<?php
// エックスサーバー（PHP）向けの鑑定 API。
// 入出力は api/analyze.js に合わせる。待ち時間を抑えるため、画像は縮小し、短い返答で返す。
// API キーはブラウザへ出さない。公開ディレクトリの外の config.php か環境変数から読む。

ini_set('display_errors', '0');
@set_time_limit(48);
ob_start();

function ninsougaku_respond($status, $body)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
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
    $lenses = array(
        '今回は眉の形と目の左右差から入り、そのあと鼻と口へ進んでください。',
        '今回は額の広さと頬の高さ、輪郭の左右の違いを先に見てください。',
        '今回は目尻と口角の上がり方、あごの左右の差から印象を語ってください。',
        '今回は鼻筋と小鼻、人中と唇の厚みの違いを丁寧に見てください。',
        '今回は顔の上半分と下半分のバランスと、耳の付き方にも触れてください。',
        '今回はほお骨の出方と目の大きさの左右差、口もとのゆるみから入ってください。',
    );
    $tones = array(
        '語り口は静かに寄り添う調子にしてください。',
        '語り口は向かい合って話すような明るい調子にしてください。',
        '語り口は情景が浮かぶ、少し長めの文章にしてください。',
        '語り口は率直で、強みも偏りもやわらかく言ってください。',
    );
    $lens = $lenses[random_int(0, count($lenses) - 1)];
    $tone = $tones[random_int(0, count($tones) - 1)];
    return "これは顔写真です。娯楽としての人相占いです。医療診断や身元の特定はしないでください。\n"
        . "顔が少しでも写っていれば読んでください。顔がまったくないときだけ、正面を向くよう短く案内してください。\n"
        . $lens . "\n"
        . "写真に写っている部位だけを具体的に書いてください。額、眉、目、鼻、口、輪郭、左右の差、顔全体の印象のうち、見えるものに触れてください。見えない部位は書かないでください。\n"
        . "次の3つの見出しだけを、一字も変えず、それぞれ独立した行として書いてください。見出しに # や * は付けないでください。\n"
        . "顔から読み取れる性格\n"
        . "いまの運気\n"
        . "これからへのアドバイス\n"
        . "各見出しのあとに、空行を挟んで4文以上書いてください。性格では部位と左右差を性格につなげ、運気では今の表情の印象と流れを、アドバイスではその顔立ちに合わせた次の過ごし方を書いてください。\n"
        . $tone . "\n"
        . "前と同じ顔でも、今回の視点に合わせて言い回しを変えてください。定型文の繰り返しは避けてください。";
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

function ninsougaku_shrink_jpeg($image)
{
    $bin = base64_decode($image, true);
    if ($bin === false || strlen($bin) < 80) {
        return array('', '顔の画像を読み取れませんでした。もう一度撮り直してください。');
    }
    if (strlen($bin) > 1500000) {
        return array('', '画像が大きすぎます。もう一度お試しください。');
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return array($image, '');
    }
    $src = @imagecreatefromstring($bin);
    if (!$src) {
        return array('', '顔の画像を読み取れませんでした。もう一度撮り直してください。');
    }
    $width = imagesx($src);
    $height = imagesy($src);
    $maxEdge = 512;
    $long = max($width, $height);
    if ($long > $maxEdge && $long > 0) {
        $scale = $maxEdge / $long;
        $nw = max(1, (int) round($width * $scale));
        $nh = max(1, (int) round($height * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $width, $height);
        imagedestroy($src);
        $src = $dst;
    }
    ob_start();
    $wrote = imagejpeg($src, null, 55);
    $jpeg = ob_get_clean();
    imagedestroy($src);
    if (!$wrote || !is_string($jpeg) || strlen($jpeg) < 80) {
        return array('', '顔の画像を読み取れませんでした。もう一度撮り直してください。');
    }
    return array(base64_encode($jpeg), '');
}

function ninsougaku_gemini_once($apiKey, $model, $image, $timeout)
{
    $payload = array(
        'contents' => array(
            array(
                'role' => 'user',
                'parts' => array(
                    array('text' => ninsougaku_prompt()),
                    array('inlineData' => array('mimeType' => 'image/jpeg', 'data' => $image)),
                ),
            ),
        ),
        'generationConfig' => array(
            'temperature' => 0.9,
            'maxOutputTokens' => 1800,
            'thinkingConfig' => array('thinkingBudget' => 0),
        ),
    );
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return array('kind' => 'bad', 'text' => '', 'status' => 0);
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . rawurlencode($apiKey);
    $ch = curl_init($url);
    $opts = array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
    );
    if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
        $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $errno === 28 || $httpStatus === 0) {
        return array('kind' => 'timeout', 'text' => '', 'status' => $httpStatus);
    }
    $result = json_decode($raw, true);
    if (!is_array($result)) {
        $result = array();
    }
    if ($httpStatus === 429 || $httpStatus === 503) {
        return array('kind' => 'busy', 'text' => '', 'status' => $httpStatus);
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        $detail = '';
        if (isset($result['error']['message'])) {
            $detail = ninsougaku_slice(preg_replace('/AIza[0-9A-Za-z_\-]{10,}/', '[redacted]', $result['error']['message']), 180);
        }
        error_log('gemini_error ' . $model . ' ' . $httpStatus . ' ' . $detail);
        return array('kind' => 'bad', 'text' => '', 'status' => $httpStatus);
    }
    if (!empty($result['promptFeedback']['blockReason'])) {
        return array('kind' => 'blocked', 'text' => '', 'status' => $httpStatus);
    }
    $text = ninsougaku_reading_text($result);
    if ($text === '') {
        return array('kind' => 'empty', 'text' => '', 'status' => $httpStatus);
    }
    return array('kind' => 'ok', 'text' => $text, 'status' => $httpStatus);
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
if (!is_string($image) || strlen($image) < 100) {
    ninsougaku_respond(400, array('error' => '顔の画像を取得できませんでした。カメラを向けて、もう一度お試しください。'));
}
if (strlen($image) > 2000000) {
    ninsougaku_respond(413, array('error' => '画像が大きすぎます。もう一度お試しください。'));
}

list($image, $imageError) = ninsougaku_shrink_jpeg($image);
if ($imageError !== '') {
    ninsougaku_respond(400, array('error' => $imageError));
}

if (!function_exists('curl_init')) {
    ninsougaku_respond(500, array('error' => '鑑定中に問題が起きました。少し待ってから、もう一度お試しください。'));
}

$last = array('kind' => 'bad', 'text' => '', 'status' => 0);
for ($try = 0; $try < 3; $try++) {
    $last = ninsougaku_gemini_once($apiKey, 'gemini-2.5-flash', $image, 16);
    if ($last['kind'] === 'ok') {
        ninsougaku_respond(200, array('text' => $last['text']));
    }
    if ($last['kind'] === 'blocked') {
        ninsougaku_respond(422, array('error' => 'この画像では鑑定できませんでした。顔がはっきり写るようにして、もう一度お試しください。'));
    }
}

if ($last['kind'] === 'timeout') {
    ninsougaku_respond(504, array('error' => '鑑定に時間がかかりすぎました。もう一度お試しください。'));
}
if ($last['kind'] === 'busy') {
    ninsougaku_respond(503, array('error' => 'いま鑑定が混み合っています。少し待ってから、もう一度お試しください。'));
}
if ($last['kind'] === 'empty') {
    ninsougaku_respond(502, array('error' => '鑑定結果が得られませんでした。もう一度お試しください。'));
}
ninsougaku_respond(502, array('error' => '鑑定できませんでした。顔が映っていることを確認して、もう一度お試しください。'));
