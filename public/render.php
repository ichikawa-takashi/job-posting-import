<?php
/**
 * 求人票のレンダリング
 * job.php から呼び出されます。渡された1件分のデータだけを描画します。
 */

if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function nl2($s) { return nl2br(e($s)); }

/** 値が「入っている」か。空欄の項目はセクションごと出しません。 */
function has($v) {
    if ($v === null) return false;
    if (is_array($v)) {
        if (!count($v)) return false;
        foreach ($v as $x) if (has($x)) return true;
        return false;
    }
    return trim((string)$v) !== '';
}
if (!function_exists('g')) {
    function g($a, $k, $d = null) { return (is_array($a) && array_key_exists($k, $a)) ? $a[$k] : $d; }
}

class JobRenderer
{
    private $job;
    private $tone = 0;

    public function __construct(array $job) { $this->job = $job; }

    /** 目次に載せるセクションを覚えておく */
    private $nav = [];

    private function sec($inner, $opt = []) {
        $cls = !empty($opt['plain']) ? 'plain' : '';
        $id  = isset($opt['id']) ? (string)$opt['id'] : '';
        // 目次に載せるものは id と見出しの両方が必要
        if ($id !== '' && has(g($opt, 'nav'))) {
            $this->nav[] = ['id' => $id, 'label' => (string)$opt['nav']];
        }
        return '<section class="block ' . $cls . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>'
             . '<div class="wrap">' . $inner . '</div></section>';
    }

    /** 目次（PCで左に固定表示。項目が2つ以下なら出さない） */
    private function toc() {
        if (count($this->nav) < 3) return '';
        $h = '<nav class="toc" aria-label="このページの目次"><p class="toc-ttl">目次</p><ol>';
        foreach ($this->nav as $n) {
            $h .= '<li><a href="#' . e($n['id']) . '">' . e($n['label']) . '</a></li>';
        }
        return $h . '</ol></nav>';
    }
    /** 番号つきの項目リスト（旧カードグリッド） */
    private function itemList(array $items) {
        $h = '<div class="items">';
        foreach ($items as $i => $it) {
            $h .= '<div class="item">'
                . '<div class="no">' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . '</div>'
                . '<div><h3>' . e(g($it, 'title')) . '</h3>'
                . (has(g($it, 'body')) ? '<p>' . e($it['body']) . '</p>' : '')
                . '</div></div>';
        }
        return $h . '</div>';
    }

    /** ノート風の補足ボックスに置くペンのアイコン */
    private function penIcon() {
        return '<svg class="pen" viewBox="0 0 24 24" width="26" height="26" '
             . 'aria-hidden="true" focusable="false">'
             // ペン先から柄へ伸びる本体
             . '<path d="M6.2 17.6 16.9 4.4c.7-.85 1.95-.98 2.8-.28.85.7.98 1.95.28 2.8'
             . 'L9.3 20.1" fill="none" stroke="currentColor" stroke-width="1.8" '
             . 'stroke-linecap="round" stroke-linejoin="round"/>'
             // 書いた跡の線
             . '<path d="M4.6 21.2h6.6" fill="none" stroke="currentColor" '
             . 'stroke-width="1.8" stroke-linecap="round"/>'
             . '</svg>';
    }

    private function title($t, $lead = '') {
        return '<h2 class="section-title">' . e($t) . '</h2>'
             . (has($lead) ? '<p class="section-lead">' . nl2($lead) . '</p>' : '');
    }

    public function render() {
        $hero = $this->hero();
        $o = [];
        $o[] = $this->numbers();
        $o[] = $this->about();
        $o[] = $this->duties();
        $o[] = $this->salarySteps();
        $o[] = $this->showcase();
        $o[] = $this->schedule();
        $o[] = $this->appeals();
        $o[] = $this->training();
        $o[] = $this->requirements();
        $o[] = $this->outline();
        $o[] = $this->flow();
        $o[] = $this->vision();
        $o[] = $this->companyInfo();
        $body = implode("\n", array_filter($o));

        // 目次は本文を作ったあとでないと項目が確定しない
        return $hero . "\n" . $this->toc()
             . "\n<div class=\"page-body\">" . $body . "</div>\n"
             . $this->footer();
    }

    /* ---------- ヒーロー ---------- */
    private function hero() {
        $j = $this->job;
        $h = '<section class="hero"><div class="wrap">';
        if (has(g($j, 'badges'))) {
            $h .= '<div class="tag-row">';
            foreach ($j['badges'] as $i => $b) {
                $h .= '<span class="tag ' . ($i === 0 ? 'tag-accent' : '') . '">' . e($b) . '</span>';
            }
            $h .= '</div>';
        }
        if (has(g($j, 'conditionLine'))) $h .= '<p class="cond">' . e($j['conditionLine']) . '</p>';
        $h .= '<h1>' . nl2(g($j, 'catch') ?: g($j, 'headline') ?: g($j, 'jobName')) . '</h1>';
        if (has(g($j, 'lead'))) $h .= '<p class="lead">' . nl2($j['lead']) . '</p>';
        // サマリーもインナー幅の中に置く（全幅の帯にしない）
        if (has(g($j, 'stats'))) {
            $h .= '<dl class="stat-strip">';
            foreach ($j['stats'] as $s) {
                if (!has(g($s, 'value'))) continue;
                $h .= '<div><dt>' . e(g($s, 'label')) . '</dt><dd>' . e($s['value']) . '</dd></div>';
            }
            $h .= '</dl>';
        }
        $h .= '</div>';
        return $h . '</section>';
    }

    /* ---------- 数字 ---------- */
    private function numbers() {
        $n = g($this->job, 'numbers');
        if (!has($n)) return '';
        $h = '<div class="numbers' . (count($n) === 4 ? ' n4' : '') . '">';
        foreach ($n as $i => $x) {
            $val  = (string)g($x, 'value');
            $unit = has(g($x, 'unit')) ? (string)$x['unit'] : '';

            // 「1,600社以上」のような長い値でも丸からはみ出さないよう、
            // 文字数に応じて大きさの段階を変える
            $len = mb_strlen($val . $unit, 'UTF-8');
            $size = $len >= 7 ? ' len-xl' : ($len >= 5 ? ' len-l' : '');

            $h .= '<div class="num-card">'
                . '<div class="circle">'
                . '<span class="no">' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . '</span>'
                . '<div class="inner">'
                // 何の数字かが一目で分かるよう、丸の中に短いラベルを置く
                . (has(g($x, 'label')) ? '<span class="lbl">' . e($x['label']) . '</span>' : '')
                . '<div class="big' . $size . '">' . e($val)
                . ($unit !== '' ? '<span>' . e($unit) . '</span>' : '') . '</div>'
                . '</div>'
                . '</div>'
                . (has(g($x, 'text')) ? '<p>' . e($x['text']) . '</p>' : '')
                . '</div>';
        }
        return $this->sec($h . '</div>', ['plain' => true]);
    }

    /* ---------- サービス紹介 ---------- */
    private function about() {
        $a = g($this->job, 'about');
        if (!has($a)) return '';
        $h = $this->title(g($a, 'title') ?: 'この働き方について');
        $body = has(g($a, 'body'));
        if ($body) $h .= '<div class="card"><p style="white-space:pre-line">' . e($a['body']) . '</p>';
        if (has(g($a, 'points'))) {
            $h .= '<div class="points">';
            foreach ($a['points'] as $p) {
                $h .= '<div class="point"><strong>' . e(g($p, 'label')) . '</strong><span>' . e(g($p, 'text')) . '</span></div>';
            }
            $h .= '</div>';
        }
        if (has(g($a, 'flow'))) {
            $h .= '<div class="chips">';
            foreach ($a['flow'] as $i => $f) {
                $h .= ($i ? '<span class="arw">→</span>' : '') . '<span class="chip">' . e($f) . '</span>';
            }
            $h .= '</div>';
        }
        if ($body) $h .= '</div>';
        return $this->sec($h, ['id' => 'about', 'nav' => g($a, 'title') ?: 'この働き方について']);
    }

    /* ---------- 仕事内容 ---------- */
    private function duties() {
        $d = g($this->job, 'duties');
        if (!has($d)) return '';
        $h = $this->title('仕事内容', g($d, 'lead'));
        if (has(g($d, 'list'))) {
            $h .= '<div class="card mt-m">';
            if (has(g($d, 'listTitle'))) $h .= '<h3>' . e($d['listTitle']) . '</h3>';
            $h .= '<ul class="dot-list">';
            foreach ($d['list'] as $x) $h .= '<li>' . e($x) . '</li>';
            $h .= '</ul>';
            if (has(g($d, 'tags'))) {
                $h .= '<div class="tag-row mt-s">';
                foreach ($d['tags'] as $t) $h .= '<span class="tag tag-line">' . e($t) . '</span>';
                $h .= '</div>';
            }
            if (has(g($d, 'listNote'))) $h .= '<p class="small muted mt-s">' . e($d['listNote']) . '</p>';
            $h .= '</div>';
        }
        if (has(g($d, 'items'))) {
            if (has(g($d, 'itemsTitle'))) $h .= '<h3 class="sub-head">' . e($d['itemsTitle']) . '</h3>';
            $h .= $this->itemList($d['items']);
        }
        if (has(g($d, 'note'))) {
            $h .= '<div class="note-block">' . $this->penIcon() . '<div class="note-body">'
                . (has(g($d['note'], 'title')) ? '<h3>' . e($d['note']['title']) . '</h3>' : '')
                . '<p>' . e(g($d['note'], 'body')) . '</p></div></div>';
        }
        return $this->sec($h, ['id' => 'duties', 'nav' => '仕事内容']);
    }

    /* ---------- 年収の推移 ---------- */
    private function salarySteps() {
        $x = g($this->job, 'salarySteps');
        if (!has($x) || !has(g($x, 'items'))) return '';

        $h = $this->title(g($x, 'title') ?: '年収の上がり方');
        if (has(g($x, 'lead'))) $h .= '<p class="lead-p">' . nl2($x['lead']) . '</p>';

        $h .= '<ol class="salary-steps">';
        foreach ($x['items'] as $it) {
            // 「約420万円」のような金額なら大きく出したいが、
            // 「役職手当／時間外手当」のような文章が入ることもある。
            // 長いものは文字を小さくして、隣の説明に重ならないようにする。
            $amt = (string)g($it, 'salary');
            $w   = function_exists('mb_strwidth')
                 ? mb_strwidth($amt, 'UTF-8')          // 全角は2として数える
                 : mb_strlen($amt, 'UTF-8') * 2;
            $size = $w >= 16 ? ' len-xl' : ($w >= 12 ? ' len-l' : '');

            $h .= '<li>'
                . '<span class="stage">' . e(g($it, 'stage')) . '</span>'
                . '<span class="amount' . $size . '">' . e($amt) . '</span>'
                . (has(g($it, 'note')) ? '<span class="note">' . e($it['note']) . '</span>' : '')
                . '</li>';
        }
        $h .= '</ol>';

        if (has(g($x, 'note'))) $h .= '<p class="small muted mt-s">' . nl2($x['note']) . '</p>';
        return $this->sec($h, ['id' => 'salary', 'nav' => g($x, 'title') ?: '年収の上がり方']);
    }

    /* ---------- 実績ボックス ---------- */
    private function showcase() {
        $s = g($this->job, 'showcase');
        if (!has($s)) return '';
        $this->nav[] = ['id' => 'showcase', 'label' => (g($s, 'label') ?: '実績・プロジェクト')];
        // 電球のアイコン（currentColor で色が変わるようにする）
        $bulb = '<svg class="bulb" viewBox="0 0 24 24" width="20" height="20" '
              . 'aria-hidden="true" focusable="false">'
              . '<path d="M12 2.6a6.4 6.4 0 00-3.75 11.6c.62.45.98 1.13.98 1.85v.6h5.54v-.6'
              . 'c0-.72.36-1.4.98-1.85A6.4 6.4 0 0012 2.6z" fill="none" stroke="currentColor" '
              . 'stroke-width="1.7" stroke-linejoin="round"/>'
              . '<path d="M9.6 19.1h4.8M10.5 21.4h3" fill="none" stroke="currentColor" '
              . 'stroke-width="1.7" stroke-linecap="round"/>'
              . '<path d="M12 1v-.9M20.2 4.3l.7-.7M3.8 4.3l-.7-.7M22.6 11.9h1M.4 11.9h1" '
              . 'fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
              . '</svg>';

        return '<section class="showcase" id="showcase"><div class="wrap">'
           . '<div class="showcase-box">'
           . '<p class="lbl">' . $bulb . '<span>'
           . e(has(g($s, 'label')) ? $s['label'] : 'ここがポイント') . '</span></p>'
           . '<p class="txt">' . nl2(g($s, 'text')) . '</p>'
           . (has(g($s, 'note')) ? '<p class="note">' . e($s['note']) . '</p>' : '')
           . '</div>'
           . '</div></section>';
    }

    /* ---------- 1日のスケジュール ---------- */
    private function schedule() {
        $s = g($this->job, 'schedule');
        if (!has($s) || !has(g($s, 'items'))) return '';
        $h = $this->title('1日のスケジュール例', g($s, 'lead'));
        $h .= '<ol class="timeline">';
        foreach ($s['items'] as $it) {
            $rest = !has(g($it, 'body'));
            $h .= '<li' . ($rest ? ' class="rest"' : '') . '>'
                . '<div class="time">' . e(g($it, 'time')) . '</div>'
                . '<div class="body"><div class="t">' . e(g($it, 'title')) . '</div>'
                . ($rest ? '' : '<p>' . e($it['body']) . '</p>')
                . '</div></li>';
        }
        $h .= '</ol>';
        if (has(g($s, 'note'))) $h .= '<p class="small muted mt-m">' . e($s['note']) . '</p>';
        return $this->sec($h, ['id' => 'schedule', 'nav' => '1日のスケジュール']);
    }

    /* ---------- 魅力 ---------- */
    private function appeals() {
        $a = g($this->job, 'appeals');
        if (!has($a) || !has(g($a, 'items'))) return '';
        $h = $this->title(g($a, 'title') ?: 'この求人の魅力');
        $h .= $this->itemList($a['items']);
        return $this->sec($h, ['id' => 'appeals', 'nav' => (g($this->job, 'appeals')['title'] ?? '') ?: 'この仕事の魅力']);
    }

    /* ---------- 研修 ---------- */
    private function training() {
        $t = g($this->job, 'training');
        if (!has($t) || !has(g($t, 'items'))) return '';
        $h = $this->title(g($t, 'title') ?: '研修・育成', g($t, 'lead'));
        $h .= $this->itemList($t['items']);
        if (has(g($t, 'note'))) {
            $h .= '<div class="note-block">' . $this->penIcon() . '<div class="note-body">'
                . (has(g($t['note'], 'title')) ? '<h3>' . e($t['note']['title']) . '</h3>' : '')
                . '<p>' . e(g($t['note'], 'body')) . '</p></div></div>';
        }
        return $this->sec($h, ['id' => 'training', 'nav' => (g($this->job, 'training')['title'] ?? '') ?: '研修・育成']);
    }

    /* ---------- 求める人物像 ---------- */
    private function requirements() {
        $r = g($this->job, 'requirements');
        if (!has($r)) return '';
        $h = $this->title('どのような人を求めているか') . '<div class="req">';

        $h .= '<div class="box must"><h3>' . e(g($r, 'mustTitle') ?: '必要条件') . '</h3>';
        if (has(g($r, 'mustHeadline'))) $h .= '<p class="headline">' . e($r['mustHeadline']) . '</p>';
        if (has(g($r, 'must'))) {
            $h .= '<ul class="dot-list">';
            foreach ($r['must'] as $x) $h .= '<li>' . e($x) . '</li>';
            $h .= '</ul>';
        }
        if (has(g($r, 'tags'))) {
            $h .= '<div class="tag-row mt-s">';
            foreach ($r['tags'] as $t) $h .= '<span class="tag">' . e($t) . '</span>';
            $h .= '</div>';
        }
        if (has(g($r, 'mustNote'))) $h .= '<p class="small muted mt-s">' . nl2($r['mustNote']) . '</p>';
        $h .= '</div>';

        $h .= '<div class="box want"><h3>' . e(g($r, 'wantTitle') ?: '歓迎') . '</h3>';
        if (has(g($r, 'wantHeadline'))) $h .= '<p class="headline">' . e($r['wantHeadline']) . '</p>';
        if (has(g($r, 'want'))) {
            $h .= '<ul class="dot-list">';
            foreach ($r['want'] as $x) $h .= '<li>' . e($x) . '</li>';
            $h .= '</ul>';
        }
        if (has(g($r, 'wantNote'))) $h .= '<p class="small muted mt-s">' . nl2($r['wantNote']) . '</p>';
        $h .= '</div></div>';

        return $this->sec($h, ['id' => 'requirements', 'nav' => '求める人物像']);
    }

    /* ---------- 募集要項 ---------- */
    private function outline() {
        $o = g($this->job, 'outline');
        if (!has($o)) return '';
        $h = $this->title('募集要項') . '<div class="outline">';
        foreach ($o as $x) {
            if (!has(g($x, 'value')) && !has(g($x, 'note'))) continue;
            $h .= '<div class="row"><div class="k">' . e(g($x, 'label')) . '</div><div class="v">'
                . e(g($x, 'value'))
                . (has(g($x, 'note')) ? '<span class="note">' . e($x['note']) . '</span>' : '')
                . '</div></div>';
        }
        $h .= '</div>';
        $this->nav[] = ['id' => 'outline', 'label' => '募集要項'];
        return '<section class="block" id="outline"><div class="wrap">' . $h . '</div></section>';
    }

    /* ---------- 選考フロー ---------- */
    private function flow() {
        $f = g($this->job, 'flow');
        if (!has($f) || !has(g($f, 'steps'))) return '';
        $steps = $f['steps'];
        $last = count($steps) - 1;
        $this->nav[] = ['id' => 'entry', 'label' => '選考フロー'];
        $h = '<section class="flow-sec" id="entry"><div class="wrap">'
           . $this->title('選考情報', g($f, 'lead')) . '<ol class="steps">';
        foreach ($steps as $i => $st) {
            $h .= '<li class="step' . ($i === $last ? ' last' : '') . '">'
                . '<div class="n">' . ($i + 1) . '</div>'
                . '<div class="body"><div class="t">' . e(g($st, 'title')) . '</div>'
                . (has(g($st, 'body')) ? '<p>' . e($st['body']) . '</p>' : '')
                . '</div></li>';
        }
        $h .= '</ol>'
            . (has(g($f, 'note')) ? '<p class="small flow-note">' . e($f['note']) . '</p>' : '')
            . '</div></section>';
        return $h;
    }

    /* ---------- 理念 ---------- */
    private function vision() {
        $v = g($this->job, 'vision');
        if (!has($v)) return '';
        $h = $this->title('この求人の魅力') . '<div class="vision mt-m">';
        if (has(g($v, 'label'))) $h .= '<p class="eyebrow">' . e($v['label']) . '</p>';
        if (has(g($v, 'lines'))) $h .= '<div class="lines">' . implode('<br>', array_map('e', $v['lines'])) . '</div>';
        if (has(g($v, 'by')))    $h .= '<p class="by">' . e($v['by']) . '</p>';
        if (has(g($v, 'points'))) {
            $h .= '<ul class="dot-list">';
            foreach ($v['points'] as $p) $h .= '<li>' . e($p) . '</li>';
            $h .= '</ul>';
        }
        if (has(g($v, 'body'))) $h .= '<div class="body">' . e($v['body']) . '</div>';
        return $this->sec($h . '</div>', ['id' => 'vision', 'nav' => '理念・ビジョン']);
    }

    /* ---------- 会社情報 ---------- */
    private function companyInfo() {
        $c = g($this->job, 'companyInfo');
        if (!has($c)) return '';
        $h = $this->title('会社情報');
        if (has(g($c, 'intro'))) {
            $h .= '<div class="card"><p style="white-space:pre-line">' . e($c['intro']) . '</p></div>';
        }
        if (has(g($c, 'rows'))) {
            $h .= '<div class="outline">';
            foreach ($c['rows'] as $x) {
                if (!has(g($x, 'value'))) continue;
                $val = preg_match('#^https?://#', $x['value'])
                    ? '<a href="' . e($x['value']) . '" target="_blank" rel="noopener">' . e($x['value']) . '</a>'
                    : e($x['value']);
                $h .= '<div class="row"><div class="k">' . e(g($x, 'label')) . '</div><div class="v">' . $val . '</div></div>';
            }
            $h .= '</div>';
        }
        return $this->sec($h, ['id' => 'company', 'nav' => '会社情報']);
    }

    private function footer() {
        return '<footer class="site-footer"><div class="wrap">'
             . '<p>※本求人票に記載されている労働条件等の情報は、労働契約締結時の労働条件と異なる場合がありますので、ご相談いただけますと幸いです。</p>'
             . '<p>※本求人票には一般には公開されていない情報も含まれておりますので、第三者への提供・転送を禁止させて頂いております。</p>'
             . '<p style="margin-top:16px;opacity:.7">© ' . e(g($this->job, 'company')) . '</p>'
             . '</div></footer>';
    }
}
