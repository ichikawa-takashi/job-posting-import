<?php
require __DIR__ . '/../_base.php';
require __DIR__ . '/lib.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $r = login($_POST['user'] ?? '', $_POST['pass'] ?? '');
    if ($r === 'ok')          { header('Location: index.php'); exit; }
    elseif ($r === 'locked')  { $err = '入力を5回間違えました。60秒お待ちください。'; }
    else                      { $err = 'IDまたはパスワードが違います。'; }
}
if (isset($_GET['logout'])) { logout(); header('Location: index.php'); exit; }

$authed  = is_logged_in();
// 一覧を見るだけの権限では、この画面には入れない
$allowed = $authed && is_editor();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>求人票 管理画面</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Shippori+Mincho:wght@500;600&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(u('/theme.css')) ?>?v=21">
<link rel="stylesheet" href="admin.css?v=21">
<script src="<?= e(u('/pw-toggle.js')) ?>?v=21" defer></script>
</head>
<body>

<?php if (!$authed): ?>
<!-- ============================ ログイン ============================ -->
<div id="gate">
  <div class="box">
    <p class="mark">求人票 管理画面</p>
    <p class="sub">担当者のみご利用いただけます。</p>
    <form method="post" autocomplete="off">
      <div class="f"><label for="u">ID</label><input type="text" id="u" name="user" autocomplete="username" autofocus></div>
      <div class="f"><label for="p">パスワード</label><input type="password" id="p" name="pass" autocomplete="current-password"></div>
      <?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
      <button class="btn btn-primary" type="submit" name="login" value="1">ログイン</button>
    </form>
  </div>
</div>

<?php elseif (!$allowed): ?>
<!-- ==================== 権限が足りない ==================== -->
<div id="gate">
  <div class="box">
    <p class="mark">この画面は使えません</p>
    <p class="sub">
      いまログインしているID（<?= e(current_user()) ?>）は、求人の一覧を見るための権限です。<br>
      求人票の編集と公開には、管理者用のIDが必要です。
    </p>
    <p style="margin:22px 0 0">
      <a class="btn btn-primary" href="<?= e(u('/list.php')) ?>">一覧ページへ</a>
    </p>
    <p class="sub" style="margin-top:18px">
      管理者用のIDでログインし直す場合は
      <a href="?logout=1">こちらからログアウト</a>してください。
    </p>
  </div>
</div>

<?php else: ?>
<!-- ============================ 管理画面 ============================ -->
<header class="site-header">
  <div class="wrap inner">
    <span class="brand">求人票 管理画面<small><?= e(current_user()) ?></small></span>
    <nav class="header-nav">
      <a href="<?= e(u('/list.php')) ?>" target="_blank" rel="noopener">一覧ページ</a>
      <a href="#" id="navImport">JSONを読み込む</a>
      <a href="#" id="navPass">パスワード変更</a>
      <a href="?logout=1">ログアウト</a>
    </nav>
  </div>
</header>

<div class="admin">
  <aside class="side">
    <div class="side-head">
      <h2>求人一覧<span class="n" id="listCount"></span></h2>
      <input type="search" id="sideSearch" placeholder="社名・職種で絞り込む" autocomplete="off">
    </div>
    <div class="side-scroll" id="list"><p class="small muted">読み込み中…</p></div>
    <div class="side-foot">
      <button class="btn btn-quiet btn-sm block" id="add">＋ 新しい求人を追加</button>
      <button class="btn btn-ghost btn-sm block" id="importBtn">JSONを読み込む</button>
    </div>
  </aside>

  <div class="main">
    <div class="bar">
      <div class="who" id="who">—</div>
      <span class="pill" id="statusPill"></span>
      <button class="btn btn-ghost btn-sm" id="view">求人票を開く</button>
      <button class="btn btn-quiet btn-sm" id="dup">複製</button>
      <button class="btn btn-danger btn-sm" id="del">削除</button>
      <label class="statuspick">公開状態
        <select id="statusSel">
          <option value="published">公開中</option>
          <option value="draft">限定公開</option>
          <option value="private">非公開</option>
        </select>
      </label>
      <button class="btn btn-primary btn-sm" id="save">保存</button>
    </div>
    <p class="urlline" id="urlline"></p>
    <form id="form"><p class="small muted">左から求人を選んでください。</p></form>
  </div>
</div>

<!-- 取り込みダイアログ -->
<dialog id="dlgImport"><div class="dg">
  <h3>JSONを読み込む</h3>
  <p class="small muted">Cowork で作成した求人JSONを取り込みます。1件でも複数件でも読み込めます。</p>
  <div class="drop" id="drop">
    <p><strong>ここにJSONファイルをドラッグ</strong></p>
    <p class="small muted">または</p>
    <label class="btn btn-quiet btn-sm">ファイルを選ぶ<input type="file" id="file" accept=".json,application/json" multiple hidden></label>
  </div>
  <p class="small muted mt-s">貼り付けでも取り込めます</p>
  <textarea id="importText" placeholder='{ "id": "example", "company": "株式会社◯◯", ... }'></textarea>
  <label class="chk"><input type="checkbox" id="overwrite"> 同じIDがある場合は上書きする</label>
  <label class="statuspick block">取り込んだ直後の状態
    <select id="importStatus">
      <option value="draft">限定公開（リンクを知っている人だけ見られる）</option>
      <option value="private">非公開（ページを開けない）</option>
      <option value="published">公開中（一覧にも出る）</option>
    </select>
  </label>
  <div id="importResult"></div>
  <div class="dg-foot">
    <button class="btn btn-ghost btn-sm" data-close>閉じる</button>
    <button class="btn btn-primary btn-sm" id="doImport">読み込む</button>
  </div>
</div></dialog>

<!-- パスワード変更ダイアログ -->
<dialog id="dlgPass"><div class="dg">
  <h3>パスワード変更</h3>
  <p class="small muted">新しいパスワードを入力すると、設定用の1行を発行します。<br>
    サーバー上の <code>admin/config.php</code> を開き、変えたいIDの <code>'pass' =&gt;</code> の行を
    その1行に差し替えてください。</p>
  <div class="f"><label>どのIDのパスワードを変えますか</label>
    <select id="passUser">
      <?php foreach (USERS as $uid => $u): ?>
      <option value="<?= e($uid) ?>"><?= e($uid) ?>（<?= ($u['role'] ?? '') === 'editor' ? '管理者' : '一覧のみ' ?>）</option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="f"><label>新しいパスワード（8文字以上）</label><input type="text" id="newPass" data-pw-toggle autocomplete="new-password"></div>
  <button class="btn btn-quiet btn-sm" id="makeHash">発行する</button>
  <code id="hashOut" style="display:none"></code>
  <div class="dg-foot"><button class="btn btn-primary btn-sm" data-close>閉じる</button></div>
</div></dialog>

<div class="toast" id="toast"></div>

<script>
  window.CSRF = <?= json_encode(csrf_token()) ?>;
  window.BASE = <?= json_encode(base_url()) ?>;
</script>
<script src="admin.js?v=21"></script>
<?php endif; ?>

</body>
</html>
