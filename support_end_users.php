<?php

declare(strict_types=1);

require_once 'config.php';

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

function getPendingRequestFlags(PDO $pdo): array
{
    $hasPendingRequest = false;
    $hasOverduePendingRequest = false;

    if (!tableHasColumn($pdo, 'request_management', 'is_completed')) {
        return [$hasPendingRequest, $hasOverduePendingRequest];
    }

    if (tableHasColumn($pdo, 'request_management', 'send_date')) {
        $pendingRequestStmt = $pdo->query(
            'SELECT 1
             FROM request_management
             WHERE is_completed = 0
               AND DATE(send_date) <= CURRENT_DATE()
             LIMIT 1'
        );
        $overduePendingRequestStmt = $pdo->query(
            'SELECT 1
             FROM request_management
             WHERE is_completed = 0
               AND DATE(send_date) < CURRENT_DATE()
             LIMIT 1'
        );
        $hasOverduePendingRequest = (bool) $overduePendingRequestStmt->fetchColumn();
    } else {
        $pendingRequestStmt = $pdo->query('SELECT 1 FROM request_management WHERE is_completed = 0 LIMIT 1');
    }

    $hasPendingRequest = (bool) $pendingRequestStmt->fetchColumn();

    return [$hasPendingRequest, $hasOverduePendingRequest];
}
function ensureSupportEndStatusTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS support_end_user_statuses (
            sheet_id VARCHAR(255) NOT NULL PRIMARY KEY,
            is_ended TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

$pdo = db();
ensureSupportEndStatusTable($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sheet_id'], $_POST['is_ended'])) {
    $sheetId = trim((string) $_POST['sheet_id']);
    $isEnded = (int) $_POST['is_ended'] === 1 ? 1 : 0;

    if ($sheetId !== '') {
        $upsertStmt = $pdo->prepare(
            'INSERT INTO support_end_user_statuses (sheet_id, is_ended)
             VALUES (:sheet_id, :is_ended)
             ON DUPLICATE KEY UPDATE is_ended = VALUES(is_ended)'
        );
        $upsertStmt->bindValue(':sheet_id', $sheetId);
        $upsertStmt->bindValue(':is_ended', $isEnded, PDO::PARAM_INT);
        $upsertStmt->execute();
    }

    $isAjaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => true,
            'sheet_id' => $sheetId,
            'is_ended' => $isEnded,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $redirectUrl = 'support_end_users.php?' . http_build_query($_GET);
    header('Location: ' . $redirectUrl);
    exit;
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$keyword = trim((string) ($_GET['keyword'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'active');
if (!in_array($statusFilter, ['active', 'ended', 'all'], true)) {
    $statusFilter = 'active';
}

$supportEndColumnExists = tableHasColumn($pdo, 'request_management', 'support_end_date');
$sendDateColumnExists = tableHasColumn($pdo, 'request_management', 'send_date');

[$hasPendingRequest, $hasOverduePendingRequest] = getPendingRequestFlags($pdo);
if (!$supportEndColumnExists && !$sendDateColumnExists) {
    $rows = [];
    $total = 0;
    $totalPages = 1;
} else {
    $computedSupportEndDateSql = $supportEndColumnExists && $sendDateColumnExists
        ? 'COALESCE(NULLIF(rm.support_end_date, \'\'), DATE_ADD(rm.send_date, INTERVAL 6 MONTH))'
        : ($supportEndColumnExists
            ? 'NULLIF(rm.support_end_date, \'\')'
            : 'DATE_ADD(rm.send_date, INTERVAL 6 MONTH)');

    $where = ['target.support_end_date IS NOT NULL'];
    $bindings = [];

    if ($statusFilter === 'active') {
        $where[] = 'COALESCE(ses.is_ended, 0) = 0';
    } elseif ($statusFilter === 'ended') {
        $where[] = 'COALESCE(ses.is_ended, 0) = 1';
    }

    if ($keyword !== '') {
        $where[] = '(csr.line_name LIKE :keyword OR csr.full_name LIKE :keyword OR csr.email LIKE :keyword OR csr.sales_staff LIKE :keyword)';
        $bindings[':keyword'] = '%' . $keyword . '%';
    }

    $whereSql = implode(' AND ', $where);

    $countSql = 'SELECT COUNT(*)
        FROM (
            SELECT
                rm.sheet_id,
                ' . $computedSupportEndDateSql . ' AS support_end_date
            FROM request_management rm
        ) AS target
        LEFT JOIN customer_sales_records csr ON target.sheet_id = csr.sheet_id
        LEFT JOIN support_end_user_statuses ses ON target.sheet_id = ses.sheet_id
        WHERE ' . $whereSql;

    $countStmt = $pdo->prepare($countSql);
    foreach ($bindings as $name => $value) {
        $countStmt->bindValue($name, $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $sql = 'SELECT
            target.sheet_id,
            csr.line_name,
            csr.full_name,
            csr.email,
            csr.sales_staff,
            target.support_end_date,
            COALESCE(ses.is_ended, 0) AS is_ended
        FROM (
            SELECT
                rm.sheet_id,
                ' . $computedSupportEndDateSql . ' AS support_end_date
            FROM request_management rm
        ) AS target
        LEFT JOIN customer_sales_records csr ON target.sheet_id = csr.sheet_id
        LEFT JOIN support_end_user_statuses ses ON target.sheet_id = ses.sheet_id
        WHERE ' . $whereSql . '
        ORDER BY target.support_end_date ASC, target.sheet_id ASC
        LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);
    foreach ($bindings as $name => $value) {
        $stmt->bindValue($name, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

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
  ]);
  ?>

  <section class="main-panel">
    <section id="filters" class="panel content-panel filters">
      <form method="get" data-filter-form>
        <div class="filter-grid">
          <div class="field">
            <label for="keyword">ユーザ検索</label>
            <input id="keyword" type="text" name="keyword" value="<?= h($keyword); ?>" placeholder="LINE名・本名・メール・セールス">
          </div>
          <div class="field">
            <label for="status">状態</label>
            <select id="status" name="status">
              <option value="active" <?= $statusFilter === 'active' ? 'selected' : ''; ?>>継続中のみ</option>
              <option value="ended" <?= $statusFilter === 'ended' ? 'selected' : ''; ?>>終了のみ</option>
              <option value="all" <?= $statusFilter === 'all' ? 'selected' : ''; ?>>すべて</option>
            </select>
          </div>
        </div>
        <div class="actions">
          <button type="submit" class="btn btn-primary">検索する</button>
        </div>
      </form>
    </section>

    <section class="panel content-panel table-wrap">
      <?php if ($rows === []): ?>
        <div class="empty">表示できるデータがありません。</div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>状態</th>
              <th>LINE名</th>
              <th>本名</th>
              <th>メールアドレス</th>
              <th>セールス</th>
              <th>サポート終了日</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td data-label="状態">
                  <form method="post" class="js-status-form" style="margin: 0; display: inline-flex; align-items: center; gap: 8px;">
                    <input type="hidden" name="sheet_id" value="<?= h((string) ($row['sheet_id'] ?? '')); ?>">
                    <input type="hidden" name="is_ended" value="<?= ((int) ($row['is_ended'] ?? 0) === 1) ? '0' : '1'; ?>">
                    <button type="submit" class="btn btn-ghost js-status-button" style="min-width: 84px;" data-state="<?= ((int) ($row['is_ended'] ?? 0) === 1) ? 'ended' : 'active'; ?>">
                      <?= ((int) ($row['is_ended'] ?? 0) === 1) ? 'ON: 終了' : 'OFF: 継続中'; ?>
                    </button>
                  </form>
                </td>
                <td data-label="LINE名"><?= h((string) ($row['line_name'] ?? '')); ?></td>
                <td data-label="本名"><?= h((string) ($row['full_name'] ?? '')); ?></td>
                <td data-label="メールアドレス"><?= h((string) ($row['email'] ?? '')); ?></td>
                <td data-label="セールス"><?= h((string) ($row['sales_staff'] ?? '')); ?></td>
                <td data-label="サポート終了日"><?= h((string) ($row['support_end_date'] ?? '')); ?></td>
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
        <a class="btn btn-ghost <?= $page >= $totalPages ? 'is-disabled' : ''; ?>" href="<?= $page >= $totalPages ? '#' : h($nextUrl); ?>">次へ</a>
        <span class="meta">全 <?= number_format($total); ?> 件 / <?= $page; ?> / <?= $totalPages; ?> ページ</span>
      </div>
    </section>
  </section>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.js-status-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      const button = form.querySelector('.js-status-button');
      if (!button || button.disabled) {
        return;
      }

      const formData = new FormData(form);
      button.disabled = true;
      button.textContent = '更新中...';

      fetch('support_end_users.php?<?= h(http_build_query($_GET)); ?>', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('failed');
          }
          return response.json();
        })
        .then(function (payload) {
          const current = form.querySelector('input[name="is_ended"]');
          if (!current || !payload || payload.ok !== true) {
            throw new Error('invalid');
          }
          const newIsEnded = parseInt(current.value, 10) === 1;
          button.dataset.state = newIsEnded ? 'ended' : 'active';
          button.textContent = newIsEnded ? 'ON: 終了' : 'OFF: 継続中';
          current.value = newIsEnded ? '0' : '1';
        })
        .catch(function () {
          button.textContent = '更新失敗';
          setTimeout(function () {
            location.reload();
          }, 600);
        })
        .finally(function () {
          button.disabled = false;
        });
    });
  });
});
</script>
<?php require 'footer.php'; ?>
