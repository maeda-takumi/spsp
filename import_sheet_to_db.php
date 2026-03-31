<?php

declare(strict_types=1);

/**
 * Googleスプレッドシート（投資顧客管理）をMySQLへ保存するスクリプト
 *
 * 前提:
 * - service_account.json が同一ディレクトリに存在
 * - config.php にDB接続情報定義（DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET）
 * - PHP拡張: openssl / curl / pdo_mysql
 */

require_once __DIR__ . '/config.php';

const SPREADSHEET_ID = '1HGz1Jq-S3UWKqtbDtPnRmaDiK5LRUWYifBtBquyVFhU';
const SHEET_NAME = '投資顧客管理';
const SERVICE_ACCOUNT_FILE = __DIR__ . '/service_account.json';
const TARGET_TABLE = 'customer_sales_records';
const STAGING_TABLE = 'customer_sales_records_staging';
const GOOGLE_SHEETS_SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

const DELETE_MISSING_RECORDS = false;
function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function httpPostJson(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl初期化に失敗しました');
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
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP POST失敗: ' . $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSONデコードに失敗しました。response=' . $raw);
    }

    if ($status >= 400) {
        throw new RuntimeException('HTTPエラー: status=' . $status . ' response=' . $raw);
    }

    return $decoded;
}

function httpPostForm(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl初期化に失敗しました');
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
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP POST失敗: ' . $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSONデコードに失敗しました。response=' . $raw);
    }

    if ($status >= 400) {
        throw new RuntimeException('HTTPエラー: status=' . $status . ' response=' . $raw);
    }

    return $decoded;
}

function httpGetJson(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl初期化に失敗しました');
    }

    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP GET失敗: ' . $err);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSONデコードに失敗しました。response=' . $raw);
    }

    if ($status >= 400) {
        throw new RuntimeException('HTTPエラー: status=' . $status . ' response=' . $raw);
    }

    return $decoded;
}

function fetchAccessTokenFromServiceAccount(string $serviceAccountFile): string
{
    if (!file_exists($serviceAccountFile)) {
        throw new RuntimeException("service account file not found: {$serviceAccountFile}");
    }

    $credentials = json_decode((string) file_get_contents($serviceAccountFile), true);
    if (!is_array($credentials)) {
        throw new RuntimeException('service_account.json のJSON解析に失敗しました');
    }

    $clientEmail = $credentials['client_email'] ?? null;
    $privateKey = $credentials['private_key'] ?? null;
    $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';

    if (!is_string($clientEmail) || !is_string($privateKey)) {
        throw new RuntimeException('service_account.json に client_email / private_key がありません');
    }

    $now = time();

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claimSet = [
        'iss' => $clientEmail,
        'scope' => GOOGLE_SHEETS_SCOPE,
        'aud' => $tokenUri,
        'iat' => $now,
        'exp' => $now + 3600,
    ];

    $jwtUnsigned = base64UrlEncode(json_encode($header, JSON_UNESCAPED_UNICODE))
        . '.'
        . base64UrlEncode(json_encode($claimSet, JSON_UNESCAPED_UNICODE));

    $signature = '';
    $ok = openssl_sign($jwtUnsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if ($ok !== true) {
        throw new RuntimeException('JWT署名に失敗しました');
    }

    $jwt = $jwtUnsigned . '.' . base64UrlEncode($signature);

    $tokenResponse = httpPostForm($tokenUri, [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);

    $accessToken = $tokenResponse['access_token'] ?? null;
    if (!is_string($accessToken) || $accessToken === '') {
        throw new RuntimeException('アクセストークン取得に失敗しました');
    }

    return $accessToken;
}

function fetchSheetValues(string $spreadsheetId, string $sheetName, string $accessToken): array
{
    $range = rawurlencode($sheetName . '!A:AC');
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode($spreadsheetId)
        . '/values/'
        . $range;

    $response = httpGetJson($url, ['Authorization: Bearer ' . $accessToken]);

    $values = $response['values'] ?? null;
    if (!is_array($values)) {
        throw new RuntimeException('スプレッドシートのvalues取得に失敗しました');
    }

    return $values;
}

function buildPdo(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_NAME,
        DB_CHARSET
    );

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function normalizeYearMonth(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $value = trim($value);

    if (preg_match('/^(\d{4})[\/-年\s]*(\d{1,2})月?$/u', $value, $m)) {
        return sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
    }

    return null;
}

function normalizeDate(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $value = trim($value);

    if (is_numeric($value)) {
        $unixTime = ((int) $value - 25569) * 86400;
        return gmdate('Y-m-d', $unixTime);
    }

    $value = str_replace(['年', '月', '日', '.'], ['/', '/', '', '/'], $value);

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', $timestamp);
}

function normalizeAmount(?string $value): ?float
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $cleaned = preg_replace('/[^0-9.\-]/', '', $value);
    if ($cleaned === '' || !is_numeric($cleaned)) {
        return null;
    }

    return (float) $cleaned;
}

function normalizeInt(?string $value): ?int
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $cleaned = preg_replace('/[^0-9\-]/', '', $value);
    if ($cleaned === '' || !is_numeric($cleaned)) {
        return null;
    }

    return (int) $cleaned;
}

function valueAt(array $row, int $index): ?string
{
    return array_key_exists($index, $row) ? trim((string) $row[$index]) : null;
}

function logMessage(string $message, bool $isError = false): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;


    error_log($line);

    if (PHP_SAPI === 'cli') {
        $stream = $isError ? 'php://stderr' : 'php://stdout';
        file_put_contents($stream, $line . PHP_EOL, FILE_APPEND);
        return;
    }

    static $headersSent = false;
    if ($headersSent === false) {
        header('Content-Type: text/plain; charset=UTF-8');
        $headersSent = true;
    }

    echo $line . "\n";
    if (function_exists('flush')) {
        flush();
    }
}

function respondAndExit(string $message, int $statusCode = 200): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($statusCode);
    }

    logMessage($message, $statusCode >= 400);
    exit($statusCode >= 400 ? 1 : 0);
}
$columnMap = [
    'sheet_id' => 0,
    'serial_no' => 1,
    'sales_year_month' => 2,
    'payment_year_month' => 3,
    'full_name' => 4,
    'system_name' => 5,
    'entry_point' => 6,
    'status' => 7,
    'line_name' => 8,
    'phone_number' => 9,
    'email' => 10,
    'sales_date' => 11,
    'payment_date' => 12,
    'expected_payment_amount' => 13,
    'payment_amount' => 14,
    'payment_installment_no' => 15,
    'login_id' => 16,
    'payment_destination' => 17,
    'video_staff' => 18,
    'sales_staff' => 19,
    'acquisition_channel' => 20,
    'age' => 21,
    'system_delivery_status' => 22,
    'notes' => 23,
    'payment_week' => 24,
    'data1' => 25,
    'data2' => 26,
    'line_registered_date' => 27,
    'gender' => 28,
];

$insertSql = <<<SQL
INSERT INTO %s (
    sheet_id,
    serial_no,
    sales_year_month,
    payment_year_month,
    full_name,
    system_name,
    entry_point,
    status,
    line_name,
    phone_number,
    email,
    sales_date,
    payment_date,
    expected_payment_amount,
    payment_amount,
    payment_installment_no,
    login_id,
    payment_destination,
    video_staff,
    sales_staff,
    acquisition_channel,
    age,
    system_delivery_status,
    notes,
    payment_week,
    data1,
    data2,
    line_registered_date,
    gender
) VALUES (
    :sheet_id,
    :serial_no,
    :sales_year_month,
    :payment_year_month,
    :full_name,
    :system_name,
    :entry_point,
    :status,
    :line_name,
    :phone_number,
    :email,
    :sales_date,
    :payment_date,
    :expected_payment_amount,
    :payment_amount,
    :payment_installment_no,
    :login_id,
    :payment_destination,
    :video_staff,
    :sales_staff,
    :acquisition_channel,
    :age,
    :system_delivery_status,
    :notes,
    :payment_week,
    :data1,
    :data2,
    :line_registered_date,
    :gender
)
SQL;

$upsertSql = <<<SQL
INSERT INTO %s (
    sheet_id,
    serial_no,
    sales_year_month,
    payment_year_month,
    full_name,
    system_name,
    entry_point,
    status,
    line_name,
    phone_number,
    email,
    sales_date,
    payment_date,
    expected_payment_amount,
    payment_amount,
    payment_installment_no,
    login_id,
    payment_destination,
    video_staff,
    sales_staff,
    acquisition_channel,
    age,
    system_delivery_status,
    notes,
    payment_week,
    data1,
    data2,
    line_registered_date,
    gender
)
SELECT
    sheet_id,
    serial_no,
    sales_year_month,
    payment_year_month,
    full_name,
    system_name,
    entry_point,
    status,
    line_name,
    phone_number,
    email,
    sales_date,
    payment_date,
    expected_payment_amount,
    payment_amount,
    payment_installment_no,
    login_id,
    payment_destination,
    video_staff,
    sales_staff,
    acquisition_channel,
    age,
    system_delivery_status,
    notes,
    payment_week,
    data1,
    data2,
    line_registered_date,
    gender
FROM %s
ON DUPLICATE KEY UPDATE
    serial_no = VALUES(serial_no),
    sales_year_month = VALUES(sales_year_month),
    payment_year_month = VALUES(payment_year_month),
    full_name = VALUES(full_name),
    system_name = VALUES(system_name),
    entry_point = VALUES(entry_point),
    status = VALUES(status),
    line_name = VALUES(line_name),
    phone_number = VALUES(phone_number),
    email = VALUES(email),
    sales_date = VALUES(sales_date),
    payment_date = VALUES(payment_date),
    expected_payment_amount = VALUES(expected_payment_amount),
    payment_amount = VALUES(payment_amount),
    payment_installment_no = VALUES(payment_installment_no),
    login_id = VALUES(login_id),
    payment_destination = VALUES(payment_destination),
    video_staff = VALUES(video_staff),
    sales_staff = VALUES(sales_staff),
    acquisition_channel = VALUES(acquisition_channel),
    age = VALUES(age),
    system_delivery_status = VALUES(system_delivery_status),
    notes = VALUES(notes),
    payment_week = VALUES(payment_week),
    data1 = VALUES(data1),
    data2 = VALUES(data2),
    line_registered_date = VALUES(line_registered_date),
    gender = VALUES(gender)
SQL;
try {
    logMessage('Import started. table=' . TARGET_TABLE . ' sheet=' . SHEET_NAME);
    $accessToken = fetchAccessTokenFromServiceAccount(SERVICE_ACCOUNT_FILE);
    $values = fetchSheetValues(SPREADSHEET_ID, SHEET_NAME, $accessToken);
    logMessage('Sheet fetched. rows=' . count($values));

    if (count($values) <= 1) {
        throw new RuntimeException('対象データが見つかりません（ヘッダーのみ、または空です）');
    }

    $pdo = buildPdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . STAGING_TABLE . ' LIKE ' . TARGET_TABLE);
    $stagingInsertStmt = $pdo->prepare(sprintf($insertSql, STAGING_TABLE));

    $pdo->beginTransaction();
    $deletedFromStaging = (int) $pdo->exec('DELETE FROM ' . STAGING_TABLE);

    $stagingInserted = 0;
    $skippedEmptySheetId = 0;
    foreach (array_slice($values, 1) as $index => $row) {
        if (count(array_filter($row, static fn($v) => trim((string) $v) !== '')) === 0) {
            continue;
        }

        $rowNumber = $index + 2; // スプレッドシート上の実行行番号（ヘッダー=1行目）
        $params = [
            'sheet_id' => valueAt($row, $columnMap['sheet_id']),
            'serial_no' => normalizeInt(valueAt($row, $columnMap['serial_no'])),
            'sales_year_month' => normalizeYearMonth(valueAt($row, $columnMap['sales_year_month'])),
            'payment_year_month' => normalizeYearMonth(valueAt($row, $columnMap['payment_year_month'])),
            'full_name' => valueAt($row, $columnMap['full_name']),
            'system_name' => valueAt($row, $columnMap['system_name']),
            'entry_point' => valueAt($row, $columnMap['entry_point']),
            'status' => valueAt($row, $columnMap['status']),
            'line_name' => valueAt($row, $columnMap['line_name']),
            'phone_number' => valueAt($row, $columnMap['phone_number']),
            'email' => valueAt($row, $columnMap['email']),
            'sales_date' => normalizeDate(valueAt($row, $columnMap['sales_date'])),
            'payment_date' => normalizeDate(valueAt($row, $columnMap['payment_date'])),
            'expected_payment_amount' => normalizeAmount(valueAt($row, $columnMap['expected_payment_amount'])),
            'payment_amount' => normalizeAmount(valueAt($row, $columnMap['payment_amount'])),
            'payment_installment_no' => normalizeInt(valueAt($row, $columnMap['payment_installment_no'])),
            'login_id' => valueAt($row, $columnMap['login_id']),
            'payment_destination' => valueAt($row, $columnMap['payment_destination']),
            'video_staff' => valueAt($row, $columnMap['video_staff']),
            'sales_staff' => valueAt($row, $columnMap['sales_staff']),
            'acquisition_channel' => valueAt($row, $columnMap['acquisition_channel']),
            'age' => normalizeInt(valueAt($row, $columnMap['age'])),
            'system_delivery_status' => valueAt($row, $columnMap['system_delivery_status']),
            'notes' => valueAt($row, $columnMap['notes']),
            'payment_week' => valueAt($row, $columnMap['payment_week']),
            'data1' => valueAt($row, $columnMap['data1']),
            'data2' => valueAt($row, $columnMap['data2']),
            'line_registered_date' => normalizeDate(valueAt($row, $columnMap['line_registered_date'])),
            'gender' => valueAt($row, $columnMap['gender']),
        ];

        if (($params['sheet_id'] ?? null) === null || $params['sheet_id'] === '') {
            $skippedEmptySheetId++;
            continue;
        }
        try {

            $stagingInsertStmt->execute($params);
            $stagingInserted++;
        } catch (Throwable $rowError) {
            throw new RuntimeException(
                '行の登録に失敗しました。sheet_row=' . $rowNumber
                . ' sheet_id=' . (string) ($params['sheet_id'] ?? '')
                . ' reason=' . $rowError->getMessage(),
                0,
                $rowError
            );
        }
    }

    $upserted = (int) $pdo->exec(sprintf($upsertSql, TARGET_TABLE, STAGING_TABLE));
    $deletedFromTarget = 0;
    if (DELETE_MISSING_RECORDS) {
        $deletedFromTarget = (int) $pdo->exec(
            'DELETE target
             FROM ' . TARGET_TABLE . ' AS target
             LEFT JOIN ' . STAGING_TABLE . ' AS staging
                ON staging.sheet_id = target.sheet_id
             WHERE staging.sheet_id IS NULL'
        );
    }

    $pdo->commit();

    $importedCount = (int) $pdo->query('SELECT COUNT(*) FROM ' . TARGET_TABLE)->fetchColumn();
    $completedMessage = 'Import completed. staging_deleted_rows=' . $deletedFromStaging
        . ' staging_inserted_rows=' . $stagingInserted
        . ' upsert_affected_rows=' . $upserted
        . ' target_deleted_missing_rows=' . $deletedFromTarget
        . ' skipped_empty_sheet_id_rows=' . $skippedEmptySheetId
        . ' db_rows=' . $importedCount;
    respondAndExit($completedMessage, 200);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $errorMessage = '[ERROR] Import failed. ' . $e->getMessage();
    respondAndExit($errorMessage, 500);
}
