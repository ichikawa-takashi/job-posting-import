<?php
/**
 * 求人票ページ
 *
 *   /job/<id>/        （.htaccess のリライト経由）
 *   /job.php?id=<id>  （直接アクセス）
 *
 * 指定された1件の JSON だけを読み込みます。
 * 他社のデータはこのページのどこにも含まれません。
 */

require __DIR__ . '/_base.php';
require __DIR__ . '/render.php';
require __DIR__ . '/terms.php';
require_once __DIR__ . '/admin/lib.php';   // 公開状態の判定と、編集者かどうかの確認

$JOBS_DIR = __DIR__ . '/data/jobs';

$id = isset($_GET['id']) ? (string)$_GET['id'] : '';

// ディレクトリトラバーサル対策：英数字・ハイフン・アンダースコアのみ許可
if ($id === '' || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $id)) {
    not_found();
}

$file = $JOBS_DIR . '/' . $id . '.json';
if (!is_file($file)) {
    not_found();
}

$job = json_decode(file_get_contents($file), true);
if (!is_array($job)) {
    not_found();
}

/* ------------------------------------------------------------
   公開状態による出し分け
   ------------------------------------------------------------
   公開中   … 誰でも開ける
   限定公開 … 誰でも開ける。ただし一覧には編集者にしか出ない。
              直接リンクを渡して見てもらうための状態。
   非公開   … 開けない。編集者だけ、確認用に開ける。
   ------------------------------------------------------------ */
$status  = normalize_status($job['status'] ?? 'published');
$preview = false;
if (!status_page_visible($status)) {
    if (!is_editor_if_signed_in()) not_found();
    $preview = true;   // 編集者による確認表示
}

/* ------------------------------------------------------------
   表示用の言い換え（terms.php）
   データは書き換えず、このページに出す文字だけを差し替えます。
   例）施工管理 → 不動産事務
   category は対象外なので、一覧ページと管理画面は元の表記のままです。
   ------------------------------------------------------------ */
$job = apply_display_terms($job);

function not_found() {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>ページが見つかりません</title>'
       . '<link rel="stylesheet" href="' . e(u('/theme.css')) . '?v=21' . '"></head><body>'
       . '<section class="block"><div class="wrap center" style="padding:80px 0">'
       . '<h1 class="section-title">ページが見つかりません</h1>'
       . '<p class="muted mt-s">URLをご確認いただくか、担当者までお問い合わせください。</p>'
       . '</div></section></body></html>';
    exit;
}

$company = $job['company'] ?? '求人票';
$jobName = $job['jobName'] ?? '';
$pageTitle = $company . ($jobName !== '' ? '｜' . $jobName : '');
$desc = mb_substr(preg_replace('/\s+/u', ' ', (string)($job['lead'] ?? $job['headline'] ?? '')), 0, 110, 'UTF-8');

$renderer = new JobRenderer($job);
$body = $renderer->render();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<meta name="description" content="<?= e($desc) ?>">
<title><?= e($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Shippori+Mincho:wght@500;600&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(u('/theme.css')) . '?v=21' ?>">
</head>
<body>

<?php if ($preview): ?>
<div class="preview-bar">
  <div class="wrap inner">
    <strong>非公開の求人です</strong>
    この画面は管理者にだけ表示されています。求職者がこのURLを開いても表示されません。
    <a href="<?= e(u('/admin/')) ?>">管理画面へ</a>
  </div>
</div>
<?php endif; ?>

<header class="site-header">
  <div class="wrap inner">
    <span class="brand"><?= e($company) ?><small><?= e($jobName) ?></small></span>
  </div>
</header>

<main>
<?= $body ?>
</main>

<script>
/* 目次：いま画面に見えているセクションを目次側で光らせる */
(function () {
  var toc = document.querySelector('.toc');
  if (!toc) return;
  var links = Array.prototype.slice.call(toc.querySelectorAll('a[href^="#"]'));
  if (!links.length) return;

  var map = {};
  var targets = [];
  links.forEach(function (a) {
    var el = document.getElementById(a.getAttribute('href').slice(1));
    if (el) { map[el.id] = a; targets.push(el); }
  });
  if (!targets.length) return;

  function mark(a) {
    links.forEach(function (x) { x.classList.remove('on'); });
    if (a) a.classList.add('on');
  }

  if ('IntersectionObserver' in window) {
    var seen = {};
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { seen[en.target.id] = en.isIntersecting ? en.intersectionRatio : 0; });
      // いちばん上にある「見えているセクション」を現在地とする
      var cur = null;
      for (var i = 0; i < targets.length; i++) {
        if (seen[targets[i].id]) { cur = targets[i]; break; }
      }
      if (cur) mark(map[cur.id]);
    }, { rootMargin: '-84px 0px -55% 0px', threshold: [0, 0.01] });
    targets.forEach(function (t) { io.observe(t); });
  } else {
    // 古いブラウザ向け
    var onScroll = function () {
      var y = window.pageYOffset + 120, cur = targets[0];
      targets.forEach(function (t) { if (t.offsetTop <= y) cur = t; });
      mark(map[cur.id]);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // クリックしたら即座に光らせる（スクロール完了を待たない）
  links.forEach(function (a) {
    a.addEventListener('click', function () { mark(a); });
  });
})();
</script>

</body>
</html>
