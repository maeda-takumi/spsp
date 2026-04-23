<?php

declare(strict_types=1);

require_once '../config.php';

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

function normalizeString(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }

    return trim((string) $value);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiFail('POST メソッドで実行してください。', 405);
}

$input = parseInput();
$id = normalizeString($input, 'id');
$memo = normalizeString($input, 'memo');

if ($id === '') {
    apiFail('id は必須です。');
}

if (!ctype_digit($id)) {
    apiFail('id は数字で指定してください。');
}

try {
    $pdo = apiDb();

    $stmt = $pdo->prepare('UPDATE request_management SET memo = :memo WHERE id = :id');
    $stmt->bindValue(':memo', $memo);
    $stmt->bindValue(':id', (int) $id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        $existsStmt = $pdo->prepare('SELECT 1 FROM request_management WHERE id = :id LIMIT 1');
        $existsStmt->bindValue(':id', (int) $id, PDO::PARAM_INT);
        $existsStmt->execute();
        if (!$existsStmt->fetchColumn()) {
            apiFail('対象データが見つかりません。', 404);
        }
    }

    apiRespond([
        'ok' => true,
        'message' => 'メモを更新しました。',
        'memo' => $memo,
    ]);
} catch (PDOException $e) {
    apiFail('DBエラーが発生しました。', 500);
} catch (Throwable $e) {
    apiFail('更新処理に失敗しました。', 500);
}
