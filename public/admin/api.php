<?php
/**
 * 管理画面のAPI
 *   GET  ?action=list            求人一覧
 *   GET  ?action=get&id=xxx      1件取得
 *   POST action=save             保存（1件）
 *   POST action=delete           削除
 *   POST action=import           JSON取り込み（1件でも複数でも可）
 *   POST action=status           公開状態の切り替え（公開中／限定公開／非公開）
 *   POST action=passhash         新しいパスワードハッシュを発行
 *
 * すべて editor 権限が必要です。
 * 一覧を見るだけの権限（viewer）では、画面を直接たたいても何もできません。
 */

require __DIR__ . '/lib.php';

if (!is_logged_in()) json_out(['ok' => false, 'error' => 'ログインしてください'], 401);
if (!is_editor())    json_out(['ok' => false, 'error' => 'このIDには編集の権限がありません'], 403);

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

// POST は JSON ボディも受け付ける
$body = [];
if ($isPost) {
    $raw = file_get_contents('php://input');
    if ($raw !== '' && ($j = json_decode($raw, true)) && is_array($j)) $body = $j;
    else $body = $_POST;
    $action = $body['action'] ?? $action;
    if (!csrf_ok($body['csrf'] ?? '')) json_out(['ok' => false, 'error' => 'セッションが切れました。再読み込みしてください。'], 403);
}

switch ($action) {

    case 'list':
        json_out(['ok' => true, 'jobs' => list_jobs(), 'categoryOrder' => CATEGORY_ORDER]);

    case 'get':
        $j = load_job($_GET['id'] ?? '');
        if (!$j) json_out(['ok' => false, 'error' => '求人が見つかりません'], 404);
        json_out(['ok' => true, 'job' => $j]);

    case 'save':
        $job = $body['job'] ?? null;
        if (!is_array($job)) json_out(['ok' => false, 'error' => 'データがありません'], 400);
        $oldId = $body['oldId'] ?? '';
        list($ok, $err) = save_job($job);
        if (!$ok) json_out(['ok' => false, 'error' => $err], 400);
        // IDを変更した場合は旧ファイルを削除
        if (valid_id($oldId) && $oldId !== $job['id']) delete_job($oldId);
        json_out(['ok' => true, 'id' => $job['id']]);

    case 'delete':
        $id = $body['id'] ?? '';
        if (!delete_job($id)) json_out(['ok' => false, 'error' => '削除できませんでした'], 400);
        json_out(['ok' => true]);

    case 'status':
        $id = $body['id'] ?? '';
        $j = load_job($id);
        if (!$j) json_out(['ok' => false, 'error' => '求人が見つかりません'], 404);
        $j['status'] = normalize_status($body['status'] ?? '');
        list($ok, $err) = save_job($j);
        json_out($ok
            ? ['ok' => true, 'status' => $j['status'], 'statusLabel' => status_label($j['status'])]
            : ['ok' => false, 'error' => $err], $ok ? 200 : 400);

    case 'import':
        $payload = $body['data'] ?? null;
        if (is_string($payload)) $payload = json_decode($payload, true);
        $jobs = normalize_import($payload);
        if (!$jobs) json_out(['ok' => false, 'error' => '読み込めるデータが見つかりませんでした'], 400);

        // 取り込んだ直後の公開状態。既定は限定公開（draft）
        $mode = normalize_status($body['mode'] ?? 'draft');
        $added = []; $skipped = []; $updated = [];
        foreach ($jobs as $job) {
            if (!is_array($job)) continue;
            if (empty($job['id']) || !valid_id($job['id'])) {
                $skipped[] = ($job['company'] ?? '（ID不正）');
                continue;
            }
            $exists = load_job($job['id']) !== null;
            if ($exists && ($body['overwrite'] ?? false) !== true) {
                $skipped[] = $job['company'] ?? $job['id'];
                continue;
            }
            $job['status'] = $mode;
            list($ok, $err) = save_job($job);
            if ($ok) { $exists ? $updated[] = $job['id'] : $added[] = $job['id']; }
            else     { $skipped[] = ($job['company'] ?? $job['id']) . '（' . $err . '）'; }
        }
        json_out(['ok' => true, 'added' => $added, 'updated' => $updated, 'skipped' => $skipped]);

    case 'passhash':
        $pw = (string)($body['password'] ?? '');
        if (strlen($pw) < 8) json_out(['ok' => false, 'error' => 'パスワードは8文字以上にしてください'], 400);
        json_out(['ok' => true, 'hash' => password_hash($pw, PASSWORD_DEFAULT)]);

    default:
        json_out(['ok' => false, 'error' => '不正なリクエストです'], 400);
}
