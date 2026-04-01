<?php

declare(strict_types=1);

require_once 'config.php';
require_once 'customer_tagging.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function taggingApiDb(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function taggingApiResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function taggingApiFail(string $message, int $status = 400): void
{
    taggingApiResponse([
        'ok' => false,
        'message' => $message,
    ], $status);
}

$action = trim((string) ($_REQUEST['action'] ?? ''));
if ($action === '') {
    taggingApiFail('action が指定されていません。');
}

try {
    $pdo = taggingApiDb();
    ensureCustomerTagTables($pdo);

    if ($action === 'list') {
        $sheetId = trim((string) ($_GET['sheet_id'] ?? ''));
        if ($sheetId === '') {
            taggingApiFail('sheet_id が指定されていません。');
        }

        taggingApiResponse([
            'ok' => true,
            'tags' => fetchCustomerTagMaster($pdo),
            'assigned_tags' => fetchCustomerTagsBySheetId($pdo, $sheetId),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        taggingApiFail('POSTメソッドで実行してください。', 405);
    }

    if ($action === 'assign' || $action === 'unassign') {
        $sheetId = trim((string) ($_POST['sheet_id'] ?? ''));
        $tagId = (int) ($_POST['tag_id'] ?? 0);

        if ($sheetId === '' || $tagId <= 0) {
            taggingApiFail('sheet_id または tag_id が不正です。');
        }

        if ($action === 'assign') {
            attachTagToSheet($pdo, $sheetId, $tagId);
        } else {
            detachTagFromSheet($pdo, $sheetId, $tagId);
        }

        taggingApiResponse([
            'ok' => true,
            'tags' => fetchCustomerTagMaster($pdo),
            'assigned_tags' => fetchCustomerTagsBySheetId($pdo, $sheetId),
        ]);
    }

    if ($action === 'create_tag') {
        $name = (string) ($_POST['name'] ?? '');
        $color = (string) ($_POST['color'] ?? '');
        createCustomerTag($pdo, $name, $color);
        taggingApiResponse([
            'ok' => true,
            'tags' => fetchCustomerTagMaster($pdo),
        ]);
    }

    if ($action === 'update_tag') {
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        $name = (string) ($_POST['name'] ?? '');
        $color = (string) ($_POST['color'] ?? '');
        if ($tagId <= 0) {
            taggingApiFail('tag_id が不正です。');
        }
        updateCustomerTag($pdo, $tagId, $name, $color);
        taggingApiResponse([
            'ok' => true,
            'tags' => fetchCustomerTagMaster($pdo),
        ]);
    }

    if ($action === 'delete_tag') {
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        if ($tagId <= 0) {
            taggingApiFail('tag_id が不正です。');
        }
        deleteCustomerTag($pdo, $tagId);
        taggingApiResponse([
            'ok' => true,
            'tags' => fetchCustomerTagMaster($pdo),
        ]);
    }

    taggingApiFail('未対応の action です。');
} catch (InvalidArgumentException $e) {
    taggingApiFail($e->getMessage());
} catch (PDOException $e) {
    taggingApiFail('DBエラーが発生しました。', 500);
} catch (Throwable $e) {
    taggingApiFail('タグ操作に失敗しました。', 500);
}
