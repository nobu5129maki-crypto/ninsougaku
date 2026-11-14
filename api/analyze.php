<?php
// エックスサーバー（PHP）向けの鑑定 API。
// 入出力・プロンプト・バリデーション・エラー文は api/analyze.js に合わせる。
// API キーはブラウザへ出さない。公開ディレクトリの外の config.php か環境変数から読む。

ini_set('display_errors', '0');
@set_time_limit(60);
ob_start();

$MODEL = 'gemini-3.8-flash';

function ninsougaku_respond($status, $body)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = '{"error":"鑑定中に問題が起きました。少し待ってから、もう一度お試しください。"}';
    }
    echo $json;
    exit;
}

function ninsougaku_slice($value, $limit)
{
    $value = (string) $value;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit, 'UTF-8');
    }
    return substr($value, 0, $limit);
}

function ninsougaku_prompt()
{
    return "これは利用者が自分でカメラに向かって撮った写真です。娯楽としての人相占いをしてください。身元の特定や医療診断はしないでください。\n"
        . "顔が少しでも写っていれば、背景の説明では断らず、その顔を読んでください。顔がまったくないときだけ、正面を向くよう短く案内してください。\n"
        . "次の3つの見出しだけを、それぞれ独立した行として書いてください。見出しに # や * は付けないでください。\n"
        . "顔から読み取れる性格\n"
        . "いまの運気\n"
        . "これからへのアドバイス\n"
        . "各見出しのあとに、丁寧な語りかける口調で2段落書いてください。";
}

function ninsougaku_reading_text($result)
{
    $parts = array();
    if (isset($result['candidates'][0]['content']['parts']) && is_array($result['candidates'][0]['content']['parts'])) {
        $parts = $result['candidates'][0]['content']['parts'];
    }
    $texts = array();
    foreach ($parts as $part) {
        if (!is_array($part) || !isset($part['text']) || !is_string($part['text']) || $part['text'] === '') {
            continue;
        }
        if (!empty($part['thought'])) {
            continue;
        }
        $texts[] = $part['text'];
    }
    return trim(implode("\n", $texts));
}

function ninsougaku_key_from_config($path, $docRoot)
{
    if (!is_file($path)) {
        return '';
    }
    $real = realpath($path);
    if (!is_string($real) || $real === '') {
        return '';
    }
    $realNorm = str_replace('\\', '/', $real);
    $rootNorm = rtrim(str_replace('\\', '/', $docRoot), '/');
    if ($rootNorm !== '' && ($realNorm === $rootNorm || strpos($realNorm, $rootNorm . '/') === 0)) {
        return '';
    }
    if (preg_match('#/public_html(/|$)#', $realNorm)) {
        return '';
    }
    if (basename($realNorm) !== 'config.php') {
        return '';
    }

    $level = ob_get_level();
    ob_start();
    try {
        $config = include $real;
    } catch (Throwable $e) {
        $config = null;
    }
    while (ob_get_level() > $level) {
        ob_end_clean();
    }

    if (!is_array($config) || !isset($config['GEMINI_API_KEY']) || !is_string($config['GEMINI_API_KEY'])) {
        return '';
    }
    return trim($config['GEMINI_API_KEY']);
}

function ninsougaku_api_key()
{
    $candidates = array();
    $fromEnv = getenv('GEMINI_API_KEY');
    if (is_string($fromEnv)) {
        $candidates[] = $fromEnv;
    }
    if (isset($_ENV['GEMINI_API_KEY']) && is_string($_ENV['GEMINI_API_KEY'])) {
        $candidates[] = $_ENV['GEMINI_API_KEY'];
    }
    if (isset($_SERVER['GEMINI_API_KEY']) && is_string($_SERVER['GEMINI_API_KEY'])) {
        $candidates[] = $_SERVER['GEMINI_API_KEY'];
    }
    foreach ($candidates as $value) {
        if (trim($value) !== '') {
            return trim($value);
        }
    }

    $docRoot = '';
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $resolved = realpath($_SERVER['DOCUMENT_ROOT']);
        if (is_string($resolved) && $resolved !== '') {
            $docRoot = $resolved;
        }
    }
    if ($docRoot === '') {
        return '';
    }

    $paths = array();
    $add = function ($path) use (&$paths) {
        if (!in_array($path, $paths, true)) {
            $paths[] = $path;
        }
    };
    $above = dirname($docRoot);
    $add($above . '/config.php');
    $add($above . '/private/config.php');

    $dir = $docRoot;
    for ($i = 0; $i < 6; $i++) {
        if (basename($dir) === 'public_html') {
            $add(dirname($dir) . '/config.php');
            $add(dirname($dir) . '/private/config.php');
            break;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    foreach ($paths as $path) {
        $key = ninsougaku_key_from_config($path, $docRoot);
        if ($key !== '') {
            return $key;
        }
    }
    return '';
}

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
if ($method !== 'POST') {
    ninsougaku_respond(405, array('error' => 'この操作はできません。'));
}

require dirname(__DIR__) . '/auth.php';
if (!ninsougaku_logged_in()) {
    ninsougaku_respond(401, array('error' => 'ログインしてください。'));
}

$apiKey = ninsougaku_api_key();
if ($apiKey === '') {
    ninsougaku_respond(500, array('error' => 'サーバーにAPIキーが設定されていません。'));
}

$rawBody = file_get_contents('php://input');
$payloadIn = json_decode($rawBody === false ? '' : $rawBody, true);
if (!is_array($payloadIn)) {
    $payloadIn = array();
}

$image = isset($payloadIn['image']) ? $payloadIn['image'] : null;
$mimeType = (isset($payloadIn['mimeType']) && $payloadIn['mimeType'] === 'image/png') ? 'image/png' : 'image/jpeg';
if (!is_string($image) || strlen($image) < 100) {
    ninsougaku_respond(400, array('error' => '顔の画像を取得できませんでした。カメラを向けて、もう一度お試しください。'));
}
if (strlen($image) > 3500000) {
    ninsougaku_respond(413, array('error' => '画像が大きすぎます。もう一度お試しください。'));
}

$payload = array(
    'contents' => array(
        array(
            'role' => 'user',
            'parts' => array(
                array('text' => ninsougaku_prompt()),
                array('inlineData' => array('mimeType' => $mimeType, 'data' => $image)),
            ),
        ),
    ),
    'generationConfig' => array(
        'thinkingConfig' => array('thinkingLevel' => 'low'),
        'maxOutputTokens' => 2048,
    ),
);

$body = json_encode($payload, JSON_UNESCAPED_UNICODE);
if ($body === false) {
    ninsougaku_respond(500, array('error' => '鑑定中に問題が起きました。少し待ってから、もう一度お試しください。'));
}

$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $MODEL . ':generateContent?key=' . rawurlencode($apiKey);

if (!function_exists('curl_init')) {
    ninsougaku_respond(500, array('error' => '鑑定中に問題が起きました。少し待ってから、もう一度お試しください。'));
}

$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 55,
));
$raw = curl_exec($ch);
$errno = curl_errno($ch);
$httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $errno || $httpStatus === 0) {
    error_log('gemini_error curl ' . $errno);
    ninsougaku_respond(500, array('error' => '鑑定中に問題が起きました。少し待ってから、もう一度お試しください。'));
}

$result = json_decode($raw, true);
if (!is_array($result)) {
    $result = array();
}

if ($httpStatus < 200 || $httpStatus >= 300) {
    $detail = '';
    if (isset($result['error']['message'])) {
        $detail = ninsougaku_slice($result['error']['message'], 300);
    }
    $statusName = '';
    if (isset($result['error']['status'])) {
        $statusName = (string) $result['error']['status'];
    }
    error_log('gemini_error ' . $httpStatus . ' ' . $statusName . ' ' . $detail);
    $friendly = ($httpStatus === 429 || $httpStatus === 503)
        ? 'いま鑑定が混み合っています。少し待ってから、もう一度お試しください。'
        : '鑑定できませんでした。顔が映っていることを確認して、もう一度お試しください。';
    ninsougaku_respond(502, array('error' => $friendly));
}

if (!empty($result['promptFeedback']['blockReason'])) {
    ninsougaku_respond(422, array('error' => 'この画像では鑑定できませんでした。顔がはっきり写るようにして、もう一度お試しください。'));
}

$text = ninsougaku_reading_text($result);
if ($text === '') {
    ninsougaku_respond(502, array('error' => '鑑定結果が得られませんでした。もう一度お試しください。'));
}

ninsougaku_respond(200, array('text' => $text));
