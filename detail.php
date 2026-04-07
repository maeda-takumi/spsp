<?php

declare(strict_types=1);

require_once 'config.php';
require_once 'chatwork_notifier.php';
require_once 'support_interview_sheet_appender.php';
require_once 'support_interview_mail_completion_updater.php';
require_once 'refund_guarantee_section.php';
require_once 'customer_tagging.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function db(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function getOptionalQuery(string $key): ?string
{
    $value = $_GET[$key] ?? null;
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    return $value === '' ? null : $value;
}
const GOOGLE_OAUTH_TOKEN_FILE =  'download/google_oauth_token.json';
const GOOGLE_OAUTH_CLIENT_FILE = 'download/google_oauth_client_secret.json';
const MAIL_SENDER = 'systemsoufu@gmail.com';
const FALLBACK_MAIL_TO = MAIL_SENDER;
const GMAIL_SEND_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

function requestJson(string $url, array $headers = [], ?string $body = null): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('cURLの初期化に失敗しました。');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => $body !== null,
        CURLOPT_POSTFIELDS => $body,
    ]);

    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($raw === false) {
        throw new RuntimeException('HTTP通信に失敗しました。' . ($error !== '' ? ' ' . $error : ''));
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('APIレスポンスの解析に失敗しました。');
    }

    return [
        'status' => $status,
        'json' => $decoded,
    ];
}

function getGmailAccessToken(): string
{
    if (!is_file(GOOGLE_OAUTH_TOKEN_FILE)) {
        throw new RuntimeException('Google OAuthトークンファイルが見つかりません。');
    }

    $raw = file_get_contents(GOOGLE_OAUTH_TOKEN_FILE);
    if ($raw === false) {
        throw new RuntimeException('Google OAuthトークンファイルの読み込みに失敗しました。');
    }

    $tokenData = json_decode($raw, true);
    if (!is_array($tokenData)) {
        throw new RuntimeException('Google OAuthトークンファイルのJSON形式が不正です。');
    }

    $tokenAppData = [];
    if (isset($tokenData['installed']) && is_array($tokenData['installed'])) {
        $tokenAppData = $tokenData['installed'];
    } elseif (isset($tokenData['web']) && is_array($tokenData['web'])) {
        $tokenAppData = $tokenData['web'];
    }

    $clientData = [];
    if (is_file(GOOGLE_OAUTH_CLIENT_FILE)) {
        $clientRaw = file_get_contents(GOOGLE_OAUTH_CLIENT_FILE);
        if ($clientRaw === false) {
            throw new RuntimeException('Google OAuthクライアント設定ファイルの読み込みに失敗しました。');
        }

        $decodedClientData = json_decode($clientRaw, true);
        if (!is_array($decodedClientData)) {
            throw new RuntimeException('Google OAuthクライアント設定ファイルのJSON形式が不正です。');
        }

        if (isset($decodedClientData['installed']) && is_array($decodedClientData['installed'])) {
            $clientData = $decodedClientData['installed'];
        } elseif (isset($decodedClientData['web']) && is_array($decodedClientData['web'])) {
            $clientData = $decodedClientData['web'];
        } else {
            $clientData = $decodedClientData;
        }
    }

    $clientId = (string) (
        $tokenData['client_id']
        ?? $tokenData['clientId']
        ?? $tokenAppData['client_id']
        ?? $tokenAppData['clientId']
        ?? $clientData['client_id']
        ?? $clientData['clientId']
        ?? getenv('GOOGLE_OAUTH_CLIENT_ID')
        ?? ''
    );
    $clientSecret = (string) (
        $tokenData['client_secret']
        ?? $tokenData['clientSecret']
        ?? $tokenAppData['client_secret']
        ?? $tokenAppData['clientSecret']
        ?? $clientData['client_secret']
        ?? $clientData['clientSecret']
        ?? getenv('GOOGLE_OAUTH_CLIENT_SECRET')
        ?? ''
    );
    $refreshToken = (string) ($tokenData['refresh_token'] ?? '');
    $tokenUri = (string) ($tokenData['token_uri'] ?? $tokenAppData['token_uri'] ?? $clientData['token_uri'] ?? 'https://oauth2.googleapis.com/token');

    if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
        throw new RuntimeException('Google OAuth情報が不足しています。google_oauth_token.json に refresh_token、google_oauth_token.json または google_oauth_client_secret.json に client_id / client_secret を設定してください。');
    }

    $body = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
        'scope' => GMAIL_SEND_SCOPE,
    ]);

    $response = requestJson($tokenUri, ['Content-Type: application/x-www-form-urlencoded'], $body);
    if (($response['status'] ?? 500) >= 300) {
        $description = (string) (($response['json']['error_description'] ?? '') ?: ($response['json']['error'] ?? 'アクセストークンの取得に失敗しました。'));
        throw new RuntimeException($description);
    }

    $accessToken = (string) ($response['json']['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('アクセストークンの取得結果が不正です。');
    }

    return $accessToken;
}

function buildGmailRawMessage(string $to, string $subject, string $body, array $attachments = []): string
{
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
        'From: ' . MAIL_SENDER,
        'To: ' . $to,
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
    ];

    if ($attachments === []) {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $raw = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    $boundary = 'boundary_' . bin2hex(random_bytes(12));
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    $parts = [];
    $parts[] = '--' . $boundary;
    $parts[] = 'Content-Type: text/plain; charset=UTF-8';
    $parts[] = 'Content-Transfer-Encoding: base64';
    $parts[] = '';
    $parts[] = trim(chunk_split(base64_encode($body)));

    foreach ($attachments as $attachment) {
        $fileName = (string) ($attachment['file_name'] ?? '');
        $fileContent = $attachment['content'] ?? '';
        if ($fileName === '' || !is_string($fileContent) || $fileContent === '') {
            continue;
        }
        $mimeType = (string) ($attachment['mime_type'] ?? 'application/octet-stream');
        $encodedFileName = '=?UTF-8?B?' . base64_encode($fileName) . '?=';
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: ' . $mimeType . '; name="' . $encodedFileName . '"';
        $parts[] = 'Content-Disposition: attachment; filename="' . $encodedFileName . '"';
        $parts[] = 'Content-Transfer-Encoding: base64';
        $parts[] = '';
        $parts[] = trim(chunk_split(base64_encode($fileContent)));
    }

    $parts[] = '--' . $boundary . '--';
    $raw = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts) . "\r\n";
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function sendWithGmailApi(string $to, string $subject, string $body, array $attachments = []): void
{
    $accessToken = getGmailAccessToken();
    $payload = json_encode([
        'raw' => buildGmailRawMessage($to, $subject, $body, $attachments),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('メール送信ペイロードの作成に失敗しました。');
    }

    $response = requestJson(
        'https://gmail.googleapis.com/gmail/v1/users/' . rawurlencode(MAIL_SENDER) . '/messages/send',
        [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        $payload
    );

    if (($response['status'] ?? 500) >= 300) {
        $message = (string) (($response['json']['error']['message'] ?? '') ?: 'Gmail APIでの送信に失敗しました。');
        throw new RuntimeException($message);
    }
}

$sheetId = trim((string) ($_GET['sheet_id'] ?? ''));
if ($sheetId === '') {
    http_response_code(400);
    echo 'sheet_id が指定されていません。';
    exit;
}

$returnPage = max(1, (int) ($_GET['page'] ?? 1));
$returnFrom = getOptionalQuery('from');
$returnName = getOptionalQuery('name');
$returnVideoStaff = getOptionalQuery('video_staff');
$returnSalesStaff = getOptionalQuery('sales_staff');
$requestManagementId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$requestManagementId = $requestManagementId !== false && $requestManagementId !== null ? (int) $requestManagementId : null;
$indexBackParams = ['page' => $returnPage];
if ($returnFrom === 'request_management') {
    $indexBackUrl = 'request_management.php?' . http_build_query($indexBackParams);
} else {
    if ($returnName !== null) {
        $indexBackParams['name'] = $returnName;
    }
    if ($returnVideoStaff !== null) {
        $indexBackParams['video_staff'] = $returnVideoStaff;
    }
    if ($returnSalesStaff !== null) {
        $indexBackParams['sales_staff'] = $returnSalesStaff;
    }
    $indexBackUrl = 'index.php?' . http_build_query($indexBackParams);
}

$detailBaseParams = ['sheet_id' => $sheetId, 'page' => $returnPage];
if ($returnFrom !== null) {
    $detailBaseParams['from'] = $returnFrom;
}
if ($returnName !== null) {
    $detailBaseParams['name'] = $returnName;
}
if ($returnVideoStaff !== null) {
    $detailBaseParams['video_staff'] = $returnVideoStaff;
}
if ($returnSalesStaff !== null) {
    $detailBaseParams['sales_staff'] = $returnSalesStaff;
}
if ($requestManagementId !== null) {
    $detailBaseParams['request_id'] = (string) $requestManagementId;
}

function buildDetailUrl(array $extraParams = [], string $anchor = ''): string
{
    global $detailBaseParams;

    $query = array_merge($detailBaseParams, $extraParams);
    $url = 'detail.php?' . http_build_query($query);
    if ($anchor !== '') {
        $url .= '#' . ltrim($anchor, '#');
    }

    return $url;
}
function tableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1'
    );
    $stmt->bindValue(':schema', DB_NAME);
    $stmt->bindValue(':table_name', $tableName);
    $stmt->bindValue(':column_name', $columnName);
    $stmt->execute();

    return (bool) $stmt->fetchColumn();
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = :schema
           AND TABLE_NAME = :table_name
         LIMIT 1'
    );
    $stmt->bindValue(':schema', DB_NAME);
    $stmt->bindValue(':table_name', $tableName);
    $stmt->execute();

    return (bool) $stmt->fetchColumn();
}

$pdo = db();

$recordStmt = $pdo->prepare('SELECT * FROM customer_sales_records WHERE sheet_id = :sheet_id LIMIT 1');
$recordStmt->bindValue(':sheet_id', $sheetId);
$recordStmt->execute();
$record = $recordStmt->fetch();

if (!$record) {
    http_response_code(404);
    echo '対象の顧客レコードが見つかりません。';
    exit;
}

$requestManagementInfo = null;
if ($requestManagementId !== null && tableExists($pdo, 'request_management')) {
    $requiredRequestManagementColumns = ['sheet_id', 'request_type', 'document_type', 'is_completed', 'created_at'];
    $hasRequiredColumns = true;
    foreach ($requiredRequestManagementColumns as $requiredRequestManagementColumn) {
        if (!tableHasColumn($pdo, 'request_management', $requiredRequestManagementColumn)) {
            $hasRequiredColumns = false;
            break;
        }
    }

    if ($hasRequiredColumns) {
        $hasRequestManagementMemoColumn = tableHasColumn($pdo, 'request_management', 'memo');
        $hasRequestManagementSendDateColumn = tableHasColumn($pdo, 'request_management', 'send_date');
        $requestManagementSelectColumns = 'id, sheet_id, request_type, document_type, is_completed, created_at';
        if ($hasRequestManagementSendDateColumn) {
            $requestManagementSelectColumns .= ', send_date';
        }
        if ($hasRequestManagementMemoColumn) {
            $requestManagementSelectColumns .= ', memo';
        }
        $requestManagementStmt = $pdo->prepare(
            'SELECT ' . $requestManagementSelectColumns . '
             FROM request_management
             WHERE id = :id
             LIMIT 1'
        );
        $requestManagementStmt->bindValue(':id', $requestManagementId, PDO::PARAM_INT);
        $requestManagementStmt->execute();
        $fetchedRequestManagementInfo = $requestManagementStmt->fetch();
        if (is_array($fetchedRequestManagementInfo) && (string) ($fetchedRequestManagementInfo['sheet_id'] ?? '') === $sheetId) {
            $requestManagementInfo = $fetchedRequestManagementInfo;
        }
    }
}
$pdo->exec('CREATE TABLE IF NOT EXISTS email_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_name VARCHAR(255) NOT NULL,
    mail_subject VARCHAR(255) NOT NULL,
    mail_body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
ensureCustomerTagTables($pdo);

ensureRefundGuaranteeTable($pdo);

$pdo->exec('CREATE TABLE IF NOT EXISTS customer_sales_record_email_drafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_sales_record_id VARCHAR(100) NOT NULL,
    email_template_id BIGINT UNSIGNED NULL,
    mail_subject VARCHAR(255) NULL,
    mail_body TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_customer_sales_record_id (customer_sales_record_id),
    CONSTRAINT fk_customer_sales_record_email_drafts_record_id
        FOREIGN KEY (customer_sales_record_id)
        REFERENCES customer_sales_records (sheet_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_customer_sales_record_email_drafts_template_id
        FOREIGN KEY (email_template_id)
        REFERENCES email_templates (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$pdo->exec('CREATE TABLE IF NOT EXISTS customer_sales_record_email_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_sales_record_id VARCHAR(100) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_sales_record_email_attachments_record_id (customer_sales_record_id),
    CONSTRAINT fk_customer_sales_record_email_attachments_record_id
        FOREIGN KEY (customer_sales_record_id)
        REFERENCES customer_sales_records (sheet_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$pdo->exec('CREATE TABLE IF NOT EXISTS customer_sales_record_email_send_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_sales_record_id VARCHAR(100) NOT NULL,
    email_template_id BIGINT UNSIGNED NULL,
    mail_subject VARCHAR(255) NOT NULL,
    mail_body TEXT NOT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_sales_record_email_send_logs_record_id (customer_sales_record_id),
    CONSTRAINT fk_customer_sales_record_email_send_logs_record_id
        FOREIGN KEY (customer_sales_record_id)
        REFERENCES customer_sales_records (sheet_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_customer_sales_record_email_send_logs_template_id
        FOREIGN KEY (email_template_id)
        REFERENCES email_templates (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$pdo->exec('CREATE TABLE IF NOT EXISTS customer_memo (
    sheet_id VARCHAR(100) NOT NULL,
    memo TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sheet_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

if (!tableHasColumn($pdo, 'customer_sales_record_email_drafts', 'mail_to')) {
    $pdo->exec('ALTER TABLE customer_sales_record_email_drafts ADD COLUMN mail_to VARCHAR(255) NULL AFTER email_template_id');
}
if (!tableHasColumn($pdo, 'customer_sales_record_email_send_logs', 'mail_to')) {
    $pdo->exec('ALTER TABLE customer_sales_record_email_send_logs ADD COLUMN mail_to VARCHAR(255) NOT NULL AFTER email_template_id');
}

$pdo->exec('CREATE TABLE IF NOT EXISTS chatwork_mention_masters (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    chatwork_id VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

if (!tableHasColumn($pdo, 'email_templates', 'chatwork_message_template')) {
    $pdo->exec('ALTER TABLE email_templates ADD COLUMN chatwork_message_template TEXT NULL AFTER mail_body');
}
if (!tableHasColumn($pdo, 'email_templates', 'chatwork_mention_ids')) {
    $pdo->exec('ALTER TABLE email_templates ADD COLUMN chatwork_mention_ids VARCHAR(255) NULL AFTER chatwork_message_template');
}
$recordSheetId = (string) ($record['sheet_id'] ?? $sheetId);
$draftStmt = $pdo->prepare('SELECT email_template_id, mail_to, mail_subject, mail_body FROM customer_sales_record_email_drafts WHERE customer_sales_record_id = :record_id LIMIT 1');

$mentionMasterStmt = $pdo->query('SELECT id, name, chatwork_id FROM chatwork_mention_masters ORDER BY id ASC');
$mentionMasters = $mentionMasterStmt->fetchAll();
$mentionMastersByName = [];
foreach ($mentionMasters as $mentionMaster) {
    $mentionName = trim((string) ($mentionMaster['name'] ?? ''));
    if ($mentionName === '') {
        continue;
    }
    if (!isset($mentionMastersByName[$mentionName])) {
        $mentionMastersByName[$mentionName] = [];
    }
    $mentionMastersByName[$mentionName][] = $mentionMaster;
}
$actorMastersByName = [];
if (tableExists($pdo, 'actor_table') && tableHasColumn($pdo, 'actor_table', 'name') && tableHasColumn($pdo, 'actor_table', 'actor_name')) {
    $actorMasterStmt = $pdo->query('SELECT name, actor_name FROM actor_table');
    $actorMasters = $actorMasterStmt->fetchAll();
    foreach ($actorMasters as $actorMaster) {
        $actorNameKey = trim((string) ($actorMaster['name'] ?? ''));
        if ($actorNameKey === '') {
            continue;
        }
        if (!isset($actorMastersByName[$actorNameKey])) {
            $actorMastersByName[$actorNameKey] = [];
        }
        $actorMastersByName[$actorNameKey][] = $actorMaster;
    }
}
$draftStmt->bindValue(':record_id', $recordSheetId);
$draftStmt->execute();
$existingDraft = $draftStmt->fetch() ?: [];

$attachmentStmt = $pdo->prepare('SELECT id, file_name, file_path, created_at FROM customer_sales_record_email_attachments WHERE customer_sales_record_id = :record_id ORDER BY created_at DESC, id DESC');
$attachmentStmt->bindValue(':record_id', $recordSheetId);
$attachmentStmt->execute();
$existingAttachments = $attachmentStmt->fetchAll();
$defaultMailTo = FALLBACK_MAIL_TO;

$defaultMailTo = '';
if (filter_var((string) ($record['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
    $defaultMailTo = (string) ($record['email'] ?? '');
}
$sheetIdForMemo = $sheetId;
$memoStmt = $pdo->prepare('SELECT memo FROM customer_memo WHERE sheet_id = :sheet_id LIMIT 1');
$memoValue = '';
$memoStmt->bindValue(':sheet_id', $sheetIdForMemo);
$memoStmt->execute();
$memoRow = $memoStmt->fetch();
$memoValue = (string) ($memoRow['memo'] ?? '');
$memoError = '';
$tagMaster = fetchCustomerTagMaster($pdo);
$assignedTags = fetchCustomerTagsBySheetId($pdo, $sheetId);
$refundGuaranteeStatuses = fetchRefundGuaranteeStatuses($pdo, $recordSheetId);

$errors = [];
$currentAction = (string) ($_POST['action'] ?? '');
$formValues = [
    'writing' => '',
    'writing_notes' => '',
    'email_template_id' => isset($existingDraft['email_template_id']) ? (string) $existingDraft['email_template_id'] : '',
    'mail_to' => (string) ($existingDraft['mail_to'] ?? $defaultMailTo),
    'mail_subject' => (string) ($existingDraft['mail_subject'] ?? ''),
    'mail_body' => (string) ($existingDraft['mail_body'] ?? ''),
    'template_name' => '',
    'template_subject' => '',
    'template_body' => '',
    'template_notification_body' => DEFAULT_CHATWORK_MESSAGE_TEMPLATE,
    'template_mention_ids' => [],
    'template_id' => '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    $writingId = (int) ($_POST['writing_id'] ?? 0);
    $writing = trim((string) ($_POST['writing'] ?? ''));
    $writingNotes = trim((string) ($_POST['writing_notes'] ?? ''));
    $formValues['writing'] = $writing;
    $formValues['writing_notes'] = $writingNotes;
    $audioFile = $_FILES['audio_file'] ?? null;
    $recordSheetId = (string) ($record['sheet_id'] ?? $sheetId);
    $isUpdate = $action === 'update';
    $isDelete = $action === 'delete';
    $isWritingAction = $action === 'create' || $isUpdate || $isDelete;
    $targetWriting = null;
    $fileName = '';

    if ($action === 'save_memo') {
        $memoInput = trim((string) ($_POST['memo'] ?? ''));
        $memoValue = $memoInput;
        if ($memoInput === '') {
            $memoError = 'メモを入力してください。';
        } else {
            $memoExistsStmt = $pdo->prepare('SELECT 1 FROM customer_memo WHERE sheet_id = :sheet_id LIMIT 1');
            $memoExistsStmt->bindValue(':sheet_id', $sheetIdForMemo);
            $memoExistsStmt->execute();
            $memoExists = (bool) $memoExistsStmt->fetchColumn();

            if ($memoExists) {
                $updateMemoStmt = $pdo->prepare('UPDATE customer_memo SET memo = :memo WHERE sheet_id = :sheet_id');
                $updateMemoStmt->bindValue(':sheet_id', $sheetIdForMemo);
                $updateMemoStmt->bindValue(':memo', $memoInput);
                $updateMemoStmt->execute();
            } else {
                $insertMemoStmt = $pdo->prepare('INSERT INTO customer_memo (sheet_id, memo) VALUES (:sheet_id, :memo)');
                $insertMemoStmt->bindValue(':sheet_id', $sheetIdForMemo, PDO::PARAM_INT);
                $insertMemoStmt->bindValue(':sheet_id', $sheetIdForMemo);
                $insertMemoStmt->execute();
            }
            header('Location: ' . buildDetailUrl(['memo_saved' => '1', 'refresh' => (string) time()], 'customer-memo'));
            exit;
        }
    } elseif ($action === 'template_create' || $action === 'template_update' || $action === 'template_delete') {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $templateName = trim((string) ($_POST['template_name'] ?? ''));
        $templateSubject = trim((string) ($_POST['template_subject'] ?? ''));
        $templateBody = trim((string) ($_POST['template_body'] ?? ''));
        $templateNotificationBody = trim((string) ($_POST['template_notification_body'] ?? ''));
        $templateMentionIds = array_values(array_filter(array_map('intval', (array) ($_POST['template_mention_ids'] ?? [])), static fn ($value) => $value > 0));
        $formValues['template_id'] = (string) $templateId;
        $formValues['template_name'] = $templateName;
        $formValues['template_subject'] = $templateSubject;
        $formValues['template_body'] = $templateBody;
        $formValues['template_notification_body'] = $templateNotificationBody !== '' ? $templateNotificationBody : DEFAULT_CHATWORK_MESSAGE_TEMPLATE;
        $formValues['template_mention_ids'] = array_map('strval', $templateMentionIds);

        if ($action === 'template_delete') {
            if ($templateId <= 0) {
                $errors[] = '削除対象テンプレートが不正です。';
            } else {
                $deleteTemplateStmt = $pdo->prepare('DELETE FROM email_templates WHERE id = :id');
                $deleteTemplateStmt->bindValue(':id', $templateId, PDO::PARAM_INT);
                $deleteTemplateStmt->execute();
                header('Location: ' . buildDetailUrl(['template_deleted' => '1', 'refresh' => (string) time()], 'email-compose'));
                exit;
            }
        } else {
            if ($templateName === '' || $templateSubject === '' || $templateBody === '') {
                $errors[] = 'テンプレート名・件名・本文は必須です。';
            } elseif ($templateNotificationBody === '') {
                $errors[] = '通知本文を入力してください。';
            } elseif ($action === 'template_create') {
                $createTemplateStmt = $pdo->prepare('INSERT INTO email_templates (template_name, mail_subject, mail_body, chatwork_message_template, chatwork_mention_ids) VALUES (:template_name, :mail_subject, :mail_body, :chatwork_message_template, :chatwork_mention_ids)');
                $createTemplateStmt->bindValue(':template_name', $templateName);
                $createTemplateStmt->bindValue(':mail_subject', $templateSubject);
                $createTemplateStmt->bindValue(':mail_body', $templateBody);
                $createTemplateStmt->bindValue(':chatwork_message_template', $templateNotificationBody);
                $createTemplateStmt->bindValue(':chatwork_mention_ids', $templateMentionIds !== [] ? implode(',', $templateMentionIds) : null, $templateMentionIds !== [] ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $createTemplateStmt->execute();
                header('Location: ' . buildDetailUrl(['template_saved' => '1', 'refresh' => (string) time()], 'email-compose'));
                exit;
            } else {
                if ($templateId <= 0) {
                    $errors[] = '更新対象テンプレートが不正です。';
                } else {
                    $updateTemplateStmt = $pdo->prepare('UPDATE email_templates SET template_name = :template_name, mail_subject = :mail_subject, mail_body = :mail_body, chatwork_message_template = :chatwork_message_template, chatwork_mention_ids = :chatwork_mention_ids WHERE id = :id');
                    $updateTemplateStmt->bindValue(':template_name', $templateName);
                    $updateTemplateStmt->bindValue(':mail_subject', $templateSubject);
                    $updateTemplateStmt->bindValue(':mail_body', $templateBody);
                    $updateTemplateStmt->bindValue(':chatwork_message_template', $templateNotificationBody);
                    $updateTemplateStmt->bindValue(':chatwork_mention_ids', $templateMentionIds !== [] ? implode(',', $templateMentionIds) : null, $templateMentionIds !== [] ? PDO::PARAM_STR : PDO::PARAM_NULL);
                    $updateTemplateStmt->bindValue(':id', $templateId, PDO::PARAM_INT);
                    $updateTemplateStmt->execute();
                    header('Location: ' . buildDetailUrl(['template_saved' => '1', 'refresh' => (string) time()], 'email-compose'));
                    exit;
                }
            }
        }
    } elseif ($action === 'save_email_draft' || $action === 'send_email') {
        $templateId = (int) ($_POST['email_template_id'] ?? 0);
        $mailTo = trim((string) ($_POST['mail_to'] ?? ''));
        $mailSubject = trim((string) ($_POST['mail_subject'] ?? ''));
        $mailBody = trim((string) ($_POST['mail_body'] ?? ''));
        $slideConfirmed = (string) ($_POST['slide_confirmed'] ?? '');
        $mailAttachments = $_FILES['mail_attachments'] ?? null;
        $formValues['email_template_id'] = $templateId > 0 ? (string) $templateId : '';
        $formValues['mail_to'] = $mailTo;
        $formValues['mail_subject'] = $mailSubject;
        $formValues['mail_body'] = $mailBody;

        if ($mailTo === '') {
            $errors[] = '宛先メールアドレスを入力してください。';
        } elseif (!filter_var($mailTo, FILTER_VALIDATE_EMAIL)) {
            $errors[] = '宛先メールアドレスの形式が不正です。';
        }
        if ($mailSubject === '' || $mailBody === '') {
            $errors[] = '件名と本文を入力してください。';
        }

        if ($action === 'send_email' && $slideConfirmed !== '1') {
            $errors[] = '送信スライドを完了してください。';
        }

        $attachmentUploadRows = [];
        if (is_array($mailAttachments) && is_array($mailAttachments['name'] ?? null)) {
            $maxAttachmentBytes = 20 * 1024 * 1024;
            $uploadCount = count($mailAttachments['name']);
            for ($i = 0; $i < $uploadCount; $i++) {
                $errorCode = (int) ($mailAttachments['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($errorCode === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($errorCode !== UPLOAD_ERR_OK) {
                    $errors[] = '添付ファイルのアップロードに失敗しました。';
                    break;
                }

                $originalName = trim((string) ($mailAttachments['name'][$i] ?? ''));
                $tmpName = (string) ($mailAttachments['tmp_name'][$i] ?? '');
                $size = (int) ($mailAttachments['size'][$i] ?? 0);

                if ($originalName === '' || $tmpName === '') {
                    $errors[] = '添付ファイルの情報が不正です。';
                    break;
                }
                if ($size > $maxAttachmentBytes) {
                    $errors[] = sprintf('添付ファイル「%s」は20MB以下にしてください。', $originalName);
                    break;
                }
                $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
                $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                $sanitizedBaseName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $baseName) ?: 'file';
                $storedFileName = sprintf('%s_%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(4)), $sanitizedBaseName, $extension ?: 'dat');

                $attachmentUploadRows[] = [
                    'tmp_name' => $tmpName,
                    'stored_file_name' => $storedFileName,
                    'original_name' => $originalName,
                ];
            }
        }
        if ($errors === []) {
            $upsertDraftStmt = $pdo->prepare(
                'INSERT INTO customer_sales_record_email_drafts (customer_sales_record_id, email_template_id, mail_to, mail_subject, mail_body)
                 VALUES (:record_id, :template_id, :mail_to, :mail_subject, :mail_body)
                 ON DUPLICATE KEY UPDATE email_template_id = VALUES(email_template_id), mail_to = VALUES(mail_to), mail_subject = VALUES(mail_subject), mail_body = VALUES(mail_body)'
            );
            $upsertDraftStmt->bindValue(':record_id', $recordSheetId);
            $upsertDraftStmt->bindValue(':template_id', $templateId > 0 ? $templateId : null, $templateId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $upsertDraftStmt->bindValue(':mail_to', $mailTo);
            $upsertDraftStmt->bindValue(':mail_subject', $mailSubject);
            $upsertDraftStmt->bindValue(':mail_body', $mailBody);
            $upsertDraftStmt->execute();

            if ($attachmentUploadRows !== []) {
                $attachmentUploadDir = __DIR__ . '/uploads/email_attachments';
                if (!is_dir($attachmentUploadDir) && !mkdir($attachmentUploadDir, 0777, true) && !is_dir($attachmentUploadDir)) {
                    $errors[] = '添付ファイル保存先フォルダの作成に失敗しました。';
                } else {
                    $insertAttachmentStmt = $pdo->prepare(
                        'INSERT INTO customer_sales_record_email_attachments (customer_sales_record_id, file_name, file_path)
                         VALUES (:record_id, :file_name, :file_path)'
                    );

                    foreach ($attachmentUploadRows as $attachmentUploadRow) {
                        $destination = $attachmentUploadDir . '/' . $attachmentUploadRow['stored_file_name'];
                        if (!move_uploaded_file($attachmentUploadRow['tmp_name'], $destination)) {
                            $errors[] = sprintf('添付ファイル「%s」の保存に失敗しました。', $attachmentUploadRow['original_name']);
                            break;
                        }

                        $insertAttachmentStmt->bindValue(':record_id', $recordSheetId);
                        $insertAttachmentStmt->bindValue(':file_name', $attachmentUploadRow['original_name']);
                        $insertAttachmentStmt->bindValue(':file_path', 'uploads/email_attachments/' . $attachmentUploadRow['stored_file_name']);
                        $insertAttachmentStmt->execute();
                    }
                }
            }

            if ($errors !== []) {
                $attachmentStmt->execute();
                $existingAttachments = $attachmentStmt->fetchAll();
            }

            if ($errors === [] && $action === 'send_email') {
                $selectedTemplate = null;
                if ($templateId > 0) {
                    $templateForNotificationStmt = $pdo->prepare('SELECT template_name, chatwork_message_template, chatwork_mention_ids FROM email_templates WHERE id = :id LIMIT 1');
                    $templateForNotificationStmt->bindValue(':id', $templateId, PDO::PARAM_INT);
                    $templateForNotificationStmt->execute();
                    $selectedTemplate = $templateForNotificationStmt->fetch() ?: null;
                }
                $notificationTemplate = trim((string) ($selectedTemplate['chatwork_message_template'] ?? ''));
                if ($notificationTemplate === '') {
                    $notificationTemplate = DEFAULT_CHATWORK_MESSAGE_TEMPLATE;
                }

                $notificationMessage = '';
                try {
                    $notificationMessage = renderChatworkMessageTemplate($notificationTemplate, [
                        'template_name' => (string) ($selectedTemplate['template_name'] ?? ''),
                        'sales_staff' => (string) ($record['sales_staff'] ?? ''),
                        'full_name' => (string) ($record['full_name'] ?? ''),
                        'line_name' => (string) ($record['line_name'] ?? ''),
                        'video_staff' => (string) ($record['video_staff'] ?? ''),
                    ], $mentionMastersByName, $actorMastersByName);
                } catch (Throwable $chatworkTemplateError) {
                    $errors[] = 'Chatwork通知テンプレートに不備があります。' . $chatworkTemplateError->getMessage();
                }

                if ($errors !== []) {
                    $attachmentStmt->execute();
                    $existingAttachments = $attachmentStmt->fetchAll();
                }
            }
            if ($errors === [] && $action === 'send_email') {
                $attachmentsForSendStmt = $pdo->prepare(
                    'SELECT file_name, file_path FROM customer_sales_record_email_attachments WHERE customer_sales_record_id = :record_id ORDER BY created_at ASC, id ASC'
                );
                $attachmentsForSendStmt->bindValue(':record_id', $recordSheetId);
                $attachmentsForSendStmt->execute();
                $attachmentsForSendRows = $attachmentsForSendStmt->fetchAll();
                $attachmentsForSend = [];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                foreach ($attachmentsForSendRows as $attachmentRow) {
                    $fileName = (string) ($attachmentRow['file_name'] ?? '');
                    $relativePath = (string) ($attachmentRow['file_path'] ?? '');
                    $absolutePath = __DIR__ . '/' . ltrim($relativePath, '/');
                    if ($fileName === '' || $relativePath === '' || !is_file($absolutePath)) {
                        continue;
                    }
                    $content = file_get_contents($absolutePath);
                    if (!is_string($content) || $content === '') {
                        continue;
                    }
                    $mimeType = $finfo !== false ? (string) finfo_file($finfo, $absolutePath) : '';
                    if ($mimeType === '') {
                        $mimeType = 'application/octet-stream';
                    }
                    $attachmentsForSend[] = [
                        'file_name' => $fileName,
                        'content' => $content,
                        'mime_type' => $mimeType,
                    ];
                }
                if ($finfo !== false) {
                    finfo_close($finfo);
                }

                try {
                    sendWithGmailApi($mailTo, $mailSubject, $mailBody, $attachmentsForSend);
                } catch (Throwable $e) {
                    $errors[] = 'メール送信に失敗しました。' . $e->getMessage();
                }
            }

            if ($errors === [] && $action === 'send_email') {
                $sendLogStmt = $pdo->prepare(
                    'INSERT INTO customer_sales_record_email_send_logs (customer_sales_record_id, email_template_id, mail_to, mail_subject, mail_body)
                     VALUES (:record_id, :template_id, :mail_to, :mail_subject, :mail_body)'
                );
                $sendLogStmt->bindValue(':record_id', $recordSheetId);
                $sendLogStmt->bindValue(':template_id', $templateId > 0 ? $templateId : null, $templateId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $sendLogStmt->bindValue(':mail_to', $mailTo);
                $sendLogStmt->bindValue(':mail_subject', $mailSubject);
                $sendLogStmt->bindValue(':mail_body', $mailBody);
                $sendLogStmt->execute();

                if ($requestManagementId !== null && $requestManagementInfo !== null) {
                    $requestCompletedStmt = $pdo->prepare(
                        'UPDATE request_management
                         SET is_completed = 1
                         WHERE id = :id
                           AND sheet_id = :sheet_id'
                    );
                    $requestCompletedStmt->bindValue(':id', $requestManagementId, PDO::PARAM_INT);
                    $requestCompletedStmt->bindValue(':sheet_id', $recordSheetId);
                    $requestCompletedStmt->execute();
                }

                try {
                    $requestTypeForMailCompletion = trim((string) ($requestManagementInfo['request_type'] ?? ''));
                    if ($requestTypeForMailCompletion === 'サポート面談') {
                        appendSupportInterviewMailCompletedForSupportInterview($record);
                    } else {
                        appendSupportInterviewMailCompletedToSheet($record);
                    }
                } catch (Throwable $e) {
                    $errors[] = '送付メール完了のスプレッドシート連携に失敗しました。' . $e->getMessage();
                }
                try {
                    sendChatworkNotification($notificationMessage);
                } catch (Throwable $chatworkError) {
                    error_log('Chatwork通知エラー: ' . $chatworkError->getMessage());
                }
                if ($errors === []) {
                    header('Location: ' . buildDetailUrl(['mail_sent' => '1', 'refresh' => (string) time()], 'email-compose'));
                    exit;
                }
            }

            if ($errors === []) {
                header('Location: ' . buildDetailUrl(['draft_saved' => '1', 'refresh' => (string) time()], 'email-compose'));
                exit;
            }
        }
    }

    if ($isWritingAction && ($isUpdate || $isDelete) && $writingId <= 0) {
        $errors[] = '対象データが不正です。';
    }

    if ($isWritingAction && ($isUpdate || $isDelete) && $errors === []) {
        $targetStmt = $pdo->prepare('SELECT id, file_name FROM customer_sales_record_writings WHERE id = :id AND sheet_id = :sheet_id LIMIT 1');
        $targetStmt->bindValue(':id', $writingId, PDO::PARAM_INT);
        $targetStmt->bindValue(':sheet_id', $recordSheetId);
        $targetStmt->execute();
        $targetWriting = $targetStmt->fetch();

        if (!$targetWriting) {
            $errors[] = '対象のwritingデータが見つかりません。';
        } else {
            $fileName = (string) ($targetWriting['file_name'] ?? '');
        }
    }

    if ($isWritingAction && $isDelete && $errors === []) {
        $deleteStmt = $pdo->prepare('DELETE FROM customer_sales_record_writings WHERE id = :id AND sheet_id = :sheet_id');
        $deleteStmt->bindValue(':id', $writingId, PDO::PARAM_INT);
        $deleteStmt->bindValue(':sheet_id', $recordSheetId);
        $deleteStmt->execute();

        header('Location: ' . buildDetailUrl(['deleted' => '1', 'refresh' => (string) time()], 'writing-list'));
        exit;
    }

    if ($isWritingAction && !$isDelete) {
        $isNoFile = !is_array($audioFile) || (int) ($audioFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;
        if (!$isUpdate && $isNoFile) {
            $errors[] = '音声ファイルは必須です。';
        } elseif (!$isNoFile && (int) $audioFile['error'] !== UPLOAD_ERR_OK) {
            $errors[] = '音声ファイルのアップロードに失敗しました。';
        } elseif (!$isNoFile) {
            $originalName = (string) ($audioFile['name'] ?? '');
            $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExtensions = ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac', 'webm', 'mp4'];
            if ($extension !== '' && !in_array($extension, $allowedExtensions, true)) {
                $errors[] = '対応していない音声ファイル形式です。';
            }

            $mimeType = (string) ($audioFile['type'] ?? '');
            if ($mimeType !== '' && strpos($mimeType, 'audio/') !== 0) {
                $errors[] = '音声ファイルを選択してください。';
            }

            $maxBytes = 100 * 1024 * 1024;
            if ((int) ($audioFile['size'] ?? 0) > $maxBytes) {
                $errors[] = '音声ファイルサイズは100MB以下にしてください。';
            }

            if ($errors === []) {
                $uploadDir = __DIR__ . '/uploads/audio';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                    $errors[] = '保存先フォルダの作成に失敗しました。';
                } else {
                    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                    $sanitizedBaseName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $baseName) ?: 'audio';
                    $storedFileName = sprintf('%s_%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(4)), $sanitizedBaseName, $extension ?: 'dat');
                    $destination = $uploadDir . '/' . $storedFileName;

                    if (!move_uploaded_file((string) ($audioFile['tmp_name'] ?? ''), $destination)) {
                        $errors[] = '音声ファイルの保存に失敗しました。';
                    } else {
                        $fileName = 'uploads/audio/' . $storedFileName;
                    }
                }
            }
        }
    }

    if ($isWritingAction && $errors === []) {
        if ($isUpdate) {
            $updateStmt = $pdo->prepare('UPDATE customer_sales_record_writings SET file_name = :file_name, writing = :writing, writing_notes = :writing_notes WHERE id = :id AND sheet_id = :sheet_id');
            $updateStmt->bindValue(':file_name', $fileName);
            $updateStmt->bindValue(':writing', $writing === '' ? null : $writing);
            $updateStmt->bindValue(':writing_notes', $writingNotes === '' ? null : $writingNotes);
            $updateStmt->bindValue(':id', $writingId, PDO::PARAM_INT);
            $updateStmt->bindValue(':sheet_id', $recordSheetId);
            $updateStmt->execute();
            try {
                appendSupportInterviewRecordToSheet($record, [
                    'id' => $writingId,
                    'file_name' => $fileName,
                    'writing' => $writing,
                    'writing_notes' => $writingNotes,
                ], 'update');
            } catch (Throwable $e) {
                $errors[] = 'サポート面談記録のスプレッドシート連携に失敗しました。' . $e->getMessage();
            }
            $noticeParam = 'updated=1';
        } else {
            $insertStmt = $pdo->prepare('INSERT INTO customer_sales_record_writings (sheet_id, file_name, writing, writing_notes) VALUES (:sheet_id, :file_name, :writing, :writing_notes)');
            $insertStmt->bindValue(':sheet_id', $recordSheetId);
            $insertStmt->bindValue(':file_name', $fileName);
            $insertStmt->bindValue(':writing', $writing === '' ? null : $writing);
            $insertStmt->bindValue(':writing_notes', $writingNotes === '' ? null : $writingNotes);
            $insertStmt->execute();
            $createdWritingId = (int) $pdo->lastInsertId();
            try {
                appendSupportInterviewRecordToSheet($record, [
                    'id' => $createdWritingId,
                    'file_name' => $fileName,
                    'writing' => $writing,
                    'writing_notes' => $writingNotes,
                ], 'create');
            } catch (Throwable $e) {
                $errors[] = 'サポート面談記録のスプレッドシート連携に失敗しました。' . $e->getMessage();
            }
            $noticeParam = 'saved=1';
        }

        if ($errors === []) {
            header('Location: ' . buildDetailUrl([$noticeParam => '1', 'refresh' => (string) time()], 'writing-list'));
            exit;
        }
    }
}

$writingsStmt = $pdo->prepare('SELECT id, file_name, writing, writing_notes, updated_at FROM customer_sales_record_writings WHERE sheet_id = :sheet_id ORDER BY updated_at DESC');
$writingsStmt->bindValue(':sheet_id', $recordSheetId);
$writingsStmt->execute();
$writings = $writingsStmt->fetchAll();

$templatesStmt = $pdo->query('SELECT id, template_name, mail_subject, mail_body, chatwork_message_template, chatwork_mention_ids, updated_at FROM email_templates ORDER BY updated_at DESC, id DESC');
$emailTemplates = $templatesStmt !== false ? $templatesStmt->fetchAll() : [];
$customerFields = [
    'sheet_id' => 'シートID',
    'serial_no' => '通し番号',
    'sales_year_month' => '売上年月',
    'payment_year_month' => '入金年月',
    'full_name' => '氏名',
    'system_name' => 'システム名',
    'entry_point' => '流入経路',
    'status' => 'ステータス',
    'line_name' => 'LINE名',
    'phone_number' => '電話番号',
    'email' => 'メールアドレス',
    'sales_date' => 'セールス日',
    'payment_date' => '入金日',
    'expected_payment_amount' => '見込み入金額',
    'payment_amount' => '入金額',
    'payment_installment_no' => '分割回数',
    'login_id' => 'ログインID',
    'payment_destination' => '振込先',
    'video_staff' => '演者',
    'sales_staff' => 'セールス担当',
    'acquisition_channel' => '獲得チャネル',
    'age' => '年齢',
    'system_delivery_status' => 'システム納品状況',
    'notes' => '備考',
    'payment_week' => '入金週',
    'data1' => 'data1',
    'data2' => 'data2',
    'line_registered_date' => 'LINE登録日',
    'gender' => '性別',
    'created_at' => '作成日時',
    'updated_at' => '更新日時',
];

$pageTitle = 'SUPSUP-NEO 顧客詳細';
$showImportButton = true;
$importCompletedAt = trim((string) ($_GET['import_completed_at'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $importCompletedAt)) {
    $importCompletedAt = '';
}
require 'header.php';
?>
<div class="glass-board" aria-hidden="true" style="display:none;"></div>
<div class="dashboard-shell panel dashboard-shell--detail">
  <aside class="side-panel">
    <div class="avatar-frame" data-sidebar-avatar-frame>
      <span class="avatar-frame-flash" aria-hidden="true" data-sidebar-avatar-flash></span>
      <img class="avatar" src="img/human.png" alt="顧客詳細アイコン" loading="lazy" data-sidebar-avatar>
    </div>
    <h1>Customer Detail</h1>
    <p><?= h((string) ($record['line_name'] ?? '名称未設定')); ?></p>

    <nav class="side-nav" aria-label="メニュー">
      <a href="<?= h($indexBackUrl); ?>">一覧へ戻る</a>
      <!-- <a href="#customer-info">顧客情報</a>
      <a href="#writing-list">Writing一覧</a> -->
    </nav>
    <section class="memo-panel">
      <div class="memo-tagging" data-tagging-root data-sheet-id="<?= h($sheetId); ?>">
        <div class="memo-tagging-controls">
          <select data-tag-select>
            <option value="">タグを選択</option>
            <?php foreach ($tagMaster as $tag): ?>
              <option value="<?= h((string) ($tag['id'] ?? '')); ?>"><?= h((string) ($tag['name'] ?? '')); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="button_frame">
            <button type="button" class="btn btn-ghost" data-open-modal="tag-manager-modal">タグ管理</button>
            <button type="button" class="btn btn-primary" data-tag-add>追加</button>
          </div>
        </div>
        <div class="tag-badges" data-tag-badges>
          <?php if ($assignedTags === []): ?>
            <p class="tagging-empty">タグは未設定です。</p>
          <?php else: ?>
            <?php foreach ($assignedTags as $tag): ?>
              <span class="tag-badge" style="--tag-color:<?= h((string) ($tag['color'] ?? '#3b82f6')); ?>">
                <span class="tag-badge-label"><?= h((string) ($tag['name'] ?? 'タグ')); ?></span>
                <button type="button" class="tag-badge-remove" data-remove-tag-id="<?= h((string) ($tag['id'] ?? '')); ?>">×</button>
              </span>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>  
    </section>              
    <section id="customer-memo" class="memo-panel">
      <h2>メモ</h2>
      <?php if (isset($_GET['memo_saved'])): ?>
        <p class="notice memo-notice">メモを保存しました。</p>
      <?php elseif ($memoError !== ''): ?>
        <p class="error memo-notice"><?= h($memoError); ?></p>
      <?php endif; ?>
      <form method="post" class="memo-form">
        <input type="hidden" name="action" value="save_memo">
        <textarea name="memo" rows="8" placeholder="メモを入力してください。"><?= h($memoValue); ?></textarea>
        <div class="actions">
          <button type="submit" class="btn btn-primary">保存</button>
        </div>
      </form>
    </section>
  </aside>

  <section class="main-panel detail-main-panel">
    <header class="detail-page-header">
      <h2>顧客詳細</h2>
    </header>
    <div class="detail-columns">
      <section id="customer-info" class="panel content-panel detail-panel">
   
        <h2>顧客情報</h2>
        <?php if ($requestManagementInfo !== null): ?>
          <?php $requestCompleted = isset($requestManagementInfo['is_completed']) && (int) $requestManagementInfo['is_completed'] === 1; ?>
          <?php
          $sendRawDate = (string) ($requestManagementInfo['send_date'] ?? '');
          $sendDate = '';
          if ($sendRawDate !== '') {
              $timestamp = strtotime($sendRawDate);
              $sendDate = $timestamp !== false ? date('Y/m/d', $timestamp) : (string) preg_replace('/\s.+$/', '', $sendRawDate);
          }
          $requestSummaryMemo = trim((string) preg_replace('/\s+/u', ' ', (string) ($requestManagementInfo['memo'] ?? '')));
          if ($requestSummaryMemo === '') {
              $requestSummaryMemo = trim((string) preg_replace('/\s+/u', ' ', $memoValue));
          }
          ?>
          <div class="request-management-summary" aria-label="依頼内容">
            <div class="request-management-summary-main">
              <span><span class="meta-label">依頼内容</span><strong><?= h((string) ($requestManagementInfo['request_type'] ?? '')); ?></strong></span>
              <span><span class="meta-label">資料</span><strong><?= h((string) ($requestManagementInfo['document_type'] ?? '')); ?></strong></span>
              <span><span class="meta-label">状況</span><strong><?= h($requestCompleted ? '送付済' : '未送付'); ?></strong></span>
              <span><span class="meta-label">送信日</span><strong><?= h($sendDate); ?></strong></span>
            </div>
            <div class="request-management-summary-memo">
              <span class="meta-label">memo</span>
              <strong><?= h($requestSummaryMemo !== '' ? $requestSummaryMemo : '（未入力）'); ?></strong>
            </div>
          </div>
        <?php else: ?>
          <p class="meta">送付依頼一覧経由で指定された依頼がないため、依頼情報は表示していません。</p>
        <?php endif; ?>
        <div class="customer-grid">
          <?php foreach ($customerFields as $field => $label): ?>
            <article class="customer-item">
              <span><?= h($label); ?></span>
              <?php $fieldValue = (string) ($record[$field] ?? ''); ?>
              <strong class="copyable-value">
                <span><?= h($fieldValue); ?></span>
                <button
                  type="button"
                  class="copy-button"
                  data-copy-value="<?= h($fieldValue); ?>"
                  aria-label="<?= h($label); ?>をクリップボードにコピー"
                >
                  <img src="img/copy.png" alt="" loading="lazy">
                </button>
              </strong>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <div class="detail-right-stack">
        <section id="email-compose" class="panel content-panel detail-panel">
          <div class="section-head">
            <h2>メール作成</h2>
            <div class="section-head-actions">
              <button
                type="button"
                class="btn btn-icon section-toggle-button"
                data-section-toggle
                data-target-id="email-compose-body"
                aria-controls="email-compose-body"
                aria-expanded="false"
                aria-label="開ける"
              >
                <img src="img/open.png" alt="" loading="lazy" data-toggle-icon>
              </button>
            </div>
          </div>
          <div id="email-compose-body" class="collapsible-body" hidden>
            <div class="template-action-row">
              <button type="button" class="btn btn-icon" data-open-modal="template-editor-modal" aria-label="テンプレート編集">
                <img src="img/option.png" alt="" loading="lazy">
              </button>
            </div>

            <?php if (isset($_GET['template_saved'])): ?>
              <p class="notice">テンプレートを保存しました。</p>
            <?php elseif (isset($_GET['template_deleted'])): ?>
              <p class="notice">テンプレートを削除しました。</p>
            <?php elseif (isset($_GET['draft_saved'])): ?>
              <p class="notice">メール下書きを保存しました。</p>
            <?php elseif (isset($_GET['mail_sent'])): ?>
              <p class="notice">メール送信を受け付けました。</p>
            <?php endif; ?>

            <?php if ($errors !== [] && ($currentAction === 'save_email_draft' || $currentAction === 'send_email')): ?>
              <ul class="error-list">
                <?php foreach ($errors as $error): ?>
                  <li><?= h($error); ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <form method="post" class="email-form" data-email-form enctype="multipart/form-data">
              <input type="hidden" name="slide_confirmed" value="0" data-slide-confirmed>
              <div class="field">
                <label for="email_template_id">テンプレート</label>
                <select id="email_template_id" name="email_template_id" data-template-select>
                  <option value="">テンプレートを選択</option>
                  <?php foreach ($emailTemplates as $template): ?>
                    <option
                      value="<?= h((string) $template['id']); ?>"
                      data-template-subject="<?= h((string) ($template['mail_subject'] ?? '')); ?>"
                      data-template-body="<?= h((string) ($template['mail_body'] ?? '')); ?>"
                      <?= $formValues['email_template_id'] === (string) $template['id'] ? 'selected' : ''; ?>
                    >
                      <?= h((string) ($template['template_name'] ?? '')); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="mail_to">宛先</label>
                <input id="mail_to" name="mail_to" type="email" value="<?= h($formValues['mail_to']); ?>" placeholder="example@example.com">
              </div>

              <div class="field">
                <label for="mail_subject">件名</label>
                <input id="mail_subject" name="mail_subject" type="text" value="<?= h($formValues['mail_subject']); ?>" data-mail-subject>
              </div>

              <div class="field">
                <label for="mail_body">本文</label>
                <textarea id="mail_body" name="mail_body" rows="12" class="mail-body-textarea" data-mail-body><?= h($formValues['mail_body']); ?></textarea>
              </div>
              <div class="field attachment-field">
                <label>添付ファイル</label>
                <div class="attachment-controls">
                  <button type="button" class="btn btn-icon" data-attachment-trigger aria-label="添付ファイルを選択">
                    <img src="img/link.png" alt="" loading="lazy">
                  </button>
                  <input id="mail_attachments" name="mail_attachments[]" type="file" multiple hidden data-attachment-input>
                  <span class="file-meta" data-attachment-meta>未選択</span>
                </div>
                <?php if ($existingAttachments !== []): ?>
                  <ul class="attachment-list">
                    <?php foreach ($existingAttachments as $attachment): ?>
                      <li>
                        <a href="<?= h((string) ($attachment['file_path'] ?? '')); ?>" target="_blank" rel="noopener noreferrer">
                          <?= h((string) ($attachment['file_name'] ?? '添付ファイル')); ?>
                        </a>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>

              <div class="actions email-actions">
                <button class="btn btn-ghost" type="submit" name="action" value="save_email_draft">下書き保存</button>
              </div>
              <div class="swipe-send" data-swipe-send>
                <div class="swipe-send-track">
                  <span class="swipe-send-label">→ 右にスワイプして送信</span>
                  <button type="button" class="swipe-send-thumb" aria-label="送信スライダー" data-swipe-thumb>送信</button>
                </div>
              </div>
            </form>
          </div>
        </section>

        <section id="writing-list" class="panel content-panel detail-panel">
          <div class="section-head">
            <h2>サポート面談記録</h2>
            <button
              type="button"
              class="btn btn-icon section-toggle-button"
              data-section-toggle
              data-target-id="writing-list-body"
              aria-controls="writing-list-body"
              aria-expanded="false"
              aria-label="開ける"
            >
              <img src="img/open.png" alt="" loading="lazy" data-toggle-icon>
            </button>
          </div>
          <div id="writing-list-body" class="collapsible-body" hidden>
            <div class="section-action-row">
              <button type="button" class="btn btn-primary" data-open-modal="create-writing-modal">追加</button>
            </div>

            <?php if (isset($_GET['saved'])): ?>
              <p class="notice">保存しました。</p>
            <?php elseif (isset($_GET['updated'])): ?>
              <p class="notice">更新しました。</p>
            <?php elseif (isset($_GET['deleted'])): ?>
              <p class="notice">削除しました。</p>
            <?php endif; ?>

            <?php if ($errors !== [] && ($currentAction === 'create' || $currentAction === 'update' || $currentAction === 'delete')): ?>
              <ul class="error-list">
                <?php foreach ($errors as $error): ?>
                  <li><?= h($error); ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <?php if ($writings === []): ?>
              <p class="empty">サポート面談記録が登録されていません。</p>
            <?php else: ?>
              <table class="table">
                <thead>

                <tr>
                  <th>ID</th>
                  <th>登録日</th>
                  <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($writings as $writing): ?>
                  <tr>
                    <td data-label="ID"><?= h((string) ($writing['id'] ?? '')); ?></td>
                    <td data-label="登録日"><?= h((string) ($writing['updated_at'] ?? '')); ?></td>
                    <td data-label="操作">
                      <button
                        type="button"
                        class="btn btn-ghost"
                        data-open-modal="writing-modal"
                        data-writing="<?= h((string) ($writing['writing'] ?? '')); ?>"
                        data-writing-notes="<?= h((string) ($writing['writing_notes'] ?? '')); ?>"
                        data-file-name="<?= h((string) ($writing['file_name'] ?? '')); ?>"
                        data-writing-id="<?= h((string) ($writing['id'] ?? '')); ?>"
                      >
                        詳細を見る
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </section>
        <?php renderRefundGuaranteeSection($recordSheetId, $refundGuaranteeStatuses); ?>
      </div>
    </div>
  </section>
</div>

<div class="modal" id="writing-modal" hidden>
  <div class="modal-dialog panel content-panel">
    <div class="section-head">
      <h3>サポート面談記録</h3>
      <button type="button" class="btn btn-ghost" data-close-modal>閉じる</button>
    </div>
    <form method="post" class="writing-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update" data-modal-action>
      <input type="hidden" name="writing_id" value="" data-modal-writing-id>
      <dl class="writing-detail">
        <div class="writing-detail-audio">
          <dt>音声ファイル（差し替え任意）</dt>
          <dd>
            <p class="audio-file" data-modal-file-name>未登録</p>
            <audio controls data-modal-audio preload="metadata"></audio>
            <input name="audio_file" type="file" accept="audio/*">
          </dd>
        </div>
        <div class="writing-detail-body">
          <div class="writing-detail-block">
            <dt>文字起こし</dt>
            <dd>
              <textarea name="writing" rows="14" data-modal-writing-input></textarea>
            </dd>
          </div>
          <div class="writing-detail-block">
            <dt>要約</dt>
            <dd>
              <textarea name="writing_notes" rows="14" data-modal-writing-notes-input></textarea>
            </dd>
          </div>
        </div>
      </dl>
      <div class="actions">
        <button class="btn btn-primary" type="submit">更新する</button>
        <button class="btn btn-ghost btn-danger" type="submit" data-modal-delete>削除する</button>
      </div>
    </form>
  </div>
</div>

<div class="modal" id="create-writing-modal" hidden>
  <div class="modal-dialog panel content-panel">
    <div class="section-head">
      <h3>Writing追加</h3>
      <button type="button" class="btn btn-ghost" data-close-modal>閉じる</button>
    </div>
    <form method="post" class="writing-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="create">
      <div class="field">
        <label for="audio_file">音声ファイル</label>
        <div class="dropzone" data-dropzone>
          <p>ここに音声ファイルをドロップ&ドラッグするか、下のボタンから選択してください。</p>
          <input id="audio_file" name="audio_file" type="file" accept="audio/*" required data-audio-input>
          <p class="file-meta" data-file-meta>未選択</p>
        </div>
      </div>
      <div class="field">
        <label for="writing">writing</label>
        <textarea id="writing" name="writing" rows="4"><?= h($formValues['writing']); ?></textarea>
      </div>
      <div class="field">
        <label for="writing_notes">writing_notes</label>
        <textarea id="writing_notes" name="writing_notes" rows="4"><?= h($formValues['writing_notes']); ?></textarea>
      </div>
      <div class="actions">
        <button class="btn btn-primary" type="submit" style="width: 100%;">保存</button>
      </div>
    </form>
  </div>
</div>
<div class="modal" id="template-editor-modal" hidden>
  <div class="modal-dialog panel content-panel">
    <div class="section-head">
      <h3>メールテンプレート編集</h3>
      <button type="button" class="btn btn-ghost" data-close-modal>閉じる</button>
    </div>

    <div class="template-list-head">
      <button
        type="button"
        class="btn btn-primary"
        data-open-modal="template-form-modal"
        data-template-mode="create"
      >
        追加
      </button>
    </div>

    <?php if ($emailTemplates === []): ?>
      <p class="empty">テンプレートはまだありません。</p>
    <?php else: ?>
      <ul class="template-list">
        <?php foreach ($emailTemplates as $template): ?>
          <li class="template-list-item">
            <div>
              <p class="template-list-name"><?= h((string) ($template['template_name'] ?? '')); ?></p>
              <p class="template-list-subject"><?= h((string) ($template['mail_subject'] ?? '')); ?></p>
            </div>
            <button
              type="button"
              class="btn btn-ghost"
              data-open-modal="template-form-modal"
              data-template-mode="edit"
              data-template-id="<?= h((string) $template['id']); ?>"
              data-template-name="<?= h((string) ($template['template_name'] ?? '')); ?>"
              data-template-subject="<?= h((string) ($template['mail_subject'] ?? '')); ?>"
              data-template-body="<?= h((string) ($template['mail_body'] ?? '')); ?>"
              data-template-notification-body="<?= h((string) (($template['chatwork_message_template'] ?? '') !== '' ? $template['chatwork_message_template'] : DEFAULT_CHATWORK_MESSAGE_TEMPLATE)); ?>"
            >
              編集
            </button>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

  </div>
</div>
<div
  class="modal"
  id="template-form-modal"
  hidden
  data-template-default-notification-body="<?= h(DEFAULT_CHATWORK_MESSAGE_TEMPLATE); ?>"
>
  <div class="modal-dialog panel content-panel">
    <div class="section-head">
      <h3 data-template-modal-title>テンプレート編集</h3>
      <button type="button" class="btn btn-ghost" data-close-modal>閉じる</button>
    </div>
    <form method="post" class="template-item" data-template-form>
      <input type="hidden" name="action" value="template_create" data-template-form-action>
      <input type="hidden" name="template_id" value="" data-template-form-id>
      <div class="field">
        <label>テンプレート名</label>
        <input type="text" name="template_name" value="" required data-template-form-name>
      </div>
      <div class="field">
        <label>件名</label>
        <input type="text" name="template_subject" value="" required data-template-form-subject>
      </div>
      <div class="field">
        <label>本文</label>
        <textarea name="template_body" rows="6" required data-template-form-body></textarea>
      </div>
      <div class="field">
        <label>Chatwork通知本文</label>
        <textarea name="template_notification_body" rows="6" required data-template-form-notification-body></textarea>
      </div>
      <div class="field">
        <label>差し込みルール</label>
        <p class="muted">文字列差し込みは <code>db[キー名]</code>、メンションは <code>mention[キー名]</code>、担当者表示は <code>actor[キー名]</code> を使用します。例: <code>mention[sales_staff]</code> / <code>actor[video_staff]</code>。</p>
      </div>
      <div class="actions">
        <button class="btn btn-primary" type="submit" data-template-submit-label>追加</button>
        <button class="btn btn-ghost btn-danger" type="button" hidden data-template-delete>削除</button>
      </div>
    </form>
  </div>
</div>
<div class="modal" id="tag-manager-modal" hidden>
  <div class="modal-dialog panel content-panel">
    <div class="section-head">
      <h3>タグ管理</h3>
      <button type="button" class="btn btn-ghost" data-close-modal>閉じる</button>
    </div>
    <form class="tag-manager-form" data-tag-manager-form>
      <input type="hidden" value="create_tag" data-tag-manager-action>
      <input type="hidden" value="" data-tag-manager-tag-id>
      <div class="field">
        <label for="tag-manager-name">タグ名</label>
        <input id="tag-manager-name" type="text" required data-tag-manager-name>
      </div>
      <div class="field">
        <label for="tag-manager-color">カラー</label>
        <input id="tag-manager-color" type="color" value="#3b82f6" data-tag-manager-color>
      </div>
      <div class="actions">
        <button class="btn btn-primary" type="submit" data-tag-manager-submit-label>追加</button>
        <button class="btn btn-ghost" type="button" hidden data-tag-manager-cancel-edit>編集をキャンセル</button>
      </div>
    </form>
    <ul class="tag-manager-list" data-tag-manager-list>
      <?php foreach ($tagMaster as $tag): ?>
        <li class="tag-manager-item">
          <span class="tag-preview" style="--tag-color:<?= h((string) ($tag['color'] ?? '#3b82f6')); ?>"><?= h((string) ($tag['name'] ?? 'タグ')); ?></span>
          <div class="tag-manager-actions">
            <button type="button" class="btn btn-ghost" data-tag-edit-id="<?= h((string) ($tag['id'] ?? '')); ?>">編集</button>
            <button type="button" class="btn btn-ghost btn-danger" data-tag-delete-id="<?= h((string) ($tag['id'] ?? '')); ?>">削除</button>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<script src="js/tagging.js?v=<?= time(); ?>"></script>
<?php require 'footer.php'; ?>
