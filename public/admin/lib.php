<?php
/**
 * 管理画面の共通処理（認証・求人JSONの読み書き）
 */

require_once __DIR__ . '/config.php';

/* ------------------------------------------------------------
   共通ヘルパー（render.php と同じもの。単独でも動くよう定義）
   ------------------------------------------------------------ */
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('g')) {
    function g($a, $k, $d = null) { return (is_array($a) && array_key_exists($k, $a)) ? $a[$k] : $d; }
}

/* ------------------------------------------------------------
   公開状態
   ------------------------------------------------------------
   published … 公開中。一覧に出て、ページも開ける
   draft     … 限定公開。ページは開けるが、一覧には編集者にしか出ない
                （直接リンクを渡せば誰でも見られる）
   private   … 非公開。ページ自体が開けない（編集者だけ下書き確認できる）

   draft という値は以前から使っているのでそのまま残し、
   画面に出す言葉だけ「限定公開」に変えている。
   ------------------------------------------------------------ */
// このファイルが2回読み込まれても落ちないようにしておく
if (!defined('JOB_STATUSES')) {
    define('JOB_STATUSES', ['published', 'draft', 'private']);
}

function status_label($s) {
    switch ((string)$s) {
        case 'published': return '公開中';
        case 'private':   return '非公開';
        default:          return '限定公開';
    }
}

/** 保存できる値に丸める。知らない値は限定公開として扱う */
function normalize_status($s) {
    $s = (string)$s;
    return in_array($s, JOB_STATUSES, true) ? $s : 'draft';
}

/** 求人票のページを開けるか */
function status_page_visible($s) {
    return normalize_status($s) !== 'private';
}

/** その権限の一覧ページに出すか */
function status_listed_for($s, $role) {
    $s = normalize_status($s);
    if ($role === 'editor') return true;          // 編集者は全部見える
    return $s === 'published';                    // 閲覧者は公開中だけ
}

/* ------------------------------------------------------------
   セッション
   ------------------------------------------------------------ */

// IDが存在しないときにも password_verify を走らせるためのダミー。
// 応答時間の差からIDの有無を推測されないようにする。
if (!defined('DUMMY_PASS_HASH')) {
    define('DUMMY_PASS_HASH', '$2y$10$ehP/zLKEr/Nrfl2FdqjJQeZpKugsg3sGBqem6IdhXDJ9NUC4O0p0y');
}

function boot_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(SESSION_NAME);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $secure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_logged_in() {
    boot_session();
    if (empty($_SESSION['auth'])) return false;
    // 8時間で自動ログアウト
    if (time() - ($_SESSION['at'] ?? 0) > 8 * 3600) { logout(); return false; }
    $_SESSION['at'] = time();
    return true;
}

/* ------------------------------------------------------------
   権限
   ------------------------------------------------------------
   editor … 管理画面で編集・公開ができる。限定公開と非公開も一覧で見える
   viewer … 一覧ページを見るだけ。公開中の求人しか一覧に出ない
   ------------------------------------------------------------ */

/** ログイン中の権限。ログインしていなければ空文字 */
function current_role() {
    if (!is_logged_in()) return '';
    return (string)($_SESSION['role'] ?? 'viewer');
}

/** ログイン中のID */
function current_user() {
    if (!is_logged_in()) return '';
    return (string)($_SESSION['user'] ?? '');
}

/** 管理画面に入れる人か（編集・公開ができるか） */
function is_editor() {
    return current_role() === 'editor';
}

/**
 * 求人票ページのように誰でも開ける場所から使う版。
 * セッションのCookieを持っていない訪問者にはセッションを作らない。
 * （全員にCookieを配ってしまうのを避けるため）
 */
function is_editor_if_signed_in() {
    if (empty($_COOKIE[SESSION_NAME])) return false;
    return is_editor();
}

function login($user, $pass) {
    boot_session();
    // 総当たり対策：5回失敗で60秒ロック
    $fails = $_SESSION['fails'] ?? 0;
    $lock  = $_SESSION['lock'] ?? 0;
    if (time() < $lock) return 'locked';

    $user = (string)$user;
    $pass = (string)$pass;

    // IDが見つからなくても、見つかったときと同じだけ時間をかける。
    // 「そのIDは存在しない」と分かってしまうのを防ぐため。
    $found = USERS[$user] ?? null;
    $hash  = is_array($found) ? (string)($found['pass'] ?? '') : DUMMY_PASS_HASH;

    if (password_verify($pass, $hash) && $found !== null) {
        session_regenerate_id(true);
        $_SESSION['auth']  = true;
        $_SESSION['user']  = $user;
        $_SESSION['role']  = (($found['role'] ?? '') === 'editor') ? 'editor' : 'viewer';
        $_SESSION['at']    = time();
        $_SESSION['fails'] = 0;
        $_SESSION['csrf']  = bin2hex(random_bytes(16));
        return 'ok';
    }
    $fails++;
    $_SESSION['fails'] = $fails;
    if ($fails % 5 === 0) $_SESSION['lock'] = time() + 60;
    return 'ng';
}

function logout() {
    boot_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function csrf_token() {
    boot_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_ok($t) {
    boot_session();
    return !empty($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

/* ------------------------------------------------------------
   求人JSONの読み書き
   ------------------------------------------------------------ */
function valid_id($id) {
    return is_string($id) && preg_match('/\A[a-z0-9][a-z0-9_-]{0,62}[a-z0-9]\z/i', $id);
}

function job_path($id) {
    return rtrim(JOBS_DIR, '/') . '/' . $id . '.json';
}

function jobs_dir_ready() {
    $d = rtrim(JOBS_DIR, '/');
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return is_dir($d) && is_writable($d);
}

function load_job($id) {
    if (!valid_id($id)) return null;
    $f = job_path($id);
    if (!is_file($f)) return null;
    $j = json_decode(file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

function save_job(array $job) {
    $id = $job['id'] ?? '';
    if (!valid_id($id)) return [false, 'IDが不正です（英数字・ハイフン・アンダースコアのみ）'];
    if (!jobs_dir_ready())  return [false, 'data/jobs フォルダに書き込めません。パーミッションをご確認ください。'];

    $job['updated'] = date('Y-m-d H:i');
    $job['status'] = normalize_status($job['status'] ?? 'draft');

    $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) return [false, 'JSONへの変換に失敗しました'];

    // 一時ファイル経由で安全に書き込み
    $f   = job_path($id);
    $tmp = $f . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return [false, '保存に失敗しました'];
    if (!rename($tmp, $f)) { @unlink($tmp); return [false, '保存に失敗しました']; }
    @chmod($f, 0644);
    return [true, ''];
}

function delete_job($id) {
    if (!valid_id($id)) return false;
    $f = job_path($id);
    return is_file($f) ? @unlink($f) : false;
}

/** 一覧用に軽い情報だけ集める */
function list_jobs() {
    $d = rtrim(JOBS_DIR, '/');
    $out = [];
    foreach (glob($d . '/*.json') ?: [] as $f) {
        $j = json_decode(file_get_contents($f), true);
        if (!is_array($j) || empty($j['id'])) continue;
        $stats = [];
        foreach (($j['stats'] ?? []) as $s) {
            $stats[] = ['label' => $s['label'] ?? '', 'value' => $s['value'] ?? ''];
        }
        $out[] = [
            'id'       => $j['id'],
            'category' => $j['category'] ?? 'その他',
            'company'  => $j['company'] ?? '（無題）',
            'jobName'  => $j['jobName'] ?? '',
            'headline' => $j['headline'] ?? '',
            'status'   => normalize_status($j['status'] ?? 'draft'),
            'updated'  => $j['updated'] ?? '',
            'stats'    => $stats,
        ];
    }
    $order = CATEGORY_ORDER;
    usort($out, function ($a, $b) use ($order) {
        $ra = array_search($a['category'], $order, true);
        $rb = array_search($b['category'], $order, true);
        $ra = ($ra === false) ? count($order) : $ra;
        $rb = ($rb === false) ? count($order) : $rb;
        if ($ra !== $rb) return $ra - $rb;
        if ($a['category'] !== $b['category']) return strcmp($a['category'], $b['category']);
        return strcmp($a['company'], $b['company']);
    });
    return $out;
}

/** 取り込み用JSONを正規化（1件でも {jobs:[...]} でも受け付ける） */
function normalize_import($data) {
    if (!is_array($data)) return [];
    if (isset($data['jobs']) && is_array($data['jobs'])) return array_values($data['jobs']);
    if (isset($data['id']) || isset($data['company'])) return [$data];
    // 添字配列
    if (array_keys($data) === range(0, count($data) - 1)) return $data;
    return [];
}

function json_out($arr, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
