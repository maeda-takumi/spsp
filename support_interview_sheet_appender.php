<?php

declare(strict_types=1);

const SUPPORT_INTERVIEW_SPREADSHEET_ID = '1mDccfeN9sR8OJdWLv6wPN0DzRr5Y5OfLSmrjjHOvMIs';
const SUPPORT_INTERVIEW_SHEET_NAME = '送付管理';
const SUPPORT_INTERVIEW_SERVICE_ACCOUNT_FILE = __DIR__ . '/service_account.json';
const GOOGLE_SHEETS_APPEND_SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

function supportInterviewBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function supportInterviewHttpPostForm(string $url, array $payload): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('cURL初期化に失敗しました。');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP POST失敗: ' . $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('トークンレスポンスのJSON解析に失敗しました。 response=' . $raw);
    }
    if ($status >= 400) {
        throw new RuntimeException('トークン取得APIエラー status=' . $status . ' response=' . $raw);
    }

    return $decoded;
}

function supportInterviewHttpPostJson(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('cURL初期化に失敗しました。');
    }

    $allHeaders = array_merge(['Content-Type: application/json'], $headers);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $allHeaders,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP POST失敗: ' . $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('APIレスポンスのJSON解析に失敗しました。 response=' . $raw);
    }
    if ($status >= 400) {
        throw new RuntimeException('Sheets APIエラー status=' . $status . ' response=' . $raw);
    }

    return $decoded;
}

function fetchSupportInterviewAccessToken(string $serviceAccountFile): string
{
    if (!is_file($serviceAccountFile)) {
        throw new RuntimeException('service_account.json が見つかりません。');
    }

    $credentials = json_decode((string) file_get_contents($serviceAccountFile), true);
    if (!is_array($credentials)) {
        throw new RuntimeException('service_account.json のJSON解析に失敗しました。');
    }

    $clientEmail = (string) ($credentials['client_email'] ?? '');
    $privateKey = (string) ($credentials['private_key'] ?? '');
    $tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    if ($clientEmail === '' || $privateKey === '') {
        throw new RuntimeException('service_account.json に client_email / private_key がありません。');
    }

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claimSet = [
        'iss' => $clientEmail,
        'scope' => GOOGLE_SHEETS_APPEND_SCOPE,
        'aud' => $tokenUri,
        'iat' => $now,
        'exp' => $now + 3600,
    ];

    $jwtUnsigned = supportInterviewBase64UrlEncode(json_encode($header, JSON_UNESCAPED_UNICODE))
        . '.'
        . supportInterviewBase64UrlEncode(json_encode($claimSet, JSON_UNESCAPED_UNICODE));

    $signature = '';
    $ok = openssl_sign($jwtUnsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if ($ok !== true) {
        throw new RuntimeException('JWT署名に失敗しました。');
    }

    $jwt = $jwtUnsigned . '.' . supportInterviewBase64UrlEncode($signature);
    $tokenResponse = supportInterviewHttpPostForm($tokenUri, [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);

    $accessToken = (string) ($tokenResponse['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('アクセストークン取得に失敗しました。');
    }

    return $accessToken;
}

function appendSupportInterviewRecordToSheet(array $record, array $writingRow, string $action): void
{
    $accessToken = fetchSupportInterviewAccessToken(SUPPORT_INTERVIEW_SERVICE_ACCOUNT_FILE);
    $timestamp = date('Y-m-d H:i:s');
    $recordedDate = date('Y-m-d');
    $range = rawurlencode(SUPPORT_INTERVIEW_SHEET_NAME . '!A:Z');
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode(SUPPORT_INTERVIEW_SPREADSHEET_ID)
        . '/values/'
        . $range
        . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

    $payload = [
        'values' => [[
            $timestamp,
            (string) ($action),
            (string) ($record['sheet_id'] ?? ''),
            (string) ($record['line_name'] ?? ''),
            (string) ($record['full_name'] ?? ''),
            (string) ($record['sales_staff'] ?? ''),
            (string) ($record['video_staff'] ?? ''),
            (string) ($writingRow['id'] ?? ''),
            (string) ($writingRow['file_name'] ?? ''),
            (string) ($writingRow['writing'] ?? ''),
            (string) ($writingRow['writing_notes'] ?? ''),
            $recordedDate,
            true,
        ]],
    ];

    supportInterviewHttpPostJson(
        $url,
        $payload,
        ['Authorization: Bearer ' . $accessToken]
    );
}
