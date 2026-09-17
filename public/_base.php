<?php
/**
 * 設置場所（ベースURL）の判定
 *
 * ドキュメントルート直下でも、/recruit-file/ のようなサブディレクトリでも
 * 動くように、このファイルの位置からベースURLを求めます。
 *
 * 自動判定がうまくいかない場合だけ、下の BASE_URL_OVERRIDE に手で設定してください。
 *   例）https://example.com/recruit-file/ に置いた場合 → '/recruit-file'
 *       ドキュメントルート直下に置いた場合           → ''
 */

const BASE_URL_OVERRIDE = '';   // ← 通常は空のままでOK

function base_url()
{
    static $cached = null;
    if ($cached !== null) return $cached;

    if (BASE_URL_OVERRIDE !== '') {
        return $cached = '/' . trim(BASE_URL_OVERRIDE, '/');
    }

    $norm = function ($p) { return str_replace('\\', '/', (string)$p); };

    // このファイル（_base.php）は public/ 直下にある
    $selfDir = rtrim($norm(__DIR__), '/');
    $docRoot = rtrim($norm($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

    if ($docRoot !== '' && strpos($selfDir, $docRoot) === 0) {
        $b = substr($selfDir, strlen($docRoot));
        return $cached = ($b === '/' ? '' : rtrim($b, '/'));
    }

    // フォールバック：実行中スクリプトのURLから逆算する
    $script = $norm($_SERVER['SCRIPT_NAME'] ?? '');   // 例 /recruit-file/admin/index.php
    $file   = $norm($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script !== '' && $file !== '') {
        // public/ から見た相対の深さぶんだけ、URLからも削る
        $rel = str_replace($selfDir, '', rtrim($norm(dirname($file)), '/')); // 例 /admin
        $depth = ($rel === '' || $rel === '/') ? 0 : substr_count(trim($rel, '/'), '/') + 1;
        $b = dirname($script);
        for ($i = 0; $i < $depth; $i++) $b = dirname($b);
        $b = rtrim($norm($b), '/');
        return $cached = ($b === '/' ? '' : $b);
    }

    return $cached = '';
}

/** ベースURLを付けたパスを返す　例：u('/theme.css') → /recruit-file/theme.css */
function u($path = '')
{
    return base_url() . '/' . ltrim((string)$path, '/');
}

/** 求人票のURL　例：job_url('mynavi-works') → /recruit-file/job/mynavi-works/ */
function job_url($id)
{
    return u('job/' . rawurlencode((string)$id) . '/');
}
