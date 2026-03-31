<?php

declare(strict_types=1);

require_once 'config.php';
require_once 'refund_guarantee_section.php';

header('Content-Type: application/json; charset=UTF-8');

function respondJson(int $status, array $payload): void
{
    http_response_code($status);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        echo '{"ok":false,"message":"JSONの生成に失敗しました。"}';
        return;
    }
    echo $json;
}

function db(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respondJson(405, [
        'ok' => false,
        'message' => 'POSTで実行してください。',
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$payload = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($payload)) {
    $payload = $_POST;
}

$sheetId = trim((string) ($payload['sheet_id'] ?? ''));
$questionKey = trim((string) ($payload['question_key'] ?? ''));
$isChecked = (int) ($payload['is_checked'] ?? 0) === 1 ? 1 : 0;

if ($sheetId === '' || $questionKey === '') {
    respondJson(422, [
        'ok' => false,
        'message' => 'sheet_id または question_key が不足しています。',
    ]);
    exit;
}

$questions = refundGuaranteeQuestions();
if (!isset($questions[$questionKey])) {
    respondJson(422, [
        'ok' => false,
        'message' => '不正な設問です。',
    ]);
    exit;
}

try {
    $pdo = db();
    ensureRefundGuaranteeTable($pdo);

    $stmt = $pdo->prepare('INSERT INTO customer_refund_guarantee_checks (sheet_id, question_key, question_label, is_checked, checked_at)
        VALUES (:sheet_id, :question_key, :question_label, :is_checked, CASE WHEN :is_checked = 1 THEN NOW() ELSE NULL END)
        ON DUPLICATE KEY UPDATE
            question_label = VALUES(question_label),
            is_checked = VALUES(is_checked),
            checked_at = CASE WHEN VALUES(is_checked) = 1 THEN NOW() ELSE NULL END');
    $stmt->bindValue(':sheet_id', $sheetId);
    $stmt->bindValue(':question_key', $questionKey);
    $stmt->bindValue(':question_label', (string) $questions[$questionKey]);
    $stmt->bindValue(':is_checked', $isChecked, PDO::PARAM_INT);
    $stmt->execute();

    respondJson(200, [
        'ok' => true,
        'message' => '保存しました。',
        'sheet_id' => $sheetId,
        'question_key' => $questionKey,
        'is_checked' => $isChecked,
    ]);
} catch (Throwable $e) {
    respondJson(500, [
        'ok' => false,
        'message' => '保存に失敗しました。',
    ]);
}
