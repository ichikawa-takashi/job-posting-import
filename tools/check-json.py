#!/usr/bin/env python3
# ============================================================
#  求人票JSONの自己点検
#
#  使い方（求人票フォルダの中で）
#      python3 tools/check-json.py            … json/ の全件
#      python3 tools/check-json.py json/x.json … 指定した1件
#
#  チェック内容
#    ・必須キーが揃っているか（既存JSONと同じ22項目）
#    ・空のセクションが残っていないか
#    ・禁止語（無期雇用派遣・求人提供元 など）が混じっていないか
#    ・月給が 20〜30万円 の範囲か
#    ・想定年収 ＝ 月給×12 ＋ 賞与2ヶ月 と整合しているか
#    ・年収例が想定年収レンジから外れていないか
#    ・冒頭サマリーの月給と、募集要項の給与欄が一致しているか
#    ・各セクションの件数が目安どおりか
# ============================================================

import glob
import io
import json
import os
import re
import sys

NG_WORDS = [
    '無期雇用派遣', '登録型派遣', '派遣社員', '派遣先',
    '求人提供元', '有料職業紹介', '許可番号', '返戻金', '違約金',
]
# 「不動産事務」「建設事務」は check_wording で個別に見る
# （ページ側で自動的に言い換わるため、JSONには書かない）

# 転職者の不安になる表現。求人票には書かず、チャットで市川さんに伝える。
# （労働条件そのものの数字は対象外。ここで見るのは「印象を悪くする言い方」だけ）
NEGATIVE_WORDS = [
    '体力勝負', '体力に自信', '汗をかく', '泥臭い', '肉体労働',
    '離職率', '辞めてしまう', '向いていない', '厳しい環境', '過酷',
    'ノルマ', 'クレーム対応', '残業が増え', '繁忙期は', '覚えることが多く',
    '大変です', 'きつい', 'つらい', '我慢', '根性', '打たれ強',
]

# 「施工管理」を言い換えたときに、印象がぶつかる語
CLASH_WORDS = ['現場監督', '職人', '作業員', '力仕事']

REQUIRED = [
    'id', 'category', 'company', 'jobName', 'headline', 'badges', 'conditionLine',
    'catch', 'lead', 'stats', 'numbers', 'about', 'duties', 'showcase', 'schedule',
    'appeals', 'training', 'requirements', 'outline', 'flow', 'vision', 'companyInfo',
]

# 年収の推移は任意。根拠が見つからないときは書かない方針のため、
# 無いことをエラーにしない（中途半端に推測で埋めるほうが害が大きい）。
OPTIONAL_KEYS = ['salarySteps']

# セクション名 → (パス, 最低件数)
COUNTS = [
    ('numbers',        lambda j: j.get('numbers', []),                    4),
    ('about.points',   lambda j: j.get('about', {}).get('points', []),     4),
    ('about.flow',     lambda j: j.get('about', {}).get('flow', []),       4),
    ('duties.list',    lambda j: j.get('duties', {}).get('list', []),      4),
    ('duties.items',   lambda j: j.get('duties', {}).get('items', []),     3),
    ('schedule.items', lambda j: j.get('schedule', {}).get('items', []),   6),
    ('appeals.items',  lambda j: j.get('appeals', {}).get('items', []),    5),
    ('training.items', lambda j: j.get('training', {}).get('items', []),   5),
    ('outline',        lambda j: j.get('outline', []),                    10),
    ('flow.steps',     lambda j: j.get('flow', {}).get('steps', []),       3),
]

ok_all = True


MARKS = {'o': '  ✓ ', 'x': '  ✗ ', 'w': '  ! '}


def say(mark, label, msg=''):
    """mark: o=問題なし　x=直してください　w=目で見て確認してください（失敗にはしない）"""
    global ok_all
    if mark == 'x':
        ok_all = False
    print(MARKS[mark] + label.ljust(16) + str(msg))


# 「肉体労働ではございません」のように打ち消している場合は、
# ネガティブではなく、むしろ不安を解く良い書き方。誤検出しないよう見分ける。
NEGATIONS = [
    'ありません', 'ございません', 'ではない', 'ではなく', 'ではありま',
    'は不要', 'なし', '無し', '不問', '問いません', '求められません',
    '追われること', '追わない', '追いません', 'ことはなく', 'ことはありま',
    'ことはない', 'から離れ', 'とは無縁', 'ような働き方ではな',
    '課さない', '課されません', '課されること', '一切', 'く済み',
    '心配は', '必要はありま', 'いりません', '求めません', 'ゼロ',
]

# その語の直前にこれがあれば、他社・業界の話なので自社の欠点ではない
# 例）「建設業界の離職率9.5%と比べても、当社の定着率は97%」
CONTEXT_OK = ['業界', '全国平均', '一般的に', '世の中', '他社', '平均値は']


def negated(text, word, span=20, head=14):
    """その語が打ち消されているか、他社・業界の話かを見る。
    すべての出現がそうなっていれば True（＝指摘しない）。"""
    for m in re.finditer(re.escape(word), text):
        tail = text[m.end():m.end() + span]
        lead = text[max(0, m.start() - head):m.start()]
        if any(n in tail for n in NEGATIONS):
            continue
        if any(c in lead for c in CONTEXT_OK):
            continue
        return False          # 素のまま使われている出現がある
    return True


def num_range(text):
    """「22万〜28万円」「約308万〜392万円」から下限・上限を取り出す"""
    m = re.search(r'([\d.]+)\s*万\s*[〜~-]\s*([\d.]+)\s*万', str(text))
    return (float(m[1]), float(m[2])) if m else (None, None)


def check_wording(j, raw):
    """呼び名と、転職者の不安になる表現を見る。"""
    # 言い換えはサーバー側（terms.php）が表示のときに行う。JSONは原本どおり。
    pre = [w for w in ('不動産事務', '建設事務') if w in raw]
    if pre:
        say('x', '言い換え', '／'.join(pre) + ' が入っています。'
            'JSONは「施工管理」と書いてください（ページ側で自動的に言い換わります）')
    else:
        say('o', '言い換え', 'JSONは原本どおりの表記です')

    # 拠点（一覧ページの絞り込みに使う）
    rg = j.get('regions')
    if isinstance(rg, list) and [x for x in rg if str(x).strip()]:
        say('o', 'regions', '／'.join(str(x) for x in rg))
    elif 'category' in j:      # 部分更新では見ない
        say('x', 'regions',
            '未設定です。勤務地から ["関東"] / ["関西"] / ["関東","関西"] を入れてください'
            '（全国の求人は両方）')

    # 施工管理の求人は、転職者への呼び名を指定する
    if j.get('category') == '施工管理':
        dt = str(j.get('displayTerm', ''))
        if dt in ('construction', 'realestate'):
            say('o', 'displayTerm',
                dt + '（転職者には「' +
                ('建設事務' if dt == 'construction' else '不動産事務') + '」と表示）')
        else:
            say('x', 'displayTerm',
                '未指定です。施工管理の求人には construction か realestate を入れてください'
                + ('（いまの値: ' + dt + '）' if dt else ''))

    # 不安をあおる表現。ただし打ち消してある場合は、むしろ良い書き方なので見逃す。
    #   ○「肉体労働ではございません」「ノルマはありません」
    #   ×「体力勝負の仕事です」
    text = raw.replace('\\n', '　')
    hits = [w for w in NEGATIVE_WORDS if w in text and not negated(text, w)]
    okneg = [w for w in NEGATIVE_WORDS if w in text and negated(text, w)]
    if hits:
        say('x', 'ネガティブ表現', '／'.join(hits)
            + ' ← 求人票からは外し、気になる点はチャットで市川さんに伝えてください')
    else:
        say('o', 'ネガティブ表現',
            'なし' + ('（打ち消しての言及: ' + '／'.join(okneg) + '）' if okneg else ''))

    # 事務という呼び名と印象がぶつかる語。
    # 「職人さんと打ち合わせ」のように、周りの人を指す使い方は問題ないため、
    # 直すべきかは文章を見て判断する。ここは注意喚起だけにとどめる。
    clash = [w for w in CLASH_WORDS if w in text and not negated(text, w)]
    if clash:
        say('w', '呼び名との相性', '／'.join(clash)
            + ' ← 応募者自身が作業する印象になっていないか、文章を見て確かめてください')


def check_salary_steps(j, ylo=None, yhi=None):
    """年収の推移。
    想定年収レンジは「初年度に提示される幅」であって、上限は到達点ではない。
    ・1年目にレンジの下限をそのまま置いていないか
    ・最終段がレンジ上限で止まっていないか（＝上限を天井と誤解している）
    を見る。
    """
    ss = j.get('salarySteps')
    if not ss:
        say('o', 'salarySteps', '未記入（根拠が無いときは空欄で構いません）')
        return
    items = ss.get('items') or []
    say('o' if len(items) >= 3 else 'x', 'salarySteps',
        str(len(items)) + '段階' + ('' if len(items) >= 3 else '　← 3段階以上にしてください'))

    for it in items:
        miss = [k for k in ('stage', 'salary') if not str(it.get(k, '')).strip()]
        if miss:
            say('x', '　└ 段階', '、'.join(miss) + ' が空です')
    if not str(ss.get('note', '')).strip():
        say('x', '　└ 注記', 'モデルケースである旨の注記を入れてください')
    else:
        say('o', '　└ 注記', 'あり')

    if ylo is None or not items:
        return

    def yen(t):
        m = re.search(r'([\d,]+)\s*万', str(t))
        return float(m[1].replace(',', '')) if m else None

    first = yen(items[0].get('salary'))
    last = yen(items[-1].get('salary'))
    mid = (ylo + yhi) / 2

    if first is not None:
        if first <= ylo + 1:
            say('x', '　└ 1年目',
                format(first, '.0f') + '万　← 想定年収の下限そのままです。'
                'レンジは初年度の提示幅なので、中央（'
                + format(mid, '.0f') + '万）より少し上に置いてください')
        elif first < mid:
            say('x', '　└ 1年目',
                format(first, '.0f') + '万　← 中央 ' + format(mid, '.0f')
                + '万より下です。中央より少し上が目安です')
        else:
            say('o', '　└ 1年目',
                format(first, '.0f') + '万（中央 ' + format(mid, '.0f') + '万より上）')

    if last is not None:
        if last <= yhi + 1:
            say('x', '　└ 最終段',
                format(last, '.0f') + '万　← 想定年収の上限（' + format(yhi, '.0f')
                + '万）で止まっています。上限は到達点ではありません。'
                'Webで実際の水準を調べ直すか、根拠が無ければ salarySteps を外してください')
        else:
            say('o', '　└ 最終段', format(last, '.0f') + '万（上限を超えています）')


def check_numbers(j):
    """丸で見せる数字。label が無いと何の数字か分からない。"""
    nums = j.get('numbers') or []
    if not nums:
        return
    nolabel = [str(x.get('value', '')) for x in nums if not str(x.get('label', '')).strip()]
    say('x' if nolabel else 'o', 'numbers の label',
        '未入力: ' + '、'.join(nolabel) if nolabel else str(len(nums)) + '件すべて入力済み')
    long_ = [x['label'] for x in nums if len(str(x.get('label', ''))) > 8]
    if long_:
        say('x', '　└ 長さ', '／'.join(long_) + ' ← 丸に収まりません。8文字以内に')
    # value と text が抜けていないか（部分更新で消してしまう事故を防ぐ）
    thin = [str(x.get('label', '?')) for x in nums
            if not str(x.get('value', '')).strip() or not str(x.get('text', '')).strip()]
    if thin:
        say('x', '　└ 欠け', '／'.join(thin) + ' ← value か text が空です')


def check(path):
    print('=' * 64)
    print(os.path.basename(path))

    raw = io.open(path, encoding='utf-8').read()
    try:
        j = json.loads(raw)
    except Exception as e:
        say('x', 'JSON形式', '読み込めません: ' + str(e))
        return
    if isinstance(j, dict) and 'jobs' in j:
        j = j['jobs'][0]
    elif isinstance(j, list):
        j = j[0]

    print('     ' + str(j.get('company', '?')) + '　/　' + str(j.get('category', '?')))

    # --- 部分更新（_merge）は、書いてある項目だけを見る ---
    if j.get('_merge'):
        print('     部分更新（書いた項目だけをサーバーに反映します）')
        print()
        keys = [k for k in j if k not in ('_merge', 'id', 'company')]
        say('o' if keys else 'x', '更新する項目', '、'.join(keys) if keys else '何も書かれていません')
        say('o' if j.get('id') else 'x', 'id', str(j.get('id', '')) or 'ありません')
        hits = [w for w in NG_WORDS if w in raw]
        say('x' if hits else 'o', '禁止語', '／'.join(hits) if hits else 'なし')
        check_wording(j, raw)
        # numbers は配列なので、送った内容でまるごと置き換わる。
        # ラベルだけ足すつもりで value や text を落とすと、その場で消える。
        if 'numbers' in j:
            check_numbers(j)
        if isinstance(j.get('salarySteps'), dict):
            # 部分更新では想定年収が手元に無いので、レンジとの照合はできない。
            # 段階数と注記だけ見る。
            check_salary_steps(j)
        return

    print()

    # --- 必須キー ---
    missing = [k for k in REQUIRED if k not in j]
    if missing:
        say('x', '必須キー', '不足: ' + '、'.join(missing))
    else:
        say('o', '必須キー', str(len(REQUIRED)) + '項目すべてあり')

    # --- status を書いていないか ---
    if 'status' in j:
        say('x', 'status', 'status は書きません（サーバー側で管理します）')
    else:
        say('o', 'status', '書かれていません')

    # --- 空セクション ---
    # fee は管理画面で入れる任意項目。salarySteps は根拠が無ければ書かない項目。
    OPTIONAL = {'fee'} | set(OPTIONAL_KEYS)
    empty = [k for k, v in j.items() if not v and k not in OPTIONAL]
    if empty:
        say('x', '空セクション', '、'.join(empty))
    else:
        say('o', '空セクション', 'なし')

    # --- 禁止語 ---
    hits = [w for w in NG_WORDS if w in raw]
    if hits:
        say('x', '禁止語', '／'.join(hits))
    else:
        say('o', '禁止語', 'なし')

    # --- 「不動産事務」と先に書いてしまっていないか ---
    # 求人票ページ側（terms.php）で自動的に言い換えるので、
    # JSONには原本どおり「施工管理」と書く。
    check_wording(j, raw)

    print()

    # --- 給与 ---
    stats = {d.get('label'): d.get('value') for d in j.get('stats', [])}
    lo, hi = num_range(stats.get('月給', ''))
    if lo is None:
        say('x', '月給', 'stats の「月給」が読めません')
        return
    if 20 <= lo and hi <= 30:
        say('o', '月給', str(stats['月給']) + '（20〜30万円の範囲内）')
    else:
        say('x', '月給', str(stats['月給']) + ' ← 20〜30万円に収めてください')

    ylo, yhi = num_range(stats.get('想定年収', ''))
    if ylo is None:
        say('x', '想定年収', 'stats の「想定年収」が読めません')
    else:
        say('o', '想定年収', str(stats['想定年収']))
        for name, mo, ye in (('下限', lo, ylo), ('上限', hi, yhi)):
            calc = mo * 12 + mo * 2      # 月給×12 ＋ 賞与2ヶ月
            diff = abs(calc - ye)
            msg = ('月給' + format(mo, 'g') + '万×12＋賞与2ヶ月＝' + format(calc, '.0f')
                   + '万　記載 ' + format(ye, '.0f') + '万')
            say('o' if diff <= 5 else 'x', '　└ ' + name, msg + ('' if diff <= 5 else '　← 差 ' + format(diff, '.0f') + '万'))

        for ex in re.findall(r'年収例[：:]\s*約?([\d,]+)\s*万', raw):
            v = float(ex.replace(',', ''))
            inside = ylo - 10 <= v <= yhi + 10
            say('o' if inside else 'x', '　└ 年収例',
                format(v, '.0f') + '万' + ('' if inside else '　← 想定年収レンジ外。削除か修正を'))

    # --- 募集要項の給与欄との一致 ---
    pay_row = next((d for d in j.get('outline', []) if d.get('label') == '給与'), None)
    if not pay_row:
        say('x', '募集要項給与', '「給与」の行がありません')
    else:
        want = format(lo, 'g') + '万円〜' + format(hi, 'g') + '万円'
        if want in pay_row.get('value', ''):
            say('o', '募集要項給与', '冒頭サマリーと一致（' + want + '）')
        else:
            say('x', '募集要項給与', want + ' が見つかりません ← 冒頭サマリーと揃えてください')
        if '目安' not in pay_row.get('note', ''):
            say('x', '給与の注記', '「※月給・年収は目安です…」を note に入れてください')
        else:
            say('o', '給与の注記', 'あり')

    print()

    # --- 数字のラベル ---
    check_numbers(j)

    # --- 年収の推移（想定年収レンジとの関係を見る） ---
    check_salary_steps(j, ylo, yhi)

    # --- 件数 ---
    for name, getter, least in COUNTS:
        n = len(getter(j))
        say('o' if n >= least else 'x', name, str(n) + '件' + ('' if n >= least else '　← 目安 ' + str(least) + '件以上'))

    body = re.sub(r'\s', '', json.dumps(j, ensure_ascii=False))
    print()
    print('     情報量 ' + format(len(body), ',') + ' 文字（既存の求人票は 9,000 文字前後）')


def main():
    # アンダースコアで始まるファイルは作業用なので対象外（_server.json など）
    targets = sys.argv[1:] or sorted(
        f for f in glob.glob('json/*.json')
        if not os.path.basename(f).startswith('_'))
    if not targets:
        print('json/ に点検するJSONがありません。')
        return 0
    for p in targets:
        check(p)
    print('=' * 64)
    print('結果: ' + ('すべて問題ありません' if ok_all else '✗ の項目を直してください'))
    return 0 if ok_all else 1


if __name__ == '__main__':
    sys.exit(main())
