<?php
declare(strict_types=1);

/**
 * oauth_gmail_token.php
 *
 * 使い方:
 * 1) 下の CLIENT_ID / CLIENT_SECRET / REDIRECT_URI を設定
 * 2) このファイルURLにアクセス（例: https://totalappworks.com/supsup_neo/oauth_gmail_token.php）
 * 3) Google同意後、同じURLに code が返ってくる
 * 4) refresh_token を download/send_1/google_oauth_token.json に保存
 */

session_start();

/* ====== 設定ここから ====== */
const CLIENT_ID     = '908342051818-rps5nkqsfk4l8eb8kebbfcu790n2586l.apps.googleusercontent.com';
const CLIENT_SECRET = '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';
const REDIRECT_URI  = 'https://totalappworks.com/supsup_neo/oauth_gmail_token.php'; // このファイルの公開URLと完全一致
const SCOPE         = 'https://www.googleapis.com/auth/gmail.send';
const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
const TOKEN_SAVE_PATH = __DIR__ . '/download/send_1/google_oauth_token.json';
/* ====== 設定ここまで ====== */

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function saveToken(array $token): void {
    $dir = dirname(TOKEN_SAVE_PATH);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('downloadフォルダの作成に失敗しました: ' . $dir);
        }
    }
    $json = json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('JSONエンコードに失敗しました');
    }
    if (file_put_contents(TOKEN_SAVE_PATH, $json, LOCK_EX) === false) {
        throw new RuntimeException('トークン保存に失敗しました');
    }
    @chmod(TOKEN_SAVE_PATH, 0600);
}

function postToken(array $fields): array {
    $ch = curl_init(TOKEN_ENDPOINT);
    if ($ch === false) {
        throw new RuntimeException('cURL初期化に失敗しました');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('トークンAPI通信エラー: ' . $err);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('トークンAPIレスポンスのJSON解析に失敗: ' . $response);
    }

    if ($httpCode >= 400) {
        throw new RuntimeException('トークンAPIエラー: HTTP ' . $httpCode . ' / ' . json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    return $data;
}

// Googleから戻ってきたとき
if (isset($_GET['code'])) {
    try {
        $state = $_GET['state'] ?? '';
        if (!isset($_SESSION['oauth_state']) || !hash_equals((string)$_SESSION['oauth_state'], (string)$state)) {
            throw new RuntimeException('state不一致（CSRFの可能性）');
        }

        $code = (string)$_GET['code'];
        $token = postToken([
            'code' => $code,
            'client_id' => CLIENT_ID,
            'client_secret' => CLIENT_SECRET,
            'redirect_uri' => REDIRECT_URI,
            'grant_type' => 'authorization_code',
        ]);

        // 送信処理側が必要とする client_id / client_secret を保存データに追加
        $token['client_id'] = CLIENT_ID;
        $token['client_secret'] = CLIENT_SECRET;

        // refresh_token が無い場合の注意
        if (empty($token['refresh_token'])) {
            // 既存同意済み等で返らないケースあり
            $token['_warning'] = 'refresh_token が返っていません。prompt=consent で再同意、または連携解除後に再実行してください。';
        }

        saveToken($token);

        echo '<h2>成功</h2>';
        echo '<p>トークンを保存しました。</p>';
        echo '<p><code>' . h(TOKEN_SAVE_PATH) . '</code></p>';
        echo '<pre>' . h(json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<h2>エラー</h2><pre>' . h($e->getMessage()) . '</pre>';
        exit;
    }
}

// 開始（初回アクセス）
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$params = [
    'client_id' => CLIENT_ID,
    'redirect_uri' => REDIRECT_URI,
    'response_type' => 'code',
    'scope' => SCOPE,
    'access_type' => 'offline',
    'prompt' => 'consent',
    'include_granted_scopes' => 'true',
    'state' => $state,
];

$authUrl = AUTH_ENDPOINT . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

echo '<h2>Google OAuth開始</h2>';
echo '<p>下のリンクをクリックして会社アカウントで同意してください。</p>';
echo '<p><a href="' . h($authUrl) . '">Googleで認可する</a></p>';
echo '<p>redirect_uri: <code>' . h(REDIRECT_URI) . '</code></p>';