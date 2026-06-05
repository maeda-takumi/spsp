<?php

declare(strict_types=1);

require_once 'config.php';
require_once 'chatwork_notifier.php';
require_once 'support_end_reminder.php';

// このページ専用のテストモード（true でテスト用API_KEYを使用）
// 影響範囲は support_end_users.php の送信処理のみ。他ファイルは本番キーのまま。
const SUPPORT_END_USE_TEST_API_KEY = false;
const SUPPORT_END_TEST_API_KEY = 'fee574510c5ce22d78b85282a0a8acaa';

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

function getTableColumns(PDO $pdo, string $tableName): array
{
    $table = str_replace('`', '``', $tableName);
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');

    if ($stmt === false) {
        return [];
    }

    $columns = [];
    foreach ($stmt->fetchAll() as $row) {
        $name = (string) ($row['Field'] ?? '');
        if ($name !== '') {
            $columns[] = $name;
        }
    }
    return $columns;
}

function getPendingRequestFlags(PDO $pdo, array $requestManagementColumns): array
{
    $hasPendingRequest = false;
    $hasOverduePendingRequest = false;

    if (!in_array('is_completed', $requestManagementColumns, true)) {
        return [$hasPendingRequest, $hasOverduePendingRequest];
    }

    if (in_array('send_date', $requestManagementColumns, true)) {
        $pendingRequestStmt = $pdo->query(
            'SELECT 1
             FROM request_management
             WHERE is_completed = 0
               AND send_date <= CURRENT_DATE()
             LIMIT 1'
        );
        $overduePendingRequestStmt = $pdo->query(
            'SELECT 1
             FROM request_management
             WHERE is_completed = 0
               AND send_date < CURRENT_DATE()
             LIMIT 1'
        );
        $hasOverduePendingRequest = (bool) $overduePendingRequestStmt->fetchColumn();
    } else {
        $pendingRequestStmt = $pdo->query('SELECT 1 FROM request_management WHERE is_completed = 0 LIMIT 1');
    }

    $hasPendingRequest = (bool) $pendingRequestStmt->fetchColumn();

    return [$hasPendingRequest, $hasOverduePendingRequest];
}
$pdo = db();

// 送信ログテーブル（存在しなければ自動作成）
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS support_end_send_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        end_id BIGINT UNSIGNED NOT NULL,
        send_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_end_id (end_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

// 送信ログ取得API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'fetch_logs') {
    header('Content-Type: application/json; charset=UTF-8');
    $endId = (int) ($_POST['end_id'] ?? 0);
    if ($endId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'end_id が不正です。', 'logs' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT id, send_date FROM support_end_send_log WHERE end_id = :end_id ORDER BY send_date DESC, id DESC'
        );
        $stmt->bindValue(':end_id', $endId, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();
        echo json_encode([
            'ok' => true,
            'end_id' => $endId,
            'logs' => array_map(static function ($row) {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'send_date' => (string) ($row['send_date'] ?? ''),
                ];
            }, $logs),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'ログ取得に失敗しました。', 'logs' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'send_notifications') {
    header('Content-Type: application/json; charset=UTF-8');
    $sheetIds = $_POST['sheet_ids'] ?? [];
    if (!is_array($sheetIds)) {
        $sheetIds = [];
    }
    $sheetIds = array_values(array_filter(array_map(static function ($v) {
        return trim((string) $v);
    }, $sheetIds), static function ($v) {
        return $v !== '';
    }));

    if ($sheetIds === []) {
        echo json_encode(['ok' => false, 'error' => '送信対象がありません。', 'sent' => 0, 'failed' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $custColumns = getTableColumns($pdo, 'customer_sales_records');
    $videoCol = in_array('video_dtaff', $custColumns, true) ? 'video_dtaff' : 'video_staff';

    $placeholders = implode(',', array_fill(0, count($sheetIds), '?'));
    $selectStmt = $pdo->prepare(
        'SELECT csr.sheet_id, csr.line_name, csr.full_name, csr.sales_staff, csr.`' . $videoCol . '` AS video_staff,
                seo.id AS end_id, seo.support_end_date
         FROM customer_sales_records csr
         INNER JOIN support_end_date_overrides seo ON seo.sheet_id = csr.sheet_id
         WHERE csr.sheet_id IN (' . $placeholders . ')'
    );
    $selectStmt->execute($sheetIds);
    $targetRows = $selectStmt->fetchAll();

    $logInsertStmt = $pdo->prepare(
        'INSERT INTO support_end_send_log (end_id, send_date) VALUES (:end_id, NOW())'
    );

    $settingsPath = __DIR__ . '/support_end_chatwork_settings.json';
    $settings = readSupportEndReminderSettings($settingsPath);

    $sent = 0;
    $failed = 0;
    $errors = [];

    foreach ($targetRows as $r) {
        $salesStaff = trim((string) ($r['sales_staff'] ?? ''));
        $sheetIdVal = trim((string) ($r['sheet_id'] ?? ''));
        if ($salesStaff === '') {
            $failed++;
            $errors[] = $sheetIdVal . ': セールス担当未設定';
            continue;
        }
        $target = resolveSupportEndNotificationTarget($salesStaff, $settings);
        $roomId = $target['room_id'];
        if ($roomId === '' || !preg_match('/^\d+$/', $roomId)) {
            $failed++;
            $errors[] = $sheetIdVal . ' (' . $salesStaff . '): 通知先ルーム未設定';
            continue;
        }
        $mentions = [];
        if ($target['to_id'] !== '' && preg_match('/^\d+$/', $target['to_id'])) {
            $mentions[] = $target['to_id'];
        }

        $message = "■サポート終了1ヶ月前確認\n"
            . "LINE名: " . trim((string) ($r['line_name'] ?? '')) . "\n"
            . "動画担当: " . trim((string) ($r['video_staff'] ?? '')) . "\n"
            . "サポート終了日: " . trim((string) ($r['support_end_date'] ?? ''));

        try {
            $apiKeyOverride = SUPPORT_END_USE_TEST_API_KEY ? SUPPORT_END_TEST_API_KEY : '';
            sendSupportEndChatworkNotification($roomId, $message, $mentions, $apiKeyOverride);
            $sent++;

            // 送信成功時にログを残す
            $endId = (int) ($r['end_id'] ?? 0);
            if ($endId > 0) {
                try {
                    $logInsertStmt->bindValue(':end_id', $endId, PDO::PARAM_INT);
                    $logInsertStmt->execute();
                } catch (Throwable $logEx) {
                    // ログ書き込み失敗は本体処理を止めない
                    error_log('support_end_send_log insert failed sheet_id=' . $sheetIdVal . ' ' . $logEx->getMessage());
                }
            }
        } catch (Throwable $e) {
            $failed++;
            $errors[] = $sheetIdVal . ' (' . $salesStaff . '): ' . $e->getMessage();
        }
    }

    echo json_encode([
        'ok' => $failed === 0,
        'sent' => $sent,
        'failed' => $failed,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sheet_id'], $_POST['support_end_date'])) {
    $postedSheetId = trim((string) $_POST['sheet_id']);
    $postedEndDate = trim((string) $_POST['support_end_date']);
    $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $ok = false;
    $errorMessage = '';

    if ($postedSheetId === '') {
        $errorMessage = 'シートIDが空です。';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postedEndDate) || strtotime($postedEndDate) === false) {
        $errorMessage = 'サポート終了日の形式が不正です（YYYY-MM-DD）。';
    } else {
        $upsertStmt = $pdo->prepare(
            'INSERT INTO support_end_date_overrides (sheet_id, support_end_date)
             VALUES (:sheet_id, :support_end_date)
             ON DUPLICATE KEY UPDATE support_end_date = VALUES(support_end_date), updated_at = CURRENT_TIMESTAMP'
        );
        $upsertStmt->bindValue(':sheet_id', $postedSheetId);
        $upsertStmt->bindValue(':support_end_date', $postedEndDate);
        $upsertStmt->execute();
        $ok = true;
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => $ok,
            'sheet_id' => $postedSheetId,
            'support_end_date' => $postedEndDate,
            'error' => $errorMessage,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: support_end_users.php?' . http_build_query($_GET));
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$keyword = trim((string) ($_GET['keyword'] ?? ''));

// 基準日: isset で「初回アクセス」と「明示的にクリア」を区別
if (isset($_GET['base_date'])) {
    $baseDateInput = trim((string) $_GET['base_date']);
    if ($baseDateInput !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $baseDateInput) && strtotime($baseDateInput) !== false) {
        $baseDate = $baseDateInput;
    } else {
        // 明示的に空 or 不正値 → フィルタ無効
        $baseDate = '';
    }
} else {
    // 初回アクセス → 今日をデフォルト
    $baseDate = date('Y-m-d');
}
$targetDate = $baseDate !== '' ? date('Y-m-d', strtotime($baseDate . ' +30 days')) : '';

$requestManagementColumns = getTableColumns($pdo, 'request_management');

$customerSalesColumns = getTableColumns($pdo, 'customer_sales_records');
$videoStaffColumn = in_array('video_dtaff', $customerSalesColumns, true) ? 'video_dtaff' : 'video_staff';

[$hasPendingRequest, $hasOverduePendingRequest] = getPendingRequestFlags($pdo, $requestManagementColumns);

$salesStaffOptions = [];
$salesStaffStmt = $pdo->query("SELECT DISTINCT sales_staff FROM customer_sales_records WHERE sales_staff IS NOT NULL AND sales_staff <> '' ORDER BY sales_staff ASC");
if ($salesStaffStmt !== false) {
    foreach ($salesStaffStmt->fetchAll() as $r) {
        $name = trim((string) ($r['sales_staff'] ?? ''));
        if ($name !== '') {
            $salesStaffOptions[] = $name;
        }
    }
}

// 現在のChatwork通知設定一覧（送信枠で表示）
$chatworkSettings = readSupportEndReminderSettings(__DIR__ . '/support_end_chatwork_settings.json');
$notificationsMap = is_array($chatworkSettings['sales_staff_notifications'] ?? null) ? $chatworkSettings['sales_staff_notifications'] : [];
$groupIdMap = is_array($chatworkSettings['sales_staff_group_ids'] ?? null) ? $chatworkSettings['sales_staff_group_ids'] : [];

$settingsView = [];
$staffNames = array_unique(array_merge(array_keys($notificationsMap), array_keys($groupIdMap)));
sort($staffNames);
foreach ($staffNames as $staffName) {
    $row = is_array($notificationsMap[$staffName] ?? null) ? $notificationsMap[$staffName] : [];
    $toId = trim((string) ($row['to_id'] ?? ''));
    $chatworkId = trim((string) ($row['chatwork_id'] ?? $row['room_id'] ?? ''));
    if ($chatworkId === '') {
        $chatworkId = trim((string) ($groupIdMap[$staffName] ?? ''));
    }
    if ($toId === '' && $chatworkId === '') {
        continue;
    }
    $settingsView[] = [
        'name' => (string) $staffName,
        'to_id' => $toId,
        'chatwork_id' => $chatworkId,
    ];
}

$where = [];
$bindings = [];

if ($targetDate !== '') {
    $where[] = 'seo.support_end_date = :target_date';
    $bindings[':target_date'] = $targetDate;
}

if ($keyword !== '') {
    $where[] = '(csr.line_name LIKE :keyword OR csr.full_name LIKE :keyword OR csr.email LIKE :keyword OR csr.sales_staff LIKE :keyword OR csr.`' . $videoStaffColumn . '` LIKE :keyword)';
    $bindings[':keyword'] = '%' . $keyword . '%';
}

$whereSql = $where === [] ? '1=1' : implode(' AND ', $where);

$sql = 'SELECT
        csr.sheet_id,
        csr.line_name,
        csr.full_name,
        csr.`' . $videoStaffColumn . '` AS video_staff,
        csr.sales_staff,
        seo.id AS end_id,
        seo.support_start_date,
        seo.support_end_date
    FROM customer_sales_records csr
    INNER JOIN support_end_date_overrides seo ON seo.sheet_id = csr.sheet_id
    WHERE ' . $whereSql . '
    ORDER BY csr.sheet_id ASC
    LIMIT :limit OFFSET :offset';

$stmt = $pdo->prepare($sql);
foreach ($bindings as $name => $value) {
    $stmt->bindValue($name, $value);
}
$stmt->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
$hasNextPage = count($rows) > $perPage;
if ($hasNextPage) {
    $rows = array_slice($rows, 0, $perPage);
}
$totalPages = $hasNextPage ? $page + 1 : $page;
$total = $offset + count($rows) + ($hasNextPage ? 1 : 0);

$pageTitle = 'SUP-SUP NEO サポート終了管理一覧';
require 'header.php';
?>
<div class="glass-board" aria-hidden="true" style="display:none;"></div>
<div class="dashboard-shell panel dashboard-shell--support-end">
  <?php
  require_once 'sidebar.php';
  renderSidebar('support_end_users', [
      'hasPendingRequest' => $hasPendingRequest,
      'hasOverduePendingRequest' => $hasOverduePendingRequest,
      'hasUpcomingSupportEnd' => getSupportEndAlertFlag($pdo),
  ]);
  ?>

  <section class="main-panel" data-sales-staff-options="<?= h(json_encode($salesStaffOptions, JSON_UNESCAPED_UNICODE)); ?>">
    <div class="section-frame" style="gap:15px; display:flex; flex-direction:row; align-items:stretch;">
      <section id="filters" class="panel content-panel filters" style="flex:1; min-width:0;">
        <form method="get" data-filter-form>
          <div class="filter-grid-column">
            <div class="field">
              <label for="keyword">ユーザ検索</label>
              <input id="keyword" type="text" name="keyword" value="<?= h($keyword); ?>" placeholder="LINE名・本名・メール・動画担当・セールス">
            </div>
            <div class="field">
              <label for="base_date">基準日（この日の30日後のデータを表示）</label>
              <input id="base_date" type="date" name="base_date" value="<?= h($baseDate); ?>">
              <small style="display:block; margin-top:4px; color:#666;">
              <?php if ($targetDate !== ''): ?>対象日: <?= h($targetDate); ?><?php else: ?>基準日なし（日付フィルタ無効）<?php endif; ?>
            </small>
            </div>
          </div>
          <div class="actions">
            <button type="submit" class="btn btn-primary">検索する</button>
          </div>
        </form>
      </section>

      <section class="panel content-panel" style="margin-bottom:16px; flex:1; min-width:0;">
        <div class="send-frame">
          <div style="display:flex; align-items:center; justify-content:end; gap:16px;padding:10px;">
            <button type="button" class="btn btn-icon" data-open-modal="support-end-chatwork-settings-modal" aria-label="サポート終了通知設定" title="サポート終了通知設定" style="font-size:20px; padding:6px 10px; flex:0 0 auto;">
              <img src="img/option.png" alt="サポート終了通知設定" style="width:16px; height:16px;">
            </button>


            <button type="button" class="btn btn-primary js-send-notifications" style="flex:0 0 auto;<?= SUPPORT_END_USE_TEST_API_KEY ? ' background:#e67e22;' : ''; ?>"><?= SUPPORT_END_USE_TEST_API_KEY ? 'テスト送信' : '送信'; ?></button>
          </div>

          <div class="chatwork-settings-summary" style="flex:1; min-width:0; overflow:auto; font-size:12px;padding:15px;">
            <?php if ($settingsView === []): ?>
              <p style="margin:0; color:#888;">通知設定は未登録です（⚙から設定してください）</p>
            <?php else: ?>
              <table style="width:100%; border-collapse:collapse;">
                <thead>
                  <tr style="background:#f5f5f5;">
                    <th style="padding:4px 6px; text-align:left; border-bottom:1px solid #ddd; white-space:nowrap;">セールス</th>
                    <th style="padding:4px 6px; text-align:left; border-bottom:1px solid #ddd; white-space:nowrap;">to_id</th>
                    <th style="padding:4px 6px; text-align:left; border-bottom:1px solid #ddd; white-space:nowrap;">chatwork_id</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($settingsView as $s): ?>
                    <tr>
                      <td style="padding:4px 6px; border-bottom:1px solid #eee; white-space:nowrap;"><?= h($s['name']); ?></td>
                      <td style="padding:4px 6px; border-bottom:1px solid #eee; color:<?= $s['to_id'] === '' ? '#c00' : '#333'; ?>;"><?= $s['to_id'] === '' ? '未設定' : h($s['to_id']); ?></td>
                      <td style="padding:4px 6px; border-bottom:1px solid #eee; color:<?= $s['chatwork_id'] === '' ? '#c00' : '#333'; ?>;"><?= $s['chatwork_id'] === '' ? '未設定' : h($s['chatwork_id']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>  
        </div>      
      </section>
</div>

    <section class="panel content-panel table-wrap">
      <?php if ($rows === []): ?>
        <div class="empty">表示できるデータがありません。</div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th style="width:36px;"></th>
              <th>LINE名</th>
              <th>本名</th>
              <th>動画担当</th>
              <th>セールス</th>
              <th>サポート開始日</th>
              <th>サポート終了日</th>
              <th>操作</th>
              <th>通知</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr data-row-sheet-id="<?= h((string) ($row['sheet_id'] ?? '')); ?>" data-end-id="<?= h((string) ($row['end_id'] ?? '')); ?>">
                <td data-label="ログ">
                  <button type="button" class="js-expand-button" aria-expanded="false" title="送信ログを表示" style="background:none; border:none; cursor:pointer; font-size:14px; padding:4px; line-height:1;">▶</button>
                </td>
                <td data-label="LINE名"><?= h((string) ($row['line_name'] ?? '')); ?></td>
                <td data-label="本名"><?= h((string) ($row['full_name'] ?? '')); ?></td>
                <td data-label="動画担当"><?= h((string) ($row['video_staff'] ?? '')); ?></td>
                <td data-label="セールス"><?= h((string) ($row['sales_staff'] ?? '')); ?></td>
                <td data-label="サポート開始日"><?= h((string) ($row['support_start_date'] ?? '')); ?></td>
                <td data-label="サポート終了日" class="js-end-date-cell"><?= h((string) ($row['support_end_date'] ?? '')); ?></td>
                <td data-label="操作">
                  <button type="button" class="btn btn-ghost js-edit-button"
                          data-sheet-id="<?= h((string) ($row['sheet_id'] ?? '')); ?>"
                          data-support-end-date="<?= h((string) ($row['support_end_date'] ?? '')); ?>">変更</button>
                </td>
                <td data-label="通知">
                  <label class="ios-switch">
                    <input type="checkbox" class="js-notify-toggle" checked>
                    <span class="ios-switch-slider"></span>
                  </label>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <div class="pagination">
        <?php
        $query = $_GET;
        $query['page'] = max(1, $page - 1);
        $prevUrl = 'support_end_users.php?' . http_build_query($query);
        $query['page'] = min($totalPages, $page + 1);
        $nextUrl = 'support_end_users.php?' . http_build_query($query);
        ?>
        <a class="btn btn-ghost <?= $page <= 1 ? 'is-disabled' : ''; ?>" href="<?= $page <= 1 ? '#' : h($prevUrl); ?>">前へ</a>
        <a class="btn btn-ghost <?= !$hasNextPage ? 'is-disabled' : ''; ?>" href="<?= !$hasNextPage ? '#' : h($nextUrl); ?>">次へ</a>
        <span class="meta"><?= $page; ?> ページ<?= $hasNextPage ? '（次のページあり）' : '（最終ページ）'; ?></span>
      </div>
    </section>
  </section>
</div>

<div class="modal" id="support-end-chatwork-settings-modal" hidden>
  <div class="modal-dialog panel content-panel">
    <header class="template-manager-header">
      <h3>サポート終了通知設定</h3>
      <button type="button" class="btn btn-ghost" data-close-modal>閉じる</button>
    </header>
    <form class="template-form" data-support-end-settings-form>
      <div class="field">
        <label>セールス担当ごとの通知先（to_id / chatwork_id）</label>
        <p class="muted">各担当者の通知先を個別に設定できます。<code>to_id</code> はメンション対象のChatworkユーザーID、<code>chatwork_id</code> は通知先グループチャットIDです。</p>
        <div class="support-end-settings-cards" data-support-end-sales-list></div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">保存</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay js-modal-overlay" style="display:none;">
  <div class="modal-content panel">
    <h3 style="margin-top:0;">サポート終了日の変更</h3>
    <form class="js-edit-form">
      <div class="field">
        <label>シートID</label>
        <input type="text" class="js-modal-sheet-id" readonly>
      </div>
      <div class="field">
        <label for="modal-support-end-date">サポート終了日</label>
        <input type="date" id="modal-support-end-date" class="js-modal-date-input" required>
      </div>
      <p class="js-modal-message" style="color:#c00; min-height:1.2em; margin:8px 0;"></p>
      <div class="actions" style="display:flex; gap:8px; justify-content:flex-end;">
        <button type="button" class="btn btn-ghost js-modal-cancel">キャンセル</button>
        <button type="submit" class="btn btn-primary js-modal-submit">保存</button>
      </div>
    </form>
  </div>
</div>

<style>
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.5);
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.modal-overlay.is-open { display: flex !important; }
.modal-content {
  background: #fff;
  padding: 24px;
  min-width: 320px;
  max-width: 480px;
  width: 90%;
  border-radius: 8px;
  box-shadow: 0 12px 40px rgba(0,0,0,0.2);
}
.modal-content .field { margin-bottom: 12px; }
.modal-content input[type="text"],
.modal-content input[type="date"] {
  width: 100%;
  padding: 8px;
  box-sizing: border-box;
}

/* iPhone風トグル */
.ios-switch {
  position: relative;
  display: inline-block;
  width: 46px;
  height: 26px;
  vertical-align: middle;
}
.ios-switch input {
  opacity: 0;
  width: 0;
  height: 0;
}
.ios-switch-slider {
  position: absolute;
  cursor: pointer;
  inset: 0;
  background-color: #ccc;
  transition: .2s;
  border-radius: 26px;
}
.ios-switch-slider:before {
  position: absolute;
  content: "";
  height: 20px;
  width: 20px;
  left: 3px;
  bottom: 3px;
  background-color: #fff;
  transition: .2s;
  border-radius: 50%;
  box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.ios-switch input:checked + .ios-switch-slider {
  background-color: #34c759;
}
.ios-switch input:checked + .ios-switch-slider:before {
  transform: translateX(20px);
}

/* 展開ボタン */
.js-expand-button {
  transition: transform 0.18s ease;
  color: #555;
}
.js-expand-button[aria-expanded="true"] {
  transform: rotate(90deg);
}
.log-row > td {
  background: #fafafa;
  padding: 8px 16px;
  border-top: 1px dashed #ccc;
}
.log-row .log-empty {
  color: #888;
  font-style: italic;
}
.log-row table.log-table {
  width: auto;
  border-collapse: collapse;
  font-size: 13px;
}
.log-row table.log-table th,
.log-row table.log-table td {
  padding: 4px 12px;
  border-bottom: 1px solid #eee;
  text-align: left;
}
.log-row table.log-table th {
  background: #f0f0f0;
  font-weight: normal;
  color: #555;
}

/* 通知OFFの行をグレーアウト（トグル列自体は除外） */
tr.is-notify-off > td:not([data-label="通知"]) {
  opacity: 0.4;
  background-color: #c0c0c0;
  color: #888;
}
tr.is-notify-off .js-edit-button {
  pointer-events: none;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // 検索フォーム: キーワードも基準日も空ならsubmitを止める
  const filterForm = document.querySelector('[data-filter-form]');
  if (filterForm) {
    filterForm.addEventListener('submit', function (event) {
      const keywordInput = filterForm.querySelector('[name="keyword"]');
      const baseDateInput = filterForm.querySelector('[name="base_date"]');
      const keywordVal = keywordInput ? keywordInput.value.trim() : '';
      const baseDateVal = baseDateInput ? baseDateInput.value.trim() : '';
      if (keywordVal === '' && baseDateVal === '') {
        event.preventDefault();
        window.alert('キーワードか基準日のどちらかを指定してください。');
      }
    });
  }

  // 送信ログ展開ボタン
  document.querySelectorAll('.js-expand-button').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const tr = btn.closest('tr');
      if (!tr) return;
      const endId = tr.getAttribute('data-end-id') || '';
      const isOpen = btn.getAttribute('aria-expanded') === 'true';
      const next = tr.nextElementSibling;
      const existing = (next && next.classList.contains('log-row') && next.getAttribute('data-log-for') === endId) ? next : null;

      if (isOpen) {
        // 折り畳む
        btn.setAttribute('aria-expanded', 'false');
        if (existing) existing.remove();
        return;
      }

      btn.setAttribute('aria-expanded', 'true');
      // 既に開いてる別行は閉じない（複数同時OK）

      // colspan 用に列数を取得
      const colCount = tr.children.length;
      const logTr = document.createElement('tr');
      logTr.className = 'log-row';
      logTr.setAttribute('data-log-for', endId);
      const td = document.createElement('td');
      td.colSpan = colCount;
      td.textContent = '読み込み中...';
      logTr.appendChild(td);
      tr.parentNode.insertBefore(logTr, tr.nextSibling);

      if (!endId) {
        td.innerHTML = '<span class="log-empty">end_id が取得できません。</span>';
        return;
      }

      const formData = new FormData();
      formData.append('action', 'fetch_logs');
      formData.append('end_id', endId);

      fetch('support_end_users.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
        .then(function (response) {
          if (!response.ok) throw new Error('通信失敗');
          return response.json();
        })
        .then(function (payload) {
          if (!payload || payload.ok !== true) {
            throw new Error((payload && payload.error) ? payload.error : '取得失敗');
          }
          const logs = payload.logs || [];
          if (logs.length === 0) {
            td.innerHTML = '<span class="log-empty">送信ログはありません。</span>';
            return;
          }
          let html = '<table class="log-table"><thead><tr><th>#</th><th>送信日時</th></tr></thead><tbody>';
          logs.forEach(function (log, idx) {
            const sd = (log.send_date || '').replace('T', ' ');
            html += '<tr><td>' + (idx + 1) + '</td><td>' + sd + '</td></tr>';
          });
          html += '</tbody></table>';
          td.innerHTML = html;
        })
        .catch(function (err) {
          td.innerHTML = '<span class="log-empty">取得に失敗しました: ' + (err.message || '') + '</span>';
        });
    });
  });

  // 通知トグル: OFFのとき行をグレーアウト
  document.querySelectorAll('.js-notify-toggle').forEach(function (toggle) {
    const tr = toggle.closest('tr');
    const sync = function () {
      if (!tr) return;
      tr.classList.toggle('is-notify-off', !toggle.checked);
    };
    sync();
    toggle.addEventListener('change', sync);
  });

  const overlay = document.querySelector('.js-modal-overlay');
  const form = document.querySelector('.js-edit-form');
  const sheetIdField = document.querySelector('.js-modal-sheet-id');
  const dateInput = document.querySelector('.js-modal-date-input');
  const message = document.querySelector('.js-modal-message');
  const cancelBtn = document.querySelector('.js-modal-cancel');
  const submitBtn = document.querySelector('.js-modal-submit');
  let currentRow = null;

  function openModal() {
    overlay.classList.add('is-open');
    overlay.style.display = 'flex';
  }
  function closeModal() {
    overlay.classList.remove('is-open');
    overlay.style.display = 'none';
    currentRow = null;
  }

  document.querySelectorAll('.js-edit-button').forEach(function (btn) {
    btn.addEventListener('click', function () {
      currentRow = btn.closest('tr');
      sheetIdField.value = btn.dataset.sheetId || '';
      dateInput.value = btn.dataset.supportEndDate || '';
      message.textContent = '';
      openModal();
      setTimeout(function () { dateInput.focus(); }, 50);
    });
  });

  cancelBtn.addEventListener('click', closeModal);

  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeModal();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    submitBtn.disabled = true;
    submitBtn.textContent = '保存中...';
    message.textContent = '';

    const formData = new FormData();
    formData.append('sheet_id', sheetIdField.value);
    formData.append('support_end_date', dateInput.value);

    fetch('support_end_users.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    })
      .then(function (response) {
        if (!response.ok) throw new Error('通信に失敗しました');
        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.ok) {
          throw new Error((payload && payload.error) ? payload.error : '保存に失敗しました');
        }
        if (currentRow) {
          const dateCell = currentRow.querySelector('.js-end-date-cell');
          if (dateCell) dateCell.textContent = payload.support_end_date;
          const editBtn = currentRow.querySelector('.js-edit-button');
          if (editBtn) editBtn.dataset.supportEndDate = payload.support_end_date;
        }
        closeModal();
      })
      .catch(function (err) {
        message.textContent = err.message || '保存に失敗しました';
      })
      .finally(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = '保存';
      });
  });

  // 送信ボタン: ONになっている行をChatworkに通知
  const sendBtn = document.querySelector('.js-send-notifications');
  if (sendBtn) {
    sendBtn.addEventListener('click', function () {
      const targets = [];
      document.querySelectorAll('tr[data-row-sheet-id]').forEach(function (tr) {
        const toggle = tr.querySelector('.js-notify-toggle');
        if (toggle && toggle.checked) {
          targets.push(tr.getAttribute('data-row-sheet-id') || '');
        }
      });
      if (targets.length === 0) {
        window.alert('ON になっている行がありません。');
        return;
      }
      if (!window.confirm(targets.length + ' 件にサポート終了1ヶ月前確認の通知を送信します。よろしいですか？')) {
        return;
      }

      sendBtn.disabled = true;
      const originalText = sendBtn.textContent;
      sendBtn.textContent = '送信中...';

      const formData = new FormData();
      formData.append('action', 'send_notifications');
      targets.forEach(function (id) { formData.append('sheet_ids[]', id); });

      fetch('support_end_users.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
        .then(function (response) {
          if (!response.ok) throw new Error('通信に失敗しました');
          return response.json();
        })
        .then(function (payload) {
          let msg = '送信完了: ' + (payload.sent || 0) + ' 件\n失敗: ' + (payload.failed || 0) + ' 件';
          if (payload.errors && payload.errors.length) {
            msg += '\n\nエラー:\n' + payload.errors.join('\n');
          }
          window.alert(msg);
        })
        .catch(function (err) {
          window.alert('送信に失敗しました: ' + (err.message || ''));
        })
        .finally(function () {
          sendBtn.disabled = false;
          sendBtn.textContent = originalText;
        });
    });
  }
});
</script>

<?php require 'footer.php'; ?>
