<?php
/**
 * 求人JSON 投入API
 * ============================================================
 *
 *  疎通確認（トークン不要・情報は返しません）
 *      GET  api/import.php?ping=1
 *
 *  投入
 *      POST api/import.php
 *      Authorization: Bearer <トークン>（config.php の API_TOKENS のいずれか）
 *      Content-Type: application/json
 *
 *      本文は次のいずれでも受け付けます
 *        { "id": "...", "company": "..." }          … 1件
 *        [ {...}, {...} ]                            … 複数
 *        { "jobs": [ {...} ], "overwrite": true }    … オプション付き
 *
 *      オプション（本文 または クエリ）
 *        overwrite : true なら既存IDを上書き（既定 false＝スキップ）
 *        publish   : true なら「新規の求人」を公開状態で取り込む
 *                    ※ config.php の API_ALLOW_PUBLISH が false なら無視され、必ず下書き
 *        force     : true なら既存の求人の公開状態も publish の指定で上書きする
 *
 *  公開状態の扱い（重要）
 *      ・新規の求人   … publish の指定に従う（既定は下書き）
 *      ・既存の求人   … サーバー側の現在の状態を維持する
 *                       → すでに公開中の求人が、取り込みで下書きに戻ることはありません
 *                       → 状態も上書きしたいときだけ force=true を付けます
 *
 *  応答
 *      { "ok": true, "added": [...], "updated": [...], "skipped": [...] }
 */

declare(strict_types=1);

require __DIR__ . '/../admin/lib.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

const MAX_BODY_BYTES = 2 * 1024 * 1024;   // 2MB
const MAX_JOBS       = 50;

function out(array $a, int $code = 200): void {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 送信者の識別名（トークン照合が通ったあとに入る） */
$API_SENDER = '-';

function api_log(string $msg): void {
    global $API_SENDER;
    $f = dirname(__DIR__) . '/data/api.log';
    $line = sprintf("[%s] %s by=%s %s\n", date('Y-m-d H:i:s'),
        $_SERVER['REMOTE_ADDR'] ?? '-', $API_SENDER, $msg);
    @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
}

/**
 * 部分更新のための深いマージ
 *   ・連想配列は再帰的に合成する
 *   ・添字配列（リスト）は「まるごと差し替え」にする
 *     （途中だけ混ざると意味が壊れるため）
 */
function deep_merge(array $base, array $patch): array {
    foreach ($patch as $k => $v) {
        if (is_array($v)
            && isset($base[$k]) && is_array($base[$k])
            && array_keys($v) !== range(0, count($v) - 1)) {
            $base[$k] = deep_merge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

/**
 * 有効なトークンの一覧を「名前 => トークン」で返す
 * 旧設定（API_TOKEN 単体）でもそのまま動くようにしておく
 */
function api_tokens(): array {
    $list = [];
    if (defined('API_TOKENS') && is_array(API_TOKENS)) {
        foreach (API_TOKENS as $name => $tok) {
            $tok = (string)$tok;
            if ($tok !== '') $list[(string)$name] = $tok;
        }
    }
    if (!$list && defined('API_TOKEN') && API_TOKEN !== '') {
        $list['-'] = (string)API_TOKEN;
    }
    return $list;
}

/* ------------------------------------------------------------
   疎通確認
   ------------------------------------------------------------ */
if (isset($_GET['ping'])) {
    out([
        'ok'      => true,
        'service' => 'job-import',
        'ready'   => (api_tokens() !== [] && is_writable(JOBS_DIR)),
    ]);
}

/* ------------------------------------------------------------
   送信前の下調べ（GET ?ids=1）
   いま登録されている求人のID・会社名・公開状態を返す。
   push.command が「新規か更新か」を y の前に表示するために使う。
   トークンが必要。
   ------------------------------------------------------------ */
if (isset($_GET['ids'])) {
    $tk = api_tokens();
    $t  = '';
    $h  = $_SERVER['HTTP_AUTHORIZATION']
        ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (getenv('HTTP_AUTHORIZATION') ?: ''));
    if ($h !== '' && stripos($h, 'bearer ') === 0)      $t = trim(substr($h, 7));
    elseif (!empty($_SERVER['HTTP_X_API_TOKEN']))       $t = (string)$_SERVER['HTTP_X_API_TOKEN'];
    // ヘッダーを付けられない環境（Cowork の web_fetch など）向けに
    // クエリでもトークンを受け付ける。読み取り専用なのでこの用途に限る。
    elseif (!empty($_GET['token']))                     $t = (string)$_GET['token'];

    $hit = false;
    foreach ($tk as $tok) { if ($t !== '' && hash_equals($tok, $t)) $hit = true; }
    if (!$hit) { usleep(400000); out(['ok' => false, 'error' => '認証に失敗しました'], 401); }

    $rows = [];
    $cats = [];
    foreach (glob(rtrim(JOBS_DIR, '/') . '/*.json') ?: [] as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (!is_array($d) || empty($d['id'])) continue;
        $fee = $d['fee'] ?? null;
        $cat = (string)($d['category'] ?? '');
        if ($cat !== '' && !in_array($cat, $cats, true)) $cats[] = $cat;
        $row = [
            'id'       => $d['id'],
            'company'  => $d['company'] ?? '',
            'category' => $cat,
            'status'   => $d['status'] ?? 'draft',
            'hasFee'   => (is_array($fee) && trim((string)($fee['type'] ?? '')) !== ''),
            'updated'  => $d['updated'] ?? '',
        ];
        // ?ids=1&full=1 … 本文も返す。
        // 管理画面で直したあとの「いまサーバーにある文章」を手元で確認するため。
        // 成約フィーは社内情報なので、金額そのものは返さない（hasFee だけ）。
        if (!empty($_GET['full'])) {
            unset($d['fee']);
            $row['data'] = $d;
        }
        $rows[] = $row;
    }
    sort($cats);
    out([
        'ok'         => true,
        'jobs'       => $rows,
        'categories' => $cats,
        'fetchedAt'  => date('Y-m-d H:i'),
    ]);
}

/* ------------------------------------------------------------
   メソッド
   ------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(['ok' => false, 'error' => 'POSTで送信してください'], 405);
}

/* ------------------------------------------------------------
   トークン照合
   ------------------------------------------------------------ */
$tokens = api_tokens();
if ($tokens === []) {
    out(['ok' => false, 'error' => 'APIは停止しています'], 503);
}

$sent = '';
$hdr = $_SERVER['HTTP_AUTHORIZATION']
    ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? (getenv('HTTP_AUTHORIZATION') ?: ''));
if ($hdr !== '' && stripos($hdr, 'bearer ') === 0) {
    $sent = trim(substr($hdr, 7));
} elseif (!empty($_SERVER['HTTP_X_API_TOKEN'])) {
    $sent = (string)$_SERVER['HTTP_X_API_TOKEN'];
}

/* 一致するトークンを探す。hash_equals で1本ずつ比べるので、
   トークンの本数によって処理時間が変わらない（タイミング攻撃対策）。 */
$matched = '';
foreach ($tokens as $name => $tok) {
    if ($sent !== '' && hash_equals($tok, $sent)) $matched = (string)$name;
}
if ($matched === '') {
    api_log('AUTH FAILED');
    // 総当たりを遅くする
    usleep(400000);
    out(['ok' => false, 'error' => '認証に失敗しました'], 401);
}
$API_SENDER = $matched;

/* ------------------------------------------------------------
   本文の読み込み
   ------------------------------------------------------------ */
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    out(['ok' => false, 'error' => '本文が空です'], 400);
}
if (strlen($raw) > MAX_BODY_BYTES) {
    out(['ok' => false, 'error' => '本文が大きすぎます（上限2MB）'], 413);
}

$payload = json_decode($raw, true);
if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
    out(['ok' => false, 'error' => 'JSONの形式が正しくありません：' . json_last_error_msg()], 400);
}

$overwrite = false;
$publish   = false;
$force     = false;   // 既存求人の公開状態も上書きするか
if (is_array($payload) && isset($payload['jobs'])) {
    $overwrite = !empty($payload['overwrite']);
    $publish   = !empty($payload['publish']);
    $force     = !empty($payload['force']);
}
if (isset($_GET['overwrite'])) $overwrite = filter_var($_GET['overwrite'], FILTER_VALIDATE_BOOLEAN);
if (isset($_GET['publish']))   $publish   = filter_var($_GET['publish'],   FILTER_VALIDATE_BOOLEAN);
if (isset($_GET['force']))     $force     = filter_var($_GET['force'],     FILTER_VALIDATE_BOOLEAN);

// 設定で禁止されていれば、必ず下書き
if (!defined('API_ALLOW_PUBLISH') || API_ALLOW_PUBLISH !== true) $publish = false;

$jobs = normalize_import($payload);
if (!$jobs) {
    out(['ok' => false, 'error' => '取り込めるデータが見つかりませんでした'], 400);
}
if (count($jobs) > MAX_JOBS) {
    out(['ok' => false, 'error' => '一度に取り込めるのは' . MAX_JOBS . '件までです'], 400);
}

/* ------------------------------------------------------------
   保存
   ------------------------------------------------------------ */
if (!jobs_dir_ready()) {
    out(['ok' => false, 'error' => 'data/jobs フォルダに書き込めません。パーミッションをご確認ください。'], 500);
}

$added = $updated = $skipped = [];
$kept  = [];   // 既存の公開状態を維持した求人
$held  = [];   // 管理画面で入れた項目を守った求人
$merged = [];  // 部分更新した求人

foreach ($jobs as $job) {
    if (!is_array($job)) { $skipped[] = '（形式が不正）'; continue; }

    $id = $job['id'] ?? '';
    if (!valid_id($id)) {
        $skipped[] = ($job['company'] ?? '（IDなし）') . '：IDが不正です';
        continue;
    }
    if (empty($job['company'])) {
        $skipped[] = $id . '：会社名がありません';
        continue;
    }

    $current = load_job($id);
    $exists  = ($current !== null);

    if ($exists && !$overwrite) {
        $skipped[] = $id . '：既に存在します（上書きするには overwrite:true）';
        continue;
    }

    /* ------------------------------------------------------------
       部分更新（"_merge": true）
         送ったJSONに書いた項目だけを差し替え、それ以外は既存のまま残す。
         「年収の推移だけ後から足す」といった用途で使う。
         既存が無い場合は、ただの新規登録として扱う。
       ------------------------------------------------------------ */
    $isMerge = !empty($job['_merge']);
    unset($job['_merge']);
    if ($isMerge) {
        if ($exists) {
            $job = deep_merge($current, $job);
            $merged[] = $id;
        } else {
            $skipped[] = $id . '：部分更新の指定ですが、まだ登録されていません';
            continue;
        }
    }

    /* ------------------------------------------------------------
       管理画面でだけ入力する項目を守る
         送ってきたJSONにその項目が無ければ、サーバー側の値を引き継ぐ。
         これにより、同じIDを送り直しても成約フィーなどが消えない。
       ------------------------------------------------------------ */
    if ($exists && defined('PRESERVE_ON_IMPORT') && is_array(PRESERVE_ON_IMPORT)) {
        foreach (PRESERVE_ON_IMPORT as $key) {
            $key = (string)$key;
            if (!array_key_exists($key, $job) && array_key_exists($key, $current)) {
                $job[$key] = $current[$key];
                $held[$id][] = $key;
            }
        }
    }

    /* ------------------------------------------------------------
       公開状態の決め方
         新規      … publish の指定に従う（既定は下書き）
         既存      … サーバー側の現在の状態をそのまま引き継ぐ
                     （force=true のときだけ publish の指定で上書き）
       これにより、取り込みで公開中の求人が下書きに戻ることはありません。
       ------------------------------------------------------------ */
    if (!$exists) {
        $job['status'] = $publish ? 'published' : 'draft';
    } elseif ($force) {
        $job['status'] = $publish ? 'published' : 'draft';
    } else {
        $job['status'] = $current['status'] ?? 'draft';
        if (($job['status'] ?? '') === 'published') $kept[] = $id;
    }

    [$ok, $err] = save_job($job);

    if ($ok) { $exists ? ($updated[] = $id) : ($added[] = $id); }
    else     { $skipped[] = $id . '：' . $err; }
}

api_log(sprintf('IMPORT added=%d updated=%d merged=%d skipped=%d held=%d publish=%s force=%s',
    count($added), count($updated), count($merged), count($skipped), count($held),
    $publish ? 'yes' : 'no', $force ? 'yes' : 'no'));

out([
    'ok'          => true,
    'added'       => $added,
    'updated'     => $updated,
    'skipped'     => $skipped,
    'keptPublic'  => $kept,
    'heldFields'  => $held,
    'merged'      => $merged,
    'newStatus'   => $publish ? 'published' : 'draft',
    'message'     => ($publish
        ? '新規の求人を公開状態で取り込みました。'
        : '新規の求人を下書きとして取り込みました。管理画面で確認のうえ「公開する」を押してください。')
        . ($kept ? '既存の公開中の求人（' . count($kept) . '件）は、公開状態のまま更新しました。' : '')
        . ($held ? '管理画面で入力した項目（' . count($held) . '件分）はそのまま残しました。' : ''),
]);
