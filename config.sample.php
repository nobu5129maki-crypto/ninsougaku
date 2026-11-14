<?php
/**
 * Gemini API キーの見本です。このファイルには実キーを書かないでください。
 *
 * 1. このファイルを config.php という名前でコピーする。
 * 2. GEMINI_API_KEY に、自分の Gemini API キーを入れる。
 * 3. 公開ディレクトリの外に置く。
 *    エックスサーバーでは public_html と同じ階層です。
 *    例: /home/サーバーID/ドメイン名/config.php
 *    （public_html の中、サブフォルダの中には置かない）
 *
 * ファイルが無い、またはキーが空のとき、analyze.php は HTTP 500 で次を返します。
 * {"error":"サーバーにAPIキーが設定されていません。"}
 */
return array(
    'GEMINI_API_KEY' => '',
);
