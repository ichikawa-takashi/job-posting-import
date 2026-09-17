<?php
/**
 * 求人票 一覧ページ（社内用・ログイン必須）
 *
 *   /list.php
 *
 * ※ 求職者に渡すページではありません。成約フィーも表示されます。
 * ※ 管理画面と同じID・パスワードでログインします。
 *    管理画面にログイン済みなら、そのまま開けます（セッションを共有）。
 * ※ 求人票（job.php）からこのページへのリンクは一切ありません。
 * ※ 検索エンジンには載りません（noindex）。
 */

require_once __DIR__ . '/_base.php';
require_once __DIR__ . '/admin/config.php';
require_once __DIR__ . '/admin/lib.php';

if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ------------------------------------------------------------
   ログイン
   ------------------------------------------------------------ */
$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['login'])) {
    $r = login($_POST['user'] ?? '', $_POST['pass'] ?? '');
    if ($r === 'ok')         { header('Location: ' . u('/list.php')); exit; }
    elseif ($r === 'locked') { $err = '入力を5回間違えました。60秒お待ちください。'; }
    else                     { $err = 'IDまたはパスワードが違います。'; }
}
if (isset($_GET['logout'])) { logout(); header('Location: ' . u('/list.php')); exit; }

if (!is_logged_in()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="referrer" content="no-referrer">
<title>求人票 一覧</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Shippori+Mincho:wght@500;600&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(u('/theme.css')) ?>?v=21">
<link rel="stylesheet" href="<?= e(u('/admin/admin.css')) ?>?v=21">
<script src="<?= e(u('/pw-toggle.js')) ?>?v=21" defer></script>
</head>
<body>
<div id="gate">
  <div class="box">
    <p class="mark">求人票 一覧</p>
    <p class="sub">社内用のページです。管理画面と同じIDとパスワードでログインしてください。</p>
    <form method="post" autocomplete="off">
      <div class="f"><label for="u">ID</label><input type="text" id="u" name="user" autocomplete="username" autofocus></div>
      <div class="f"><label for="p">パスワード</label><input type="password" id="p" name="pass" autocomplete="current-password"></div>
      <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
      <button class="btn btn-primary" type="submit" name="login" value="1">ログイン</button>
    </form>
  </div>
</div>
</body>
</html>
    <?php
    exit;
}

// ログイン後のページはキャッシュに残さない（共有PC対策）
header('Cache-Control: no-store, no-cache, must-revalidate');

/**
 * 一覧に出す求人を集める
 * ------------------------------------------------------------
 * 編集者 … 公開中・限定公開・非公開のすべて
 * 閲覧者 … 公開中だけ
 *          （限定公開の求人票は、直接リンクを渡せば開ける）
 */
function published_jobs(): array {
    $role = current_role();
    $dir  = rtrim(JOBS_DIR, '/');
    $out  = [];
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        $j = json_decode(file_get_contents($f), true);
        if (!is_array($j) || empty($j['id'])) continue;
        $j['status'] = normalize_status($j['status'] ?? 'draft');
        if (!status_listed_for($j['status'], $role)) continue;
        $out[] = $j;
    }
    $order = CATEGORY_ORDER;
    $rank  = ['published' => 0, 'draft' => 1, 'private' => 2];
    usort($out, function ($a, $b) use ($order, $rank) {
        $ra = array_search($a['category'] ?? '', $order, true);
        $rb = array_search($b['category'] ?? '', $order, true);
        $ra = ($ra === false) ? count($order) : $ra;
        $rb = ($rb === false) ? count($order) : $rb;
        if ($ra !== $rb) return $ra - $rb;
        // 同じカテゴリの中では、公開中 → 限定公開 → 非公開 の順に並べる
        $sa = $rank[$a['status']] ?? 1;
        $sb = $rank[$b['status']] ?? 1;
        if ($sa !== $sb) return $sa - $sb;
        return strcmp($a['company'] ?? '', $b['company'] ?? '');
    });
    return $out;
}

/**
 * 求人票の絶対URL（そのまま貼って使える形）
 * 例：https://ichi-web-home.com/recruit-file/public/job/mynavi-works/
 */
function abs_job_url(string $id): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
           || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    return ($https ? 'https://' : 'http://') . $host . job_url($id);
}

/**
 * 成約フィーのバッジを組み立てる（右上に出す3段構成）
 *
 *   固定額     …            50万
 *   パーセント  …            30%
 *   期間限定   … 8月末まで／100万／通常80万
 *
 * 未設定なら null を返す（＝バッジを出さない）
 */
function fee_parts(array $job): ?array {
    $f = $job['fee'] ?? null;
    if (!is_array($f)) return null;
    $type   = trim((string)($f['type']   ?? ''));
    $value  = trim((string)($f['value']  ?? ''));
    $normal = trim((string)($f['normal'] ?? ''));
    $until  = trim((string)($f['until']  ?? ''));
    if ($type === '' || $value === '') return null;

    if ($type === 'campaign') {
        return [
            'top'  => ($until !== '' ? $until : '期間限定'),
            'main' => $value,
            'sub'  => ($normal !== '' ? '通常 ' . $normal : ''),
            'up'   => true,
        ];
    }
    return ['top' => '', 'main' => $value, 'sub' => '', 'up' => false];
}

/**
 * エージェント確認事項のマークダウンをHTMLにする
 * ------------------------------------------------------------
 * 外部ライブラリは使わず、必要な記法だけを扱います。
 *
 *   # 〜 ###    見出し
 *   - 〜 / * 〜  箇条書き
 *   1. 〜        番号付き
 *   **太字**
 *   `コード`
 *   > 引用
 *   ---         区切り線
 *   [文字](URL) リンク（http/https のみ）
 *
 * ★ 先にすべてエスケープしてから記法を処理します。
 *   入力にHTMLが混ざっていても、タグとしては解釈されません。
 */
function md_to_html(string $md): string
{
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $md = e($md);                       // ここで全部無害化する

    $out   = [];
    $list  = null;                      // 'ul' / 'ol' / null
    $quote = false;

    $inline = function (string $s): string {
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
        $s = preg_replace('/`(.+?)`/u', '<code>$1</code>', $s);
        // リンクは http/https だけ許可する
        $s = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u',
            function ($m) {
                return '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">'
                     . $m[1] . '</a>';
            },
            $s);
        return $s;
    };

    $closeList = function () use (&$out, &$list) {
        if ($list !== null) { $out[] = '</' . $list . '>'; $list = null; }
    };
    $closeQuote = function () use (&$out, &$quote) {
        if ($quote) { $out[] = '</blockquote>'; $quote = false; }
    };

    foreach (explode("\n", $md) as $line) {
        $t = rtrim($line);

        if (trim($t) === '') { $closeList(); $closeQuote(); continue; }

        if (preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $t)) {
            $closeList(); $closeQuote(); $out[] = '<hr>'; continue;
        }
        if (preg_match('/^(#{1,4})\s+(.*)$/u', $t, $m)) {
            $closeList(); $closeQuote();
            $lv = min(6, strlen($m[1]) + 2);     // # → h3 から始める
            $out[] = '<h' . $lv . '>' . $inline($m[2]) . '</h' . $lv . '>';
            continue;
        }
        if (preg_match('/^\s*&gt;\s?(.*)$/u', $t, $m)) {
            $closeList();
            if (!$quote) { $out[] = '<blockquote>'; $quote = true; }
            $out[] = '<p>' . $inline($m[1]) . '</p>';
            continue;
        }
        if (preg_match('/^\s*[-*]\s+(.*)$/u', $t, $m)) {
            $closeQuote();
            if ($list !== 'ul') { $closeList(); $out[] = '<ul>'; $list = 'ul'; }
            $out[] = '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^\s*\d+\.\s+(.*)$/u', $t, $m)) {
            $closeQuote();
            if ($list !== 'ol') { $closeList(); $out[] = '<ol>'; $list = 'ol'; }
            $out[] = '<li>' . $inline($m[1]) . '</li>';
            continue;
        }
        $closeList(); $closeQuote();
        $out[] = '<p>' . $inline($t) . '</p>';
    }
    $closeList(); $closeQuote();
    return implode("\n", $out);
}

/** サマリーからラベルを含む項目を拾う */
function stat_of(array $job, array $keywords): string {
    foreach (($job['stats'] ?? []) as $s) {
        foreach ($keywords as $k) {
            if (isset($s['label']) && strpos($s['label'], $k) !== false) {
                return (string)($s['value'] ?? '');
            }
        }
    }
    return '';
}

$jobs = published_jobs();

$cats = [];
foreach ($jobs as $j) {
    $c = $j['category'] ?? 'その他';
    $cats[$c][] = $j;
}

// 拠点の絞り込みボタンは、実際に登録されている拠点から作る
// （あとで「東海」などが増えても、ここは直さなくてよい）
$REGION_ORDER = ['関東', '関西'];
$regions = [];
foreach ($jobs as $j) {
    foreach ((array)($j['regions'] ?? []) as $r) {
        $r = trim((string)$r);
        if ($r !== '' && !in_array($r, $regions, true)) $regions[] = $r;
    }
}
usort($regions, function ($a, $b) use ($REGION_ORDER) {
    $ia = array_search($a, $REGION_ORDER, true);
    $ib = array_search($b, $REGION_ORDER, true);
    $ia = ($ia === false) ? 99 : $ia;
    $ib = ($ib === false) ? 99 : $ib;
    return ($ia !== $ib) ? $ia - $ib : strcmp($a, $b);
});
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="referrer" content="no-referrer">
<title>求人票 一覧</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Shippori+Mincho:wght@500;600&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(u('/theme.css')) ?>?v=21">
<style>
.top { background: var(--bg); padding: 54px 0 46px; }
.top h1 {
  font-family: var(--serif); font-weight: 600; color: var(--navy);
  font-size: clamp(24px, 4.4vw, 32px); letter-spacing: .05em; line-height: 1.45;
  margin: 10px 0 0; padding-left: 20px; border-left: 5px solid var(--main-deep);
}
.top .sub { margin: 20px 0 0; color: var(--text-sub); font-size: 18px; line-height: 1.9; max-width: 40em; }

/* 検索 */
.finder {
  display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
  margin-top: 30px; padding: 18px 20px; background: var(--white);
  border-top: 3px solid var(--navy);
}
.finder input[type=search] {
  flex: 1 1 260px; min-width: 200px; font-family: var(--sans); font-size: 16px;
  color: var(--text); border: 1px solid var(--rule-dark); border-radius: 0;
  padding: 11px 14px; background: var(--white);
}
.finder input[type=search]:focus { outline: none; border-color: var(--navy); box-shadow: inset 0 0 0 1px var(--navy); }
.finder .filters { display: flex; flex-wrap: wrap; gap: 8px; }
.finder button {
  font-family: var(--sans); font-size: 16px; font-weight: 700; cursor: pointer;
  padding: 9px 16px; border: 1px solid var(--rule-dark); background: var(--white); color: var(--text-sub);
}
.finder button:hover { border-color: var(--navy); }
.finder button.on { background: var(--navy); border-color: var(--navy); color: #fff; }
.finder .count { font-size: 14px; color: var(--muted); margin-left: auto; white-space: nowrap; }

/* 絞り込みを職種・拠点の2行に分ける */
.finder { align-items: flex-start; }
.filter-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; flex: 1 1 100%; }
.filter-label {
  font-size: 14px; font-weight: 700; letter-spacing: .06em; color: var(--navy);
  min-width: 3em; white-space: nowrap;
}
.finder input[type=search] { flex: 1 1 100%; }

/* 行に出す拠点のしるし */
.job-row .regions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.job-row .regions .rg {
  font-size: 14px; font-weight: 700; line-height: 1.5; letter-spacing: .04em;
  color: var(--navy); background: var(--bg-section); padding: 2px 10px;
}

/* エージェント確認事項のボタン（行の右下） */
.note-btn {
  position: absolute; right: 0; bottom: 14px; z-index: 3;
  font-family: var(--sans); font-size: 14px; font-weight: 700; line-height: 1.5;
  color: var(--navy); background: var(--white);
  border: 1px solid var(--navy); border-radius: 0;
  padding: 7px 14px; cursor: pointer; white-space: nowrap;
}
.note-btn::before { content: "≡"; margin-right: 7px; font-size: 15px; }
.note-btn:hover { background: var(--navy); color: #fff; }
.job-row.has-note a { padding-bottom: 62px; }
@media (max-width: 1000px) {
  .note-btn { position: static; margin: 0 0 20px 18px; }
  .job-row.has-note a { padding-bottom: 22px; }
}

/* モーダル */
.note-modal { position: fixed; inset: 0; z-index: 200; display: flex;
  align-items: center; justify-content: center; padding: 24px; }
.note-modal[hidden] { display: none; }
.note-back { position: absolute; inset: 0; background: rgba(39,49,63,.55); }
.note-box {
  position: relative; z-index: 1; background: var(--white);
  width: min(760px, 100%); max-height: min(80vh, 760px);
  display: flex; flex-direction: column; border-top: 5px solid var(--navy);
}
.note-head { position: relative; padding: 22px 60px 18px 28px; border-bottom: 1px solid var(--rule); }
.note-eyebrow { margin: 0; font-size: 14px; font-weight: 700; letter-spacing: .1em; color: var(--main-ink); }
.note-head h2 { margin: 6px 0 0; font-family: var(--serif); font-size: 24px; line-height: 1.5; color: var(--navy); }
.note-x {
  position: absolute; top: 14px; right: 16px; width: 36px; height: 36px;
  font-size: 24px; line-height: 1; color: var(--text-sub);
  background: transparent; border: 0; cursor: pointer;
}
.note-x:hover { color: var(--navy); }
.note-body { padding: 22px 28px 26px; overflow-y: auto; font-size: 16px; line-height: 1.95; }
.note-body h3, .note-body h4, .note-body h5, .note-body h6 {
  font-family: var(--serif); color: var(--navy); line-height: 1.5;
  margin: 24px 0 8px; padding-bottom: 6px; border-bottom: 1px solid var(--rule);
}
.note-body h3 { font-size: 20px; }
.note-body h4, .note-body h5, .note-body h6 { font-size: 18px; border-bottom: 0; padding-bottom: 0; }
.note-body > :first-child { margin-top: 0; }
.note-body p { margin: 0 0 12px; }
.note-body ul, .note-body ol { margin: 0 0 14px; padding-left: 22px; }
.note-body li { margin-bottom: 5px; }
.note-body strong { color: var(--main-ink); }
.note-body code { background: var(--bg-section); padding: 1px 6px; font-size: 15px; }
.note-body blockquote {
  margin: 0 0 14px; padding: 10px 16px;
  background: var(--bg-soft); border-left: 3px solid var(--main-deep);
}
.note-body blockquote p:last-child { margin-bottom: 0; }
.note-body hr { border: 0; border-top: 1px solid var(--rule); margin: 20px 0; }
.note-body a { color: var(--main-ink); font-weight: 700; }
.note-foot {
  display: flex; align-items: center; gap: 16px;
  padding: 16px 28px; border-top: 1px solid var(--rule); background: var(--bg);
}
.note-foot .btn { margin-left: auto; }
@media (max-width: 700px) {
  .note-modal { padding: 0; align-items: stretch; }
  .note-box { max-height: 100%; width: 100%; }
  .note-head { padding: 18px 54px 14px 18px; }
  .note-body { padding: 18px; }
  .note-foot { padding: 14px 18px; }
}

/* 見出し */
.cat-head {
  display: flex; flex-wrap: wrap; align-items: baseline; gap: 16px;
  padding-bottom: 12px; border-bottom: 2px solid var(--navy);
}
.cat-head .badge {
  font-family: var(--serif); font-size: 14px; letter-spacing: .1em;
  color: #fff; background: var(--navy); padding: 6px 16px;
}
.cat-head h2 { font-family: var(--serif); font-size: 24px; line-height: 1.5; color: var(--navy); margin: 0; letter-spacing: .04em; }
.cat-head .n { font-size: 14px; color: var(--muted); }

/* 一覧 */
.job-list { list-style: none; margin: 0; padding: 0; }
.job-row { border-bottom: 1px solid var(--rule); }
.job-row a {
  display: grid; grid-template-columns: minmax(0,1fr) 250px 130px;
  gap: 0 30px; align-items: center;
  padding: 24px 0 24px 18px; text-decoration: none; color: inherit;
  border-left: 4px solid transparent; transition: border-color .15s, background .15s;
}
.job-row a:hover { border-left-color: var(--main-deep); background: rgba(217,140,155,.07); }
.job-row .co { font-family: var(--serif); font-size: 20px; color: var(--navy); line-height: 1.55; }
.job-row .hl { font-size: 16px; color: var(--main-ink); font-weight: 600; margin-top: 6px; line-height: 1.7; }
.job-row .tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.job-row .meta { font-size: 16px; line-height: 1.8; color: var(--text); }
.job-row .meta dt { font-size: 14px; color: var(--muted); letter-spacing: .02em; display: block; }
.job-row .meta dd { margin: 0 0 10px; display: block; font-weight: 700; }
.job-row .meta dd:last-child { margin-bottom: 0; }
.job-row .go { font-size: 16px; font-weight: 700; color: var(--accent-ink); text-align: right; white-space: nowrap; }

@media (max-width: 1000px) {
  .job-row a { grid-template-columns: 1fr; gap: 14px 0; padding: 22px 0 22px 16px; }
  .job-row .meta { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 0 20px; }
  .job-row .go { text-align: left; }
}

.empty { padding: 60px 0; text-align: center; color: var(--muted); }
.hidden { display: none !important; }

/* 公開状態のしるし（編集者にだけ、公開中でない行に出る） */
.job-row .co .st {
  display: inline-block; vertical-align: 3px; margin-right: 10px;
  font-family: var(--sans); font-size: 14px; font-weight: 700;
  line-height: 1.5; letter-spacing: .02em; padding: 2px 9px;
  color: #fff; background: var(--main-deep);
}
.job-row .co .st.st-private { background: #3F3B3C; }
.job-row.st-private .pick { background: #F2F0F0; }
.job-row.st-private a .co,
.job-row.st-private a .hl { color: var(--muted); }

/* チェックボックス列 */
.job-row { display: grid; grid-template-columns: 46px minmax(0,1fr); align-items: stretch; }
.job-row .pick {
  display: flex; align-items: center; justify-content: center;
  border-right: 1px solid var(--rule); background: var(--bg);
}
.job-row .pick input { width: 19px; height: 19px; cursor: pointer; accent-color: var(--main-deep); margin: 0; }
.job-row.picked .pick { background: var(--bg-section); }
.job-row.picked a { background: rgba(217,140,155,.09); border-left-color: var(--main-deep); }
.cat-head .pick-all {
  margin-left: auto; display: flex; align-items: center; gap: 7px;
  font-size: 14px; color: var(--text-sub); cursor: pointer; white-space: nowrap;
}
.cat-head .pick-all input { width: 17px; height: 17px; accent-color: var(--main-deep); margin: 0; cursor: pointer; }

/* --------------------------------------------------------
   成約フィー：行の右上に貼り付くバッジ
   ※ クラス名は fee- で始める。ページ上部の .top / .sub と
      名前がぶつかると、そちらの背景や余白を拾ってしまうため。
   -------------------------------------------------------- */
.job-row { position: relative; }
.fee-badge {
  position: absolute; top: 0; right: 0; z-index: 2;
  display: block; width: auto; min-width: 86px; max-width: 190px;
  padding: 6px 13px 7px;
  background: var(--accent-fill); color: #fff;
  text-align: right; pointer-events: none;
}
.fee-badge.plain { background: var(--navy); }
.fee-badge span { display: block; background: none; padding: 0; margin: 0; }
.fee-badge .fee-until {
  font-family: var(--sans); font-size: 14px; font-weight: 400;
  line-height: 1.45; letter-spacing: .02em; color: #fff; opacity: .95;
  white-space: nowrap;
}
.fee-badge .fee-main {
  font-family: var(--sans); font-size: 20px; font-weight: 700;
  line-height: 1.35; letter-spacing: .02em; color: #fff; white-space: nowrap;
}
.fee-badge .fee-normal {
  font-family: var(--sans); font-size: 14px; font-weight: 400;
  line-height: 1.45; letter-spacing: .02em; color: #fff; opacity: .9;
  white-space: nowrap;
}
.fee-note { display: block; margin-top: 10px; font-size: 14px; color: var(--muted); line-height: 1.6; }

/* 「求人票を見る →」を行の下端へ逃がして、バッジと重ならないようにする */
.job-row.has-fee .go { align-self: end; }

@media (max-width: 1000px) {
  /* 1カラムになるので、社名側に余白を作って避ける */
  .job-row.has-fee a > div:first-child { padding-right: 130px; }
  .job-row.has-fee .go { align-self: auto; }
}
@media (max-width: 700px) {
  .fee-badge { min-width: 0; padding: 5px 10px 6px; }
  .fee-badge .fee-main { font-size: 18px; }
  .job-row.has-fee a > div:first-child { padding-right: 108px; }
}

/* コピーバー（選択中だけ下から出てくる） */
.copybar {
  position: fixed; left: 0; right: 0; bottom: 0; z-index: 80;
  background: var(--navy); color: var(--on-dark);
  transform: translateY(100%); transition: transform .18s ease-out;
}
.copybar.on { transform: translateY(0); }
.copybar .inner {
  display: flex; flex-wrap: wrap; align-items: center; gap: 12px 16px;
  max-width: 1180px; margin: 0 auto; padding: 16px 24px;
}
.copybar .n { font-size: 16px; font-weight: 700; line-height: 1.6; }
.copybar .n b { font-size: 24px; font-family: var(--serif); margin: 0 4px; }
.copybar .clear {
  font-family: var(--sans); font-size: 14px; color: var(--on-dark-sub);
  background: transparent; border: 1px solid rgba(255,255,255,.45); padding: 7px 14px; cursor: pointer;
}
.copybar .clear:hover { border-color: #fff; color: #fff; }
.copybar .copy {
  margin-left: auto; font-family: var(--sans); font-size: 16px; font-weight: 700;
  color: var(--navy); background: #fff; border: 0; padding: 12px 24px; cursor: pointer; line-height: 1.5;
}
.copybar .copy:hover { background: #FFE7E2; }
.copybar .copy.done { background: #3E8A5F; color: #fff; }
body.has-copybar { padding-bottom: 84px; }

@media (max-width: 700px) {
  .job-row { grid-template-columns: 40px minmax(0,1fr); }
  .copybar .inner { padding: 13px 16px; }
  .copybar .copy { margin-left: 0; width: 100%; }
}
</style>
</head>
<body>

<header class="site-header">
  <div class="wrap inner">
    <span class="brand">求人票 一覧<small>社内用</small></span>
    <nav class="header-nav">
      <?php if (is_editor()): ?>
      <a href="<?= e(u('/admin/')) ?>">管理画面</a>
      <?php endif; ?>
      <a href="?logout=1">ログアウト</a>
    </nav>
  </div>
</header>

<section class="top">
  <div class="wrap">
    <p class="eyebrow">JOB INDEX</p>
    <h1>職種別 求人票一覧</h1>
    <p class="sub">
      <?php if (is_editor()): ?>
      公開中のほか、<strong>限定公開</strong>と<strong>非公開</strong>の求人票もここに出ています。社名をクリックすると別タブで開きます。<br>
      チェックを入れると、社名とURLをまとめてコピーできます（<strong>非公開の求人はコピーの対象外です</strong>）。<br>
      <?php else: ?>
      公開中の求人票をまとめています。社名をクリックすると、求人票が別タブで開きます。<br>
      チェックを入れると、社名とURLをまとめてコピーできます。<br>
      <?php endif; ?>
      <strong>このページは社内用です。求職者には各求人票のURLを個別にお渡しください。</strong></p>

    <div class="finder">
      <input type="search" id="q" placeholder="社名・職種・キーワードで絞り込む" autocomplete="off">
      <span class="count" id="count"><?= count($jobs) ?>件</span>
      <div class="filter-row">
        <span class="filter-label">職種</span>
        <div class="filters" id="filters">
          <button type="button" class="on" data-cat="">すべて</button>
          <?php foreach (array_keys($cats) as $c): ?>
          <button type="button" data-cat="<?= e($c) ?>"><?= e($c) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php if ($regions): ?>
      <div class="filter-row">
        <span class="filter-label">拠点</span>
        <div class="filters" id="regionFilters">
          <button type="button" class="on" data-region="">すべて</button>
          <?php foreach ($regions as $r): ?>
          <button type="button" data-region="<?= e($r) ?>"><?= e($r) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if (!$jobs): ?>
<section class="block"><div class="wrap">
  <p class="empty">
    <?php if (is_editor()): ?>
    まだ求人が登録されていません。<br>管理画面から求人票を取り込んでください。
    <?php else: ?>
    公開中の求人がありません。<br>公開されると、ここに表示されます。
    <?php endif; ?>
  </p>
</div></section>
<?php else: ?>

<?php $i = 0; foreach ($cats as $cat => $list): $i++; ?>
<section class="block <?= $i % 2 === 0 ? 'tinted' : '' ?>" data-cat-section="<?= e($cat) ?>">
  <div class="wrap">
    <div class="cat-head">
      <span class="badge"><?= e($cat) ?></span>
      <h2><?= e($cat) ?>の求人</h2>
      <span class="n"><?= count($list) ?>件</span>
      <label class="pick-all"><input type="checkbox" data-all="<?= e($cat) ?>">このカテゴリをすべて選ぶ</label>
    </div>
    <ul class="job-list">
      <?php foreach ($list as $j):
        $fee = fee_parts($j);
        $search = mb_strtolower(implode(' ', [
            $j['company'] ?? '', $j['jobName'] ?? '', $j['headline'] ?? '',
            $j['category'] ?? '', implode(' ', $j['badges'] ?? []),
            stat_of($j, ['勤務地']),
        ]), 'UTF-8');
      ?>
      <li class="job-row <?= $fee ? 'has-fee' : '' ?> <?= trim((string)($j['agentNotes'] ?? '')) !== '' ? 'has-note' : '' ?> st-<?= e($j['status']) ?>" data-cat="<?= e($cat) ?>" data-search="<?= e($search) ?>"
          data-company="<?= e($j['company'] ?? '') ?>"
          data-status="<?= e($j['status']) ?>"
          data-region="<?= e(implode('／', (array)($j['regions'] ?? []))) ?>"
          data-url="<?= e(abs_job_url($j['id'])) ?>">
        <?php if ($fee): ?>
        <div class="fee-badge <?= $fee['up'] ? '' : 'plain' ?>">
          <?php if ($fee['top'] !== ''): ?><span class="fee-until"><?= e($fee['top']) ?></span><?php endif; ?>
          <span class="fee-main"><?= e($fee['main']) ?></span>
          <?php if ($fee['sub'] !== ''): ?><span class="fee-normal"><?= e($fee['sub']) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="pick">
          <?php if ($j['status'] === 'private'): ?>
          <input type="checkbox" disabled
                 title="非公開の求人はコピーできません"
                 aria-label="<?= e($j['company'] ?? '') ?>　非公開のためコピーできません">
          <?php else: ?>
          <input type="checkbox" aria-label="<?= e($j['company'] ?? '') ?>　コピー対象にする">
          <?php endif; ?>
        </div>
        <a href="<?= e(job_url($j['id'])) ?>" target="_blank" rel="noopener noreferrer">
          <div>
            <div class="co">
              <?php if ($j['status'] !== 'published'): ?>
              <span class="st st-<?= e($j['status']) ?>"><?= e(status_label($j['status'])) ?></span>
              <?php endif; ?>
              <?= e($j['company'] ?? '') ?>
            </div>
            <div class="hl"><?= e($j['headline'] ?? ($j['jobName'] ?? '')) ?></div>
            <?php if (!empty($j['badges'])): ?>
            <div class="tags">
              <?php foreach (array_slice($j['badges'], 0, 3) as $k => $b): ?>
              <span class="tag <?= $k === 0 ? 'tag-accent' : '' ?>"><?= e($b) ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($fee && trim((string)($j['fee']['note'] ?? '')) !== ''): ?>
            <div class="fee-note"><?= e($j['fee']['note']) ?></div>
            <?php endif; ?>
            <?php if (!empty($j['regions'])): ?>
            <div class="regions">
              <?php foreach ((array)$j['regions'] as $r): ?>
              <span class="rg"><?= e($r) ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <dl class="meta">
            <?php foreach ([['月給', ['月給', '給与']], ['想定年収', ['年収']], ['勤務地', ['勤務地']]] as [$lbl, $kw]):
              $v = stat_of($j, $kw); if ($v === '') continue; ?>
            <dt><?= e($lbl) ?></dt><dd><?= e($v) ?></dd>
            <?php endforeach; ?>
          </dl>
          <div class="go">求人票を見る →</div>
        </a>
        <?php if (trim((string)($j['agentNotes'] ?? '')) !== ''): ?>
        <?php /* 行全体がリンクなので、ボタンは <a> の外に置く */ ?>
        <button type="button" class="note-btn" data-note-for="<?= e($j['id']) ?>">
          エージェント確認事項
        </button>
        <div class="note-src" id="note-<?= e($j['id']) ?>" hidden><?= md_to_html($j['agentNotes']) ?></div>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endforeach; ?>

<?php endif; ?>

<!-- エージェント確認事項のモーダル -->
<div class="note-modal" id="noteModal" hidden>
  <div class="note-back" data-close-note></div>
  <div class="note-box" role="dialog" aria-modal="true" aria-labelledby="noteTitle">
    <div class="note-head">
      <p class="note-eyebrow">エージェント確認事項</p>
      <h2 id="noteTitle"></h2>
      <button type="button" class="note-x" data-close-note aria-label="閉じる">×</button>
    </div>
    <div class="note-body" id="noteBody"></div>
    <div class="note-foot">
      <span class="muted small">この内容は求職者に渡す求人票には表示されません。</span>
      <button type="button" class="btn btn-primary btn-sm" data-close-note>閉じる</button>
    </div>
  </div>
</div>

<div class="copybar" id="copybar">
  <div class="inner">
    <span class="n"><b id="picked">0</b>件を選択中</span>
    <button type="button" class="clear" id="clear">選択を解除</button>
    <button type="button" class="copy" id="copy">リンクをコピー</button>
  </div>
</div>

<footer class="site-footer">
  <div class="wrap">
    <p>※このページは社内確認用です。求職者へは各求人票のURLを個別にお渡しください。</p>
    <p>※各求人票に記載されている労働条件等の情報は、労働契約締結時の労働条件と異なる場合があります。</p>
  </div>
</footer>

<script>
(function () {
  var q = document.getElementById('q');
  var count = document.getElementById('count');
  var rows = Array.prototype.slice.call(document.querySelectorAll('.job-row'));
  var sections = Array.prototype.slice.call(document.querySelectorAll('[data-cat-section]'));
  var buttons = Array.prototype.slice.call(document.querySelectorAll('#filters button'));
  var regionBtns = Array.prototype.slice.call(document.querySelectorAll('#regionFilters button'));
  var cat = '';
  var region = '';

  function apply() {
    var kw = (q.value || '').trim().toLowerCase();
    var shown = 0;
    rows.forEach(function (r) {
      var okCat = !cat || r.dataset.cat === cat;
      // 拠点は複数持てるので、区切って含まれるかを見る
      var rg = (r.dataset.region || '').split('／');
      var okRegion = !region || rg.indexOf(region) >= 0;
      var okKw = !kw || r.dataset.search.indexOf(kw) >= 0;
      var ok = okCat && okRegion && okKw;
      r.classList.toggle('hidden', !ok);
      if (ok) shown++;
    });
    // 中身が全部消えたセクションは見出しごと隠す
    sections.forEach(function (s) {
      var any = s.querySelectorAll('.job-row:not(.hidden)').length > 0;
      s.classList.toggle('hidden', !any);
    });
    count.textContent = shown + '件';
  }

  q.addEventListener('input', apply);
  buttons.forEach(function (b) {
    b.addEventListener('click', function () {
      buttons.forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      cat = b.dataset.cat || '';
      apply();
    });
  });
  regionBtns.forEach(function (b) {
    b.addEventListener('click', function () {
      regionBtns.forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      region = b.dataset.region || '';
      apply();
    });
  });

  /* --------------------------------------------------------
     エージェント確認事項のモーダル
     -------------------------------------------------------- */
  var modal = document.getElementById('noteModal');
  if (modal) {
    var noteBody  = document.getElementById('noteBody');
    var noteTitle = document.getElementById('noteTitle');
    var lastBtn   = null;

    function openNote(btn) {
      var src = document.getElementById('note-' + btn.dataset.noteFor);
      if (!src) return;
      noteBody.innerHTML = src.innerHTML;
      noteTitle.textContent = btn.closest('.job-row').dataset.company || '';
      noteBody.scrollTop = 0;
      modal.hidden = false;
      document.body.style.overflow = 'hidden';
      lastBtn = btn;
      var x = modal.querySelector('.note-x');
      if (x) x.focus();
    }

    function closeNote() {
      modal.hidden = true;
      document.body.style.overflow = '';
      if (lastBtn) { lastBtn.focus(); lastBtn = null; }
    }

    document.querySelectorAll('.note-btn').forEach(function (b) {
      b.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        openNote(b);
      });
    });
    modal.querySelectorAll('[data-close-note]').forEach(function (el) {
      el.addEventListener('click', closeNote);
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && !modal.hidden) closeNote();
    });
  }

  /* --------------------------------------------------------
     チェックした求人のリンクをまとめてコピーする
     -------------------------------------------------------- */
  var bar     = document.getElementById('copybar');
  var pickedN = document.getElementById('picked');
  var copyBtn = document.getElementById('copy');
  var clrBtn  = document.getElementById('clear');
  var allBoxes = Array.prototype.slice.call(document.querySelectorAll('[data-all]'));

  function boxOf(row) { return row.querySelector('.pick input'); }
  // 非公開の求人はチェックボックスを disabled にしてある。
  // 数えるときも「すべて選ぶ」のときも、この行は対象から外す。
  function pickable(row) { var b = boxOf(row); return b && !b.disabled; }
  function chosen()   { return rows.filter(function (r) { return pickable(r) && boxOf(r).checked; }); }

  function refresh() {
    var n = chosen().length;
    pickedN.textContent = n;
    copyBtn.textContent = n > 0 ? (n + '件のリンクをコピー') : 'リンクをコピー';
    copyBtn.classList.remove('done');
    bar.classList.toggle('on', n > 0);
    document.body.classList.toggle('has-copybar', n > 0);

    // カテゴリの「すべて選ぶ」の状態を合わせる
    allBoxes.forEach(function (a) {
      var inCat = rows.filter(function (r) {
        return r.dataset.cat === a.dataset.all && !r.classList.contains('hidden') && pickable(r);
      });
      var on = inCat.filter(function (r) { return boxOf(r).checked; });
      a.checked = inCat.length > 0 && on.length === inCat.length;
      a.indeterminate = on.length > 0 && on.length < inCat.length;
    });
  }

  rows.forEach(function (r) {
    if (!pickable(r)) return;
    boxOf(r).addEventListener('change', function () {
      r.classList.toggle('picked', this.checked);
      refresh();
    });
  });

  allBoxes.forEach(function (a) {
    a.addEventListener('change', function () {
      var on = this.checked;
      rows.forEach(function (r) {
        if (r.dataset.cat !== a.dataset.all || r.classList.contains('hidden')) return;
        if (!pickable(r)) return;
        boxOf(r).checked = on;
        r.classList.toggle('picked', on);
      });
      refresh();
    });
  });

  clrBtn.addEventListener('click', function () {
    rows.forEach(function (r) {
      if (pickable(r)) boxOf(r).checked = false;
      r.classList.remove('picked');
    });
    refresh();
  });

  /* 社名とURLを1行ずつ、求人と求人の間は空行で区切る */
  function buildText() {
    return chosen().map(function (r) {
      return r.dataset.company + '\n' + r.dataset.url;
    }).join('\n\n');
  }

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
  }

  function done(n) {
    copyBtn.textContent = n + '件をコピーしました';
    copyBtn.classList.add('done');
    setTimeout(refresh, 1800);
  }

  copyBtn.addEventListener('click', function () {
    var list = chosen();
    if (!list.length) return;
    var text = buildText();

    // https でないと clipboard API が使えないブラウザがあるため、両方用意する
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () {
        done(list.length);
      }, function () {
        if (fallbackCopy(text)) done(list.length);
        else window.prompt('コピーできませんでした。下を選択してコピーしてください。', text);
      });
    } else if (fallbackCopy(text)) {
      done(list.length);
    } else {
      window.prompt('コピーできませんでした。下を選択してコピーしてください。', text);
    }
  });

  // 絞り込みで隠れた行は選択から外す
  var baseApply = apply;
  apply = function () {
    baseApply();
    rows.forEach(function (r) {
      if (r.classList.contains('hidden') && boxOf(r).checked) {
        boxOf(r).checked = false;
        r.classList.remove('picked');
      }
    });
    refresh();
  };
  q.removeEventListener('input', baseApply);
  q.addEventListener('input', apply);
  buttons.forEach(function (b) {
    b.addEventListener('click', function () { refresh(); });
  });

  refresh();
})();
</script>
</body>
</html>
