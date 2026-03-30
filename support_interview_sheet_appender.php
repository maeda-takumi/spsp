<?php

declare(strict_types=1);

const SUPPORT_INTERVIEW_SPREADSHEET_ID = '1mDccfeN9sR8OJdWLv6wPN0DzRr5Y5OfLSmrjjHOvMIs';
const SUPPORT_INTERVIEW_SHEET_NAME = '送付管理';
const SUPPORT_INTERVIEW_SERVICE_ACCOUNT_FILE = 'service_account.json';
const GOOGLE_SHEETS_APPEND_SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

function supportInterviewBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function supportInterviewHttpPostForm(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('cURLの初期化に失敗しました。');
    }

    $allHeaders = array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $allHeaders,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP POSTに失敗しました。' . ($error !== '' ? ' ' . $error : ''));
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('APIレスポンスの解析に失敗しました。response=' . $raw);
    }

    if ($status >= 400) {
        throw new RuntimeException('HTTPエラー: status=' . $status . ' response=' . $raw);
    }

    return $decoded;
}

function supportInterviewHttpGetJson(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('cURLの初期化に失敗しました。');
    }

    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP GETに失敗しました。' . ($error !== '' ? ' ' . $error : ''));
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('APIレスポンスの解析に失敗しました。response=' . $raw);
    }

    if ($status >= 400) {
        throw new RuntimeException('HTTPエラー: status=' . $status . ' response=' . $raw);
    }

    return $decoded;
}

function supportInterviewHttpPostJson(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('cURLの初期化に失敗しました。');
    }

    $allHeaders = array_merge(['Content-Type: application/json'], $headers);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $allHeaders,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP POSTに失敗しました。' . ($error !== '' ? ' ' . $error : ''));
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('APIレスポンスの解析に失敗しました。response=' . $raw);
    }

    if ($status >= 400) {
        throw new RuntimeException('HTTPエラー: status=' . $status . ' response=' . $raw);
    }

    return $decoded;
}

function supportInterviewFetchAccessToken(string $serviceAccountFilePath): string
{
    if (!is_file($serviceAccountFilePath)) {
        throw new RuntimeException('service accountファイルが見つかりません: ' . $serviceAccountFilePath);
    }

    $raw = file_get_contents($serviceAccountFilePath);
    if ($raw === false) {
        throw new RuntimeException('service accountファイルの読み込みに失敗しました。');
    }

    $credentials = json_decode($raw, true);
    if (!is_array($credentials)) {
        throw new RuntimeException('service accountファイルのJSON解析に失敗しました。');
    }

    $clientEmail = (string) ($credentials['client_email'] ?? '');
    $privateKey = (string) ($credentials['private_key'] ?? '');
    $tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');

    if ($clientEmail === '' || $privateKey === '') {
        throw new RuntimeException('service accountファイルにclient_email/private_keyがありません。');
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
    $signed = openssl_sign($jwtUnsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if ($signed !== true) {
        throw new RuntimeException('JWT署名に失敗しました。');
    }

    $jwt = $jwtUnsigned . '.' . supportInterviewBase64UrlEncode($signature);

    $tokenResponse = supportInterviewHttpPostForm($tokenUri, [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);

    $accessToken = (string) ($tokenResponse['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('アクセストークンの取得に失敗しました。');
    }

    return $accessToken;
}

function supportInterviewFindRowNumberBySheetId(string $spreadsheetId, string $sheetName, string $targetSheetId, string $accessToken): ?int
{
    $range = rawurlencode($sheetName . '!A:A');
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode($spreadsheetId)
        . '/values/'
        . $range;

    $response = supportInterviewHttpGetJson($url, ['Authorization: Bearer ' . $accessToken]);
    $values = $response['values'] ?? [];
    if (!is_array($values)) {
        throw new RuntimeException('対象シートのA列取得に失敗しました。');
    }

    foreach ($values as $index => $row) {
        $cellValue = trim((string) ($row[0] ?? ''));
        if ($cellValue !== '' && $cellValue === $targetSheetId) {
            return $index + 1;
        }
    }

    return null;
}

function supportInterviewMarkCompleted(string $spreadsheetId, string $sheetName, int $rowNumber, string $accessToken): void
{
    $range = $sheetName . '!K' . $rowNumber . ':L' . $rowNumber;
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode($spreadsheetId)
        . '/values:batchUpdate';

    $payload = [
        'valueInputOption' => 'USER_ENTERED',
        'data' => [
            [
                'range' => $range,
                'majorDimension' => 'ROWS',
                'values' => [
                    [date('Y/m/d'), 'TRUE'],
                ],
            ],
        ],
    ];

    supportInterviewHttpPostJson($url, $payload, ['Authorization: Bearer ' . $accessToken]);
}

function supportInterviewMarkMailCompleted(string $spreadsheetId, string $sheetName, int $rowNumber, string $accessToken): void
{
    $range = $sheetName . '!O' . $rowNumber . ':P' . $rowNumber;
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode($spreadsheetId)
        . '/values:batchUpdate';

    $payload = [
        'valueInputOption' => 'USER_ENTERED',
        'data' => [
            [
                'range' => $range,
                'majorDimension' => 'ROWS',
                'values' => [
                    [date('Y/m/d'), 'TRUE'],
                ],
            ],
        ],
    ];

    supportInterviewHttpPostJson($url, $payload, ['Authorization: Bearer ' . $accessToken]);
}
function appendSupportInterviewRecordToSheet(array $record, array $writingData, string $action): void
{
    unset($writingData, $action);

    $sheetId = trim((string) ($record['sheet_id'] ?? ''));
    if ($sheetId === '') {
        throw new RuntimeException('連携対象のsheet_idがありません。');
    }

    $serviceAccountFilePath = __DIR__ . '/' . SUPPORT_INTERVIEW_SERVICE_ACCOUNT_FILE;
    $accessToken = supportInterviewFetchAccessToken($serviceAccountFilePath);

    $rowNumber = supportInterviewFindRowNumberBySheetId(
        SUPPORT_INTERVIEW_SPREADSHEET_ID,
        SUPPORT_INTERVIEW_SHEET_NAME,
        $sheetId,
        $accessToken
    );

    if ($rowNumber === null) {
        throw new RuntimeException('送付管理シートにsheet_id=' . $sheetId . ' の行が見つかりません。');
    }

    supportInterviewMarkCompleted(
        SUPPORT_INTERVIEW_SPREADSHEET_ID,
        SUPPORT_INTERVIEW_SHEET_NAME,
        $rowNumber,
        $accessToken
    );
}

function appendSupportInterviewMailCompletedToSheet(array $record): void
{
    $sheetId = trim((string) ($record['sheet_id'] ?? ''));
    if ($sheetId === '') {
        throw new RuntimeException('連携対象のsheet_idがありません。');
    }

    $serviceAccountFilePath = __DIR__ . '/' . SUPPORT_INTERVIEW_SERVICE_ACCOUNT_FILE;
    $accessToken = supportInterviewFetchAccessToken($serviceAccountFilePath);

    $rowNumber = supportInterviewFindRowNumberBySheetId(
        SUPPORT_INTERVIEW_SPREADSHEET_ID,
        SUPPORT_INTERVIEW_SHEET_NAME,
        $sheetId,
        $accessToken
    );

    if ($rowNumber === null) {
        throw new RuntimeException('送付管理シートにsheet_id=' . $sheetId . ' の行が見つかりません。');
    }

    supportInterviewMarkMailCompleted(
        SUPPORT_INTERVIEW_SPREADSHEET_ID,
        SUPPORT_INTERVIEW_SHEET_NAME,
        $rowNumber,
        $accessToken
    );
}
