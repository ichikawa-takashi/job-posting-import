/* ============================================================
   求人票 管理画面
   ============================================================ */
(function () {
'use strict';

/* ------------------------------------------------------------
   公開状態
   ------------------------------------------------------------
   published … 公開中。一覧に出て、ページも開ける
   draft     … 限定公開。ページは開けるが、一覧には管理者にしか出ない
   private   … 非公開。ページ自体が開けない

   draft という値は以前から保存しているのでそのまま使い、
   画面に出す言葉だけ「限定公開」にしています。
   ------------------------------------------------------------ */
var STATUS_LABEL = {
  published: '公開中',
  draft: '限定公開',
  private: '非公開'
};

function statusOf(j) {
  var s = j && j.status;
  return STATUS_LABEL[s] ? s : 'draft';
}

/* ------------------------------------------------------------
   入力項目の定義　※ここに1行足せばフォームに項目が増えます
   type: text / textarea / lines（1行1項目）/ rows（繰り返し）
   ------------------------------------------------------------ */
var SCHEMA = [
  { title: '基本情報', open: true, fields: [
    { p: 'id',       label: 'ID（求人票のURLに使う英数字）', type: 'text', hint: '例：mynavi-works → /job/mynavi-works/　重複しないようにしてください。' },
    { p: 'category', label: '職種カテゴリ', type: 'text', datalist: 'categories',
      hint: '一覧ページのセクション単位です。既存のカテゴリは入力欄をクリックすると候補が出ます。'
           + '新しい名前を入れれば、そのカテゴリが自動で増えます（表記ゆれに注意）。' },
    { p: 'company',  label: '会社名', type: 'text' },
    { p: 'jobName',  label: '職種名', type: 'text', hint: '例：一般事務、施工管理補助' },
    { p: 'headline', label: '一覧の見出し', type: 'text', hint: '例：一般事務／完全未経験OK・充実した研修' },
    { p: 'regions',  label: '拠点', type: 'checks',
      options: [{ v: '関東', l: '関東' }, { v: '関西', l: '関西' }],
      hint: '一覧ページの絞り込みに使います。両方に拠点がある場合は両方にチェックを入れてください。'
          + '全国展開の求人も両方です。求人票ページには出ません。' }
  ]},
  { title: 'エージェント確認事項（社内用・求人票には出ません）', open: true, fields: [
    { p: 'agentNotes', label: '', type: 'markdown',
      hint: '一覧ページにボタンが出て、押すとこの内容が開きます。求職者に渡す求人票には一切出ません。'
          + '見出しは # 、箇条書きは - 、強調は **太字** で書けます。' }
  ]},
  { title: '成約フィー（社内用・求人票には出ません）', open: true, fields: [
    { p: 'fee.type', label: '表記のタイプ', type: 'select',
      options: [
        { v: '',         l: '設定しない（一覧に出しません）' },
        { v: 'fixed',    l: '固定額　　　　例：50万' },
        { v: 'percent',  l: 'パーセント　　例：30%' },
        { v: 'campaign', l: '期間限定フィーアップ　例：8月末まで100万（通常80万）' }
      ],
      rerender: true,
      hint: '一覧ページ（list.php）にだけ表示されます。求職者に渡す求人票には一切出ません。' },

    { p: 'fee.value', label: '金額', type: 'text',
      showIfP: 'fee.type', showIf: ['fixed'],
      hint: '例：50万　→ 一覧に「50万」と表示されます。' },

    { p: 'fee.value', label: '料率', type: 'text',
      showIfP: 'fee.type', showIf: ['percent'],
      hint: '例：30%　→ 一覧に「30%」と表示されます。' },

    { p: 'fee.until',  label: '期限', type: 'text',
      showIfP: 'fee.type', showIf: ['campaign'],
      hint: '例：8月末まで' },
    { p: 'fee.value',  label: 'フィーアップ後の金額', type: 'text',
      showIfP: 'fee.type', showIf: ['campaign'],
      hint: '例：100万' },
    { p: 'fee.normal', label: '通常の金額', type: 'text',
      showIfP: 'fee.type', showIf: ['campaign'],
      hint: '例：80万　→ 一覧に「8月末まで100万（通常80万）」と表示されます。' },

    { p: 'fee.note', label: '備考', type: 'text',
      showIfP: 'fee.type', showIf: ['fixed', 'percent', 'campaign'],
      hint: '任意。例：初回のみ／2名以上の紹介で適用　など' }
  ]},
  { title: 'トップ（求人票の冒頭）', open: true, fields: [
    { p: 'badges',        label: 'タグ', type: 'lines', hint: '1行に1つ。1つ目がコーラル色になります。' },
    { p: 'conditionLine', label: '条件の一言', type: 'text', hint: '例：職種未経験OK／業種未経験OK／第二新卒歓迎' },
    { p: 'catch',         label: 'キャッチコピー', type: 'textarea', hint: '改行するとそのまま折り返されます。' },
    { p: 'lead',          label: 'リード文', type: 'textarea' },
    { p: 'stats',         label: 'サマリー（月給・年収など）', type: 'rows',
      cols: [{ k: 'label', l: '項目名' }, { k: 'value', l: '内容' }],
      hint: '4項目までが読みやすいです。' }
  ]},
  { title: '数字で見るポイント', fields: [
    { p: 'numbers', label: '', type: 'rows',
      cols: [
        { k: 'label', l: '何の数字か（例：定着率）' },
        { k: 'value', l: '数字' },
        { k: 'unit',  l: '単位' },
        { k: 'text',  l: '説明', wide: true }
      ],
      hint: '3〜4件が目安。「何の数字か」は丸の中に表示されるので、6文字前後の短い言葉にしてください。'
          + '空にすればセクションごと非表示になります。' }
  ]},
  { title: 'サービス・働き方の紹介', fields: [
    { p: 'about.title',  label: '見出し', type: 'text', hint: '例：「◯◯」とは？' },
    { p: 'about.body',   label: '本文', type: 'textarea' },
    { p: 'about.points', label: '特長（ラベル＋説明）', type: 'rows',
      cols: [{ k: 'label', l: 'ラベル' }, { k: 'text', l: '説明', wide: true }] },
    { p: 'about.flow',   label: 'ステップ表示', type: 'lines', hint: '1行1つ。矢印でつないで表示し、最後の1つが強調されます。' }
  ]},
  { title: '仕事内容', fields: [
    { p: 'duties.lead',       label: 'リード文', type: 'textarea' },
    { p: 'duties.listTitle',  label: '箇条書きの見出し', type: 'text', hint: '例：具体的なお仕事例' },
    { p: 'duties.list',       label: '箇条書き', type: 'lines' },
    { p: 'duties.tags',       label: '箇条書きの下のタグ', type: 'lines' },
    { p: 'duties.listNote',   label: '箇条書きの補足', type: 'text' },
    { p: 'duties.itemsTitle', label: 'カードの見出し', type: 'text', hint: '例：過去の就業実績例' },
    { p: 'duties.items',      label: 'カード', type: 'rows',
      cols: [{ k: 'title', l: '小見出し' }, { k: 'body', l: '本文', wide: true }] },
    { p: 'duties.note.title', label: '補足ボックスの見出し', type: 'text' },
    { p: 'duties.note.body',  label: '補足ボックスの本文', type: 'textarea' }
  ]},
  { title: '年収の推移（今後の上がり方）', fields: [
    { p: 'salarySteps.title', label: '見出し', type: 'text',
      hint: '空欄なら「年収の上がり方」になります。' },
    { p: 'salarySteps.lead',  label: 'リード文', type: 'textarea',
      hint: '例：資格を取るほど手当がつき、年収が上がっていきます。' },
    { p: 'salarySteps.items', label: '段階', type: 'rows',
      cols: [
        { k: 'stage',  l: '時期（例：入社1年目）' },
        { k: 'salary', l: '年収（例：約320万円）' },
        { k: 'note',   l: '補足（例：2級取得・資格手当）', wide: true }
      ],
      hint: '左から右へ、上がっていく順に並べます。3〜4段階が見やすいです。' },
    { p: 'salarySteps.note', label: '注記', type: 'textarea',
      hint: '例：※あくまでモデルケースです。実際の昇給は評価と資格取得状況によります。' }
  ]},
  { title: '実績・プロジェクト', fields: [
    { p: 'showcase.label', label: 'ラベル', type: 'text', hint: '例：施工実績／プロジェクト例' },
    { p: 'showcase.text',  label: '本文', type: 'textarea' },
    { p: 'showcase.note',  label: '補足', type: 'textarea' }
  ]},
  { title: '1日のスケジュール', fields: [
    { p: 'schedule.lead',  label: 'リード文', type: 'textarea' },
    { p: 'schedule.items', label: 'タイムライン', type: 'rows',
      cols: [{ k: 'time', l: '時刻' }, { k: 'title', l: '見出し' }, { k: 'body', l: '説明', wide: true }],
      hint: '説明を空にすると「休憩」のような控えめな表示になります。1件も無ければセクションごと非表示。' },
    { p: 'schedule.note',  label: '注記', type: 'text' }
  ]},
  { title: '魅力・やりがい', fields: [
    { p: 'appeals.title', label: '見出し', type: 'text', hint: '空欄なら「この求人の魅力」' },
    { p: 'appeals.items', label: 'カード', type: 'rows',
      cols: [{ k: 'title', l: '小見出し' }, { k: 'body', l: '本文', wide: true }] }
  ]},
  { title: '研修・育成', fields: [
    { p: 'training.title', label: '見出し', type: 'text' },
    { p: 'training.lead',  label: 'リード文', type: 'textarea' },
    { p: 'training.items', label: 'カード', type: 'rows',
      cols: [{ k: 'title', l: '小見出し' }, { k: 'body', l: '本文', wide: true }] },
    { p: 'training.note.title', label: '補足ボックスの見出し', type: 'text' },
    { p: 'training.note.body',  label: '補足ボックスの本文', type: 'textarea' }
  ]},
  { title: '求める人物像', fields: [
    { p: 'requirements.mustTitle',    label: '左：見出し', type: 'text', hint: '例：必要条件／必須' },
    { p: 'requirements.mustHeadline', label: '左：強調文', type: 'text' },
    { p: 'requirements.must',         label: '左：箇条書き', type: 'lines' },
    { p: 'requirements.tags',         label: '左：タグ', type: 'lines', hint: '例：最終学歴：高卒以上／職種未経験OK' },
    { p: 'requirements.mustNote',     label: '左：補足', type: 'textarea' },
    { p: 'requirements.wantTitle',    label: '右：見出し', type: 'text', hint: '例：内定の可能性が高い人／歓迎' },
    { p: 'requirements.wantHeadline', label: '右：強調文', type: 'text' },
    { p: 'requirements.want',         label: '右：箇条書き', type: 'lines' },
    { p: 'requirements.wantNote',     label: '右：補足', type: 'textarea' }
  ]},
  { title: '募集要項', fields: [
    { p: 'outline', label: '', type: 'rows',
      cols: [{ k: 'label', l: '項目名' }, { k: 'value', l: '内容（改行可）', wide: true, area: true }, { k: 'note', l: '※注記', wide: true, area: true }],
      hint: '雇用形態／給与／勤務地／勤務時間／休日休暇／福利厚生 などを揃えると比較しやすくなります。' }
  ]},
  { title: '選考フロー', fields: [
    { p: 'flow.lead',  label: 'リード文', type: 'textarea' },
    { p: 'flow.steps', label: 'ステップ', type: 'rows',
      cols: [{ k: 'title', l: '見出し' }, { k: 'body', l: '説明', wide: true }] },
    { p: 'flow.note',  label: 'ボタン下の一言', type: 'text' }
  ]},
  { title: '理念・ビジョン', fields: [
    { p: 'vision.label',  label: 'ラベル', type: 'text', hint: '例：理念・ビジョン' },
    { p: 'vision.lines',  label: '大きく見せる文（1行1つ）', type: 'lines' },
    { p: 'vision.by',     label: '署名', type: 'text' },
    { p: 'vision.points', label: '箇条ボックス', type: 'lines' },
    { p: 'vision.body',   label: '本文', type: 'textarea' }
  ]},
  { title: '会社情報', fields: [
    { p: 'companyInfo.intro', label: '会社紹介文', type: 'textarea' },
    { p: 'companyInfo.rows',  label: '会社データ', type: 'rows',
      cols: [{ k: 'label', l: '項目名' }, { k: 'value', l: '内容', wide: true }],
      hint: '「https://」で始まる値は自動でリンクになります。' }
  ]}
];

/* ------------------------------------------------------------
   ユーティリティ
   ------------------------------------------------------------ */
var $ = function (s) { return document.querySelector(s); };
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
  });
}
function get(o, path) { return path.split('.').reduce(function (a, k) { return a == null ? undefined : a[k]; }, o); }
function set(o, path, v) {
  var ks = path.split('.'), last = ks.pop(), t = o;
  ks.forEach(function (k) { if (typeof t[k] !== 'object' || t[k] === null) t[k] = {}; t = t[k]; });
  t[last] = v;
}
function filled(v) {
  if (v == null) return false;
  if (Array.isArray(v)) return v.some(filled);
  if (typeof v === 'object') return Object.keys(v).some(function (k) { return filled(v[k]); });
  return String(v).trim() !== '';
}
function toast(msg, bad) {
  var t = $('#toast');
  t.textContent = msg;
  t.className = 'toast on' + (bad ? ' bad' : '');
  clearTimeout(t._t); t._t = setTimeout(function () { t.className = 'toast'; }, 2600);
}
async function api(action, payload, method) {
  var url = 'api.php?action=' + encodeURIComponent(action);
  var opt = { method: method || 'GET', credentials: 'same-origin' };
  if (opt.method === 'POST') {
    opt.headers = { 'Content-Type': 'application/json' };
    opt.body = JSON.stringify(Object.assign({ action: action, csrf: window.CSRF }, payload || {}));
  } else if (payload) {
    Object.keys(payload).forEach(function (k) { url += '&' + k + '=' + encodeURIComponent(payload[k]); });
  }
  var r = await fetch(url, opt);
  var j = await r.json().catch(function () { return { ok: false, error: '通信に失敗しました' }; });
  if (!j.ok) throw new Error(j.error || 'エラーが発生しました');
  return j;
}

/* ------------------------------------------------------------
   状態
   ------------------------------------------------------------ */
var jobs = [];          // 一覧（軽量）
var job = null;         // 編集中の1件（全項目）
var loadedId = '';      // 読み込んだ時点のID（変更検知用）
var dirty = false;

function markDirty() {
  dirty = true;
  $('#save').textContent = '保存 ●';
}
function markClean() {
  dirty = false;
  $('#save').textContent = '保存';
}
window.addEventListener('beforeunload', function (e) {
  if (dirty) { e.preventDefault(); e.returnValue = ''; }
});

/* ------------------------------------------------------------
   サイドバー
   ------------------------------------------------------------ */
var listFilter = '';

function renderList() {
  var kw = listFilter.trim().toLowerCase();
  var shown = jobs.filter(function (j) {
    if (!kw) return true;
    return ((j.company || '') + ' ' + (j.jobName || '') + ' ' + (j.headline || '') +
            ' ' + (j.category || '') + ' ' + (j.id || '')).toLowerCase().indexOf(kw) >= 0;
  });

  var cats = [];
  shown.forEach(function (j) { if (cats.indexOf(j.category) < 0) cats.push(j.category); });

  var html = '';
  cats.forEach(function (c) {
    var inCat = shown.filter(function (j) { return j.category === c; });
    html += '<p class="cat">' + esc(c) + '<span>' + inCat.length + '</span></p><ul>';
    inCat.forEach(function (j) {
      var st = statusOf(j);
      html += '<li><button type="button" class="item st-' + st +
        (job && j.id === loadedId ? ' on' : '') + (st === 'published' ? ' pub' : '') +
        '" data-id="' + esc(j.id) + '" title="' + esc(j.company) + '　' + STATUS_LABEL[st] + '">' +
        '<span class="co">' + esc(j.company) + '</span>' +
        '<span class="sub">' + esc(j.jobName || j.headline || '—') + '</span>' +
        '<span class="dot" aria-label="' + STATUS_LABEL[st] + '"></span>' +
        '</button></li>';
    });
    html += '</ul>';
  });

  var box = $('#list');
  box.innerHTML = html || '<p class="small muted" style="padding:16px 0">' +
    (kw ? '該当する求人がありません' : '求人がありません') + '</p>';
  box.querySelectorAll('button.item').forEach(function (b) {
    b.onclick = function () { openJob(b.dataset.id); };
  });

  var cnt = $('#listCount');
  if (cnt) cnt.textContent = kw ? (shown.length + ' / ' + jobs.length) : String(jobs.length);
}

async function reloadList() {
  var r = await api('list');
  jobs = r.jobs;
  window.CATEGORY_ORDER = r.categoryOrder || [];
  renderList();
}

/* ------------------------------------------------------------
   フォーム
   ------------------------------------------------------------ */
function fieldHTML(f) {
  // showIf が指定されている項目は、条件を満たすときだけ表示する
  if (f.showIf && f.showIf.indexOf(String(get(job, f.showIfP) || '')) < 0) return '';
  var v = get(job, f.p);
  var h = '<div class="f">';
  if (f.label) h += '<label>' + esc(f.label) + '</label>';
  if (f.hint)  h += '<p class="hint">' + esc(f.hint) + '</p>';
  if (f.type === 'text') {
    if (f.datalist === 'categories') {
      var cats = [];
      jobs.forEach(function (j) { if (j.category && cats.indexOf(j.category) < 0) cats.push(j.category); });
      (window.CATEGORY_ORDER || []).forEach(function (c) { if (cats.indexOf(c) < 0) cats.push(c); });
      h += '<input type="text" list="dl-categories" data-p="' + f.p + '" value="' + esc(v || '') + '">';
      h += '<datalist id="dl-categories">' +
           cats.map(function (c) { return '<option value="' + esc(c) + '">'; }).join('') +
           '</datalist>';
    } else {
      h += '<input type="text" data-p="' + f.p + '" value="' + esc(v || '') + '">';
    }
  } else if (f.type === 'select') {
    h += '<select data-p="' + f.p + '"' + (f.rerender ? ' data-rerender="1"' : '') + '>';
    f.options.forEach(function (o) {
      h += '<option value="' + esc(o.v) + '"' + (String(v || '') === o.v ? ' selected' : '') + '>' + esc(o.l) + '</option>';
    });
    h += '</select>';
  } else if (f.type === 'textarea') {
    h += '<textarea data-p="' + f.p + '">' + esc(v || '') + '</textarea>';
  } else if (f.type === 'markdown') {
    h += '<textarea class="md" data-p="' + f.p + '" spellcheck="false" '
       + 'placeholder="## 推薦時のポイント&#10;- 〜&#10;- 〜">' + esc(v || '') + '</textarea>';
  } else if (f.type === 'checks') {
    // 複数選べる項目。値は配列で持つ
    var cur = Array.isArray(v) ? v : [];
    h += '<div class="checks">';
    f.options.forEach(function (o) {
      h += '<label><input type="checkbox" data-p="' + f.p + '" data-kind="checks" '
         + 'value="' + esc(o.v) + '"' + (cur.indexOf(o.v) >= 0 ? ' checked' : '') + '>'
         + esc(o.l) + '</label>';
    });
    h += '</div>';
  } else if (f.type === 'lines') {
    h += '<textarea data-p="' + f.p + '" data-kind="lines">' + esc((v || []).join('\n')) + '</textarea>';
  } else if (f.type === 'rows') {
    h += '<div class="rows" data-rows="' + f.p + '">';
    (v || []).forEach(function (item, i) { h += rowHTML(f, item, i); });
    h += '</div><button type="button" class="btn btn-quiet btn-sm" data-addrow="' + f.p + '" style="margin-top:10px">＋ 行を追加</button>';
  }
  return h + '</div>';
}

function rowHTML(f, item, idx) {
  var cols = f.cols.map(function (c) { return c.wide ? '2fr' : '1fr'; }).join(' ');
  var h = '<div class="row-item" data-idx="' + idx + '"><div class="cells" style="grid-template-columns:' + cols + '">';
  f.cols.forEach(function (c) {
    var val = item ? (item[c.k] || '') : '';
    h += '<div class="cell"><label>' + esc(c.l) + '</label>';
    h += (c.area || c.wide)
      ? '<textarea data-rk="' + c.k + '" style="min-height:' + (c.area ? '82px' : '58px') + '">' + esc(val) + '</textarea>'
      : '<input type="text" data-rk="' + c.k + '" value="' + esc(val) + '">';
    h += '</div>';
  });
  return h + '</div><div class="ops">' +
    '<button type="button" data-op="up" title="上へ">↑</button>' +
    '<button type="button" data-op="down" title="下へ">↓</button>' +
    '<button type="button" data-op="del" class="del" title="削除">×</button>' +
    '</div></div>';
}

function renderForm() {
  var form = $('#form');
  if (!job) { form.innerHTML = '<p class="small muted">左から求人を選んでください。</p>'; return; }

  form.innerHTML = SCHEMA.map(function (s, si) {
    var any = s.fields.some(function (f) { return filled(get(job, f.p)); });
    return '<fieldset class="' + (s.open || any ? '' : 'closed') + '" data-s="' + si + '">' +
      '<div class="head"><span class="caret">▼</span><h3>' + esc(s.title) + '</h3>' +
      '<span class="cnt">' + (any ? '入力あり' : '未入力（非表示）') + '</span></div>' +
      '<div class="body">' + s.fields.map(fieldHTML).join('') + '</div></fieldset>';
  }).join('');

  form.querySelectorAll('fieldset > .head').forEach(function (h) {
    h.onclick = function () { h.parentNode.classList.toggle('closed'); };
  });
  bind();
  refreshHeader();
}

function bind() {
  var form = $('#form');
  form.querySelectorAll('[data-p]').forEach(function (el) {
    var handler = function () {
      var p = el.dataset.p;
      if (el.dataset.kind === 'lines') {
        set(job, p, el.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean));
      } else if (el.dataset.kind === 'checks') {
        // 同じ項目のチェックボックスを全部見て、入っているものだけを配列にする
        var vals = [];
        form.querySelectorAll('[data-kind="checks"][data-p="' + p + '"]').forEach(function (c) {
          if (c.checked) vals.push(c.value);
        });
        set(job, p, vals);
      } else {
        set(job, p, el.value);
      }
      if (p === 'company' || p === 'jobName' || p === 'category' || p === 'id') refreshHeader();
      markDirty();
      // 選択で表示項目が変わる欄は、その場で描き直す
      if (el.dataset.rerender === '1') renderForm();
    };
    el.oninput = handler;
    // select とチェックボックスは change でも拾う
    if (el.tagName === 'SELECT' || el.type === 'checkbox') el.onchange = handler;
  });

  form.querySelectorAll('[data-rows]').forEach(function (box) {
    var p = box.dataset.rows;
    box.querySelectorAll('.row-item').forEach(function (item) {
      item.querySelectorAll('[data-rk]').forEach(function (el) {
        el.oninput = function () {
          var arr = get(job, p) || [];
          arr[+item.dataset.idx][el.dataset.rk] = el.value;
          set(job, p, arr); markDirty();
        };
      });
      item.querySelectorAll('[data-op]').forEach(function (b) {
        b.onclick = function () {
          var arr = get(job, p) || [], i = +item.dataset.idx;
          if (b.dataset.op === 'del') arr.splice(i, 1);
          if (b.dataset.op === 'up' && i > 0) arr.splice(i - 1, 0, arr.splice(i, 1)[0]);
          if (b.dataset.op === 'down' && i < arr.length - 1) arr.splice(i + 1, 0, arr.splice(i, 1)[0]);
          set(job, p, arr); markDirty(); renderForm();
        };
      });
    });
  });

  form.querySelectorAll('[data-addrow]').forEach(function (b) {
    b.onclick = function () {
      var p = b.dataset.addrow, arr = get(job, p) || [];
      arr.push({}); set(job, p, arr); markDirty(); renderForm();
    };
  });
}

function refreshHeader() {
  if (!job) return;
  $('#who').innerHTML = esc(job.company || '（無題）') +
    '<small>' + esc(job.category || '') + '　' + esc(job.jobName || '') + '</small>';
  var st = statusOf(job);
  var pill = $('#statusPill');
  pill.textContent = STATUS_LABEL[st];
  pill.className = 'pill st-' + st;
  $('#statusSel').value = st;

  var url = location.origin + (window.BASE || '') + '/job/' + (job.id || '') + '/';
  var link = '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(url) + '</a>';
  if (st === 'published') {
    $('#urlline').innerHTML = '公開URL：' + link +
      '　<span class="muted">（一覧ページにも出ています）</span>';
  } else if (st === 'draft') {
    $('#urlline').innerHTML = 'リンク：' + link +
      '　<span class="muted">（このURLを渡した人だけが見られます。一覧ページには出ません）</span>';
  } else {
    $('#urlline').innerHTML =
      '<span class="muted">非公開です。' + esc(url) + ' を開いてもページは表示されません。</span>';
  }
}

/* ------------------------------------------------------------
   操作
   ------------------------------------------------------------ */
async function openJob(id) {
  if (dirty && !confirm('保存していない変更があります。破棄して移動しますか？')) return;
  try {
    var r = await api('get', { id: id });
    job = r.job; loadedId = job.id; markClean();
    renderList(); renderForm();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  } catch (e) { toast(e.message, true); }
}

$('#add').onclick = function () {
  if (dirty && !confirm('保存していない変更があります。破棄しますか？')) return;
  job = {
    id: 'job-' + Date.now().toString(36),
    category: '', company: '（新しい求人）', jobName: '', headline: '',
    status: 'draft',
    badges: [],
    stats: [{ label: '月給', value: '' }, { label: '想定年収', value: '' },
            { label: '勤務地', value: '' }, { label: '年間休日', value: '' }],
    outline: [
      { label: '雇用形態', value: '' }, { label: '給与', value: '' },
      { label: '勤務地', value: '' }, { label: '勤務時間', value: '' },
      { label: '休日休暇', value: '' }, { label: '福利厚生・諸手当', value: '' }
    ]
  };
  loadedId = ''; markDirty(); renderForm();
  window.scrollTo({ top: 0, behavior: 'smooth' });
};

$('#save').onclick = async function () {
  if (!job) return;
  if (!/^[A-Za-z0-9_-]+$/.test(job.id || '')) { toast('IDは英数字・ハイフン・アンダースコアのみです', true); return; }
  try {
    await api('save', { job: job, oldId: loadedId }, 'POST');
    loadedId = job.id; markClean();
    await reloadList(); renderList(); refreshHeader();
    toast('保存しました');
  } catch (e) { toast(e.message, true); }
};

$('#statusSel').onchange = async function () {
  var next = this.value;
  if (!job) { return; }
  if (dirty) {
    toast('先に保存してください', true);
    this.value = statusOf(job);
    return;
  }
  try {
    await api('status', { id: job.id, status: next }, 'POST');
    job.status = next;
    await reloadList(); renderList(); refreshHeader();
    toast(STATUS_LABEL[next] + 'にしました');
  } catch (e) {
    toast(e.message, true);
    this.value = statusOf(job);
  }
};

$('#del').onclick = async function () {
  if (!job) return;
  if (!confirm('「' + (job.company || '') + '」を削除します。よろしいですか？')) return;
  try {
    await api('delete', { id: loadedId || job.id }, 'POST');
    job = null; loadedId = ''; markClean();
    await reloadList(); renderForm();
    $('#who').textContent = '—'; $('#statusPill').textContent = ''; $('#urlline').textContent = '';
    toast('削除しました');
  } catch (e) { toast(e.message, true); }
};

$('#dup').onclick = function () {
  if (!job) return;
  var c = JSON.parse(JSON.stringify(job));
  c.id = c.id + '-copy'; c.company = c.company + '（複製）'; c.status = 'draft';
  job = c; loadedId = ''; markDirty(); renderForm();
  toast('複製しました。保存すると確定します。');
};

$('#view').onclick = function () {
  if (!job) return;
  window.open((window.BASE || '') + '/job/' + encodeURIComponent(job.id) + '/', '_blank', 'noopener');
};

/* ------------------------------------------------------------
   JSON 取り込み
   ------------------------------------------------------------ */
var dlgImport = $('#dlgImport');
function openImport() { $('#importResult').innerHTML = ''; dlgImport.showModal(); }
$('#importBtn').onclick = openImport;
$('#navImport').onclick = function (e) { e.preventDefault(); openImport(); };

var drop = $('#drop');
['dragenter', 'dragover'].forEach(function (ev) {
  drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
});
['dragleave', 'drop'].forEach(function (ev) {
  drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
});
drop.addEventListener('drop', function (e) { readFiles(e.dataTransfer.files); });
$('#file').onchange = function () { readFiles(this.files); };

function readFiles(files) {
  var texts = [];
  var left = files.length;
  if (!left) return;
  Array.prototype.forEach.call(files, function (f) {
    var r = new FileReader();
    r.onload = function () {
      texts.push(r.result);
      if (--left === 0) {
        // 複数ファイルは配列にまとめる
        var merged = texts.map(function (t) { try { return JSON.parse(t); } catch (e) { return null; } }).filter(Boolean);
        var flat = [];
        merged.forEach(function (m) {
          if (m && m.jobs && Array.isArray(m.jobs)) flat = flat.concat(m.jobs);
          else if (Array.isArray(m)) flat = flat.concat(m);
          else flat.push(m);
        });
        $('#importText').value = JSON.stringify(flat.length === 1 ? flat[0] : flat, null, 2);
        toast(files.length + '件のファイルを読み込みました。内容を確認して「読み込む」を押してください。');
      }
    };
    r.readAsText(f, 'UTF-8');
  });
}

$('#doImport').onclick = async function () {
  var txt = $('#importText').value.trim();
  if (!txt) { toast('JSONを貼り付けるか、ファイルを選んでください', true); return; }
  var data;
  try { data = JSON.parse(txt); }
  catch (e) { $('#importResult').innerHTML = '<p class="ng">JSONの形式が正しくありません：' + esc(e.message) + '</p>'; return; }
  try {
    var r = await api('import', {
      data: data,
      overwrite: $('#overwrite').checked,
      mode: $('#importStatus').value
    }, 'POST');
    var h = '';
    if (r.added.length)   h += '<p class="ok">新規に取り込みました：' + r.added.map(esc).join('、') + '</p>';
    if (r.updated.length) h += '<p class="ok">上書きしました：' + r.updated.map(esc).join('、') + '</p>';
    if (r.skipped.length) h += '<p class="ng">スキップ：' + r.skipped.map(esc).join('、') +
      '<br><span class="muted">同じIDが既にある場合は「上書きする」にチェックを入れてください。</span></p>';
    $('#importResult').innerHTML = h || '<p class="ng">取り込めるデータがありませんでした。</p>';
    await reloadList();
    toast('取り込みが完了しました');
  } catch (e) { $('#importResult').innerHTML = '<p class="ng">' + esc(e.message) + '</p>'; }
};

/* ------------------------------------------------------------
   パスワード変更
   ------------------------------------------------------------ */
var dlgPass = $('#dlgPass');
$('#navPass').onclick = function (e) { e.preventDefault(); dlgPass.showModal(); };
$('#makeHash').onclick = async function () {
  try {
    var r = await api('passhash', { password: $('#newPass').value }, 'POST');
    var o = $('#hashOut');
    o.style.display = 'block';
    // config.php の USERS の中、選んだIDの 'pass' の行に貼り替える
    o.textContent = "'pass' => '" + r.hash + "',"
      + "        // " + $('#passUser').value;
  } catch (e) { toast(e.message, true); }
};

document.querySelectorAll('[data-close]').forEach(function (b) {
  b.onclick = function () { b.closest('dialog').close(); };
});

/* ------------------------------------------------------------
   起動
   ------------------------------------------------------------ */
var sideSearch = $('#sideSearch');
if (sideSearch) {
  sideSearch.addEventListener('input', function () {
    listFilter = sideSearch.value;
    renderList();
  });
}

reloadList().catch(function (e) { toast(e.message, true); });
})();
