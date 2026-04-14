<?php

declare(strict_types=1);

require_once  '../config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function apiRespond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiFail(string $message, int $statusCode = 400): void
{
    apiRespond([
        'ok' => false,
        'message' => $message,
    ], $statusCode);
}

function apiDb(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function parseInput(): array
{
    $contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));

    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            apiFail('JSON の形式が不正です。');
        }

        return $json;
    }

    return $_POST;
}

function normalizeField(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }

    return trim((string) $value);
}

function ensureRequestManagementTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS request_management (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sheet_id BIGINT NOT NULL,
            document_type VARCHAR(255) NOT NULL,
            request_type VARCHAR(255) NOT NULL,
            curriculum_type VARCHAR(255) NOT NULL DEFAULT "",
            send_date DATE NULL,
            memo TEXT NULL,
            is_completed BOOLEAN NOT NULL DEFAULT FALSE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $columnCheckStmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1'
    );
    $columnCheckStmt->bindValue(':schema', DB_NAME);
    $columnCheckStmt->bindValue(':table_name', 'request_management');
    $columnCheckStmt->bindValue(':column_name', 'request_type');
    $columnCheckStmt->execute();

    if (!$columnCheckStmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE request_management ADD COLUMN request_type VARCHAR(255) NOT NULL AFTER document_type');
    }
    $columnCheckStmt->bindValue(':schema', DB_NAME);
    $columnCheckStmt->bindValue(':table_name', 'request_management');
    $columnCheckStmt->bindValue(':column_name', 'curriculum_type');
    $columnCheckStmt->execute();

    if (!$columnCheckStmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE request_management ADD COLUMN curriculum_type VARCHAR(255) NOT NULL DEFAULT "" AFTER request_type');
    }
    $columnCheckStmt->bindValue(':schema', DB_NAME);
    $columnCheckStmt->bindValue(':table_name', 'request_management');
    $columnCheckStmt->bindValue(':column_name', 'memo');
    $columnCheckStmt->execute();

    if (!$columnCheckStmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE request_management ADD COLUMN memo TEXT NULL AFTER request_type');
    }
    $columnCheckStmt->bindValue(':schema', DB_NAME);
    $columnCheckStmt->bindValue(':table_name', 'request_management');
    $columnCheckStmt->bindValue(':column_name', 'send_date');
    $columnCheckStmt->execute();

    if (!$columnCheckStmt->fetchColumn()) {
        $pdo->exec('ALTER TABLE request_management ADD COLUMN send_date DATE NULL AFTER request_type');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiFail('POST メソッドで実行してください。', 405);
}

$input = parseInput();
$sheetId = normalizeField($input, 'sheet_id');
$documentType = normalizeField($input, 'document_type');
$requestType = normalizeField($input, 'request_type');
$curriculumType = normalizeField($input, 'curriculum_type');
$sendDate = normalizeField($input, 'send_date');
$memo = normalizeField($input, 'memo');

if ($sheetId === '' || $documentType === '' || $requestType === '') {
    apiFail('sheet_id / document_type / request_type は必須です。');
}

if (!ctype_digit($sheetId)) {
    apiFail('sheet_id は数字で指定してください。');
}

try {
    $pdo = apiDb();
    ensureRequestManagementTable($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO request_management (sheet_id, document_type, request_type, curriculum_type, send_date, memo)
         VALUES (:sheet_id, :document_type, :request_type, :curriculum_type, :send_date, :memo)'
    );
    $stmt->bindValue(':sheet_id', (int) $sheetId, PDO::PARAM_INT);
    $stmt->bindValue(':document_type', $documentType);
    $stmt->bindValue(':request_type', $requestType);
    $stmt->bindValue(':curriculum_type', $curriculumType);
    $stmt->bindValue(':send_date', $sendDate === '' ? null : $sendDate, $sendDate === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':memo', $memo);
    $stmt->execute();

    apiRespond([
        'ok' => true,
        'message' => 'request_management に保存しました。',
        'id' => (int) $pdo->lastInsertId(),
    ]);
} catch (PDOException $e) {
    apiFail('DBエラーが発生しました。', 500);
} catch (Throwable $e) {
    apiFail('保存処理に失敗しました。', 500);
}
