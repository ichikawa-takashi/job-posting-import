<?php
/**
 * 設置状態のかんたん診断
 *
 *   https://（ドメイン）/（設置場所）/check.php
 *
 * ※原因が分かったら、このファイルはサーバーから削除してください。
 */
require __DIR__ . '/_base.php';
header('Content-Type: text/html; charset=UTF-8');

function row($label, $ok, $detail = '') {
    $mark = $ok === null ? '－' : ($ok ? '✅' : '❌');
    $color = $ok === null ? '#71717A' : ($ok ? '#3d7a56' : '#b45c5c');
    echo '<tr><td>' . htmlspecialchars($label) . '</td>'
       . '<td style="color:' . $color . ';font-weight:700">' . $mark . '</td>'
       . '<td style="font-family:monospace;font-size:14px;word-break:break-all">'
       . htmlspecialchars($detail) . '</td></tr>';
}

$base    = base_url();
$jobsDir = __DIR__ . '/data/jobs';
$rewrite = null;
if (function_exists('apache_get_modules')) {
    $rewrite = in_array('mod_rewrite', apache_get_modules(), true);
}
$jsons = glob($jobsDir . '/*.json') ?: [];
?>
<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>設置チェック</title>
<style>
body{font-family:system-ui,-apple-system,"Hiragino Sans",Meiryo,sans-serif;background:#FFF9F7;color:#3F3F46;
     line-height:1.9;padding:40px 20px;max-width:820px;margin:0 auto}
h1{font-size:20px;color:#334155}h2{font-size:15px;margin-top:32px;color:#334155}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #E7E5E4;border-radius:12px;overflow:hidden}
td{padding:10px 14px;border-top:1px solid #E7E5E4;font-size:14px;vertical-align:top}
tr:first-child td{border-top:0}
td:first-child{width:34%;font-weight:700;background:#FBEDEF}
td:nth-child(2){width:44px;text-align:center}
a{color:#C27484}.note{font-size:14px;color:#71717A}
.box{background:#fff;border:1px solid #E7E5E4;border-radius:12px;padding:18px 20px;margin-top:14px}
</style></head><body>
<h1>設置チェック</h1>
<p class="note">問題が解決したら、このファイル（check.php）はサーバーから削除してください。</p>

<h2>環境</h2>
<table>
<?php
row('PHPバージョン', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION);
row('サーバー', true, $_SERVER['SERVER_SOFTWARE'] ?? '不明');
row('mod_rewrite', $rewrite, $rewrite === null ? '判定できません（CGI実行のため）' : ($rewrite ? '有効' : '無効'));
row('DOCUMENT_ROOT', true, $_SERVER['DOCUMENT_ROOT'] ?? '不明');
row('このファイルの場所', true, __DIR__);
row('判定したベースURL', true, $base === '' ? '（ドキュメントルート直下）' : $base);
?>
</table>

<h2>ファイル</h2>
<table>
<?php
foreach (['job.php', 'render.php', '_base.php', 'theme.css', '.htaccess',
          'admin/index.php', 'admin/lib.php', 'admin/api.php', 'admin/config.php',
          'admin/admin.css', 'admin/admin.js', 'data/.htaccess'] as $f) {
    row($f, is_file(__DIR__ . '/' . $f), is_file(__DIR__ . '/' . $f) ? 'あり' : '見つかりません');
}
row('data/jobs フォルダ', is_dir($jobsDir), is_dir($jobsDir) ? $jobsDir : '見つかりません');
row('data/jobs に書き込める', is_writable($jobsDir),
    is_dir($jobsDir) ? ('パーミッション ' . substr(sprintf('%o', fileperms($jobsDir)), -3)) : '—');
row('求人JSONの件数', count($jsons) > 0, count($jsons) . ' 件');
?>
</table>

<h2>リンク確認</h2>
<div class="box">
  <p><a href="<?= htmlspecialchars(u('/admin/')) ?>"><?= htmlspecialchars(u('/admin/')) ?></a>　管理画面</p>
<?php if ($jsons): $first = basename($jsons[0], '.json'); ?>
  <p><a href="<?= htmlspecialchars(job_url($first)) ?>"><?= htmlspecialchars(job_url($first)) ?></a>　求人票（きれいなURL）</p>
  <p><a href="<?= htmlspecialchars(u('/job.php?id=' . $first)) ?>"><?= htmlspecialchars(u('/job.php?id=' . $first)) ?></a>　求人票（直リンク）</p>
  <p class="note">直リンクは開けるのに「きれいなURL」が404になる場合は、mod_rewrite か AllowOverride が無効です。</p>
<?php endif; ?>
</div>
</body></html>
