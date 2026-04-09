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

function getQuery(string $key): ?string
{
    $value = $_GET[$key] ?? null;
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    return $value === '' ? null : $value;
}
function formatDisplayDate(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($trimmed);

        return $date->format('Y/m/d');
    } catch (Throwable $e) {
        return $value;
    }
}
$pdo = db();
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$sheetId = getQuery('sheet_id');
$name = getQuery('name');
$status = getQuery('status');

$rmExistsStmt = $pdo->prepare(
    'SELECT 1
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = :schema
       AND TABLE_NAME = :table_name
     LIMIT 1'
);
$rmExistsStmt->bindValue(':schema', DB_NAME);
$rmExistsStmt->bindValue(':table_name', 'request_management');
$rmExistsStmt->execute();
$requestManagementExists = (bool) $rmExistsStmt->fetchColumn();

$rows = [];
$rmColumns = [];
$visibleRmColumns = [];
$total = 0;
$totalPages = 1;

if ($requestManagementExists) {
    $columnStmt = $pdo->query('SHOW COLUMNS FROM request_management');
    $columnRows = $columnStmt !== false ? $columnStmt->fetchAll() : [];
    foreach ($columnRows as $columnRow) {
        $column = (string) ($columnRow['Field'] ?? '');
        if ($column !== '') {
            $rmColumns[] = $column;
        }
    }

    $visibleRmColumns = array_values(array_filter(
        $rmColumns,
        static fn (string $column): bool => !in_array($column, ['id', 'created_at'], true)
    ));

    $where = [];
    $params = [];

    if ($sheetId !== null) {
        $where[] = 'rm.sheet_id LIKE :sheet_id';
        $params[':sheet_id'] = '%' . $sheetId . '%';
    }
    if ($name !== null) {
        $where[] = '(csr.line_name LIKE :name OR csr.full_name LIKE :name)';
        $params[':name'] = '%' . $name . '%';
    }
    if ($status !== null && in_array($status, ['0', '1'], true)) {
        $where[] = 'rm.is_completed = :status';
        $params[':status'] = (int) $status;
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    $countSql = 'SELECT COUNT(*) FROM request_management rm LEFT JOIN customer_sales_records csr ON rm.sheet_id = csr.sheet_id' . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $orderColumn = in_array('updated_at', $rmColumns, true)
        ? 'updated_at'
        : (in_array('id', $rmColumns, true)
            ? 'id'
            : (in_array('sheet_id', $rmColumns, true) ? 'sheet_id' : $rmColumns[0] ?? ''));
    $orderDirection = $orderColumn !== '' ? 'DESC' : '';

    $selectColumns = [];
    foreach ($rmColumns as $column) {
        $selectColumns[] = 'rm.`' . str_replace('`', '``', $column) . '` AS `rm__' . str_replace('`', '``', $column) . '`';
    }

    $videoStaffColumn = tableHasColumn($pdo, 'customer_sales_records', 'video_dtaff') ? 'video_dtaff' : 'video_staff';

    $selectColumns[] = 'csr.line_name AS csr_line_name';
    $selectColumns[] = 'csr.full_name AS csr_full_name';
    $selectColumns[] = 'csr.email AS csr_email';
    $selectColumns[] = 'csr.`' . $videoStaffColumn . '` AS csr_video_staff';
    $selectColumns[] = 'csr.sales_staff AS csr_sales_staff';

    $orderSql = $orderColumn !== '' ? 'ORDER BY rm.`' . str_replace('`', '``', $orderColumn) . '` ' . $orderDirection : '';

    $sql = 'SELECT ' . implode(', ', $selectColumns)
        . ' FROM request_management rm'
        . ' LEFT JOIN customer_sales_records csr ON rm.sheet_id = csr.sheet_id '
        . $whereSql
        . ' '
        . $orderSql
        . ' LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

$pageTitle = 'SUP-SUP NEO 送付依頼一覧';
require 'header.php';
?>
<div class="glass-board" aria-hidden="true" style="display:none;"></div>
<div class="dashboard-shell panel dashboard-shell--request">
  <aside class="side-panel side-panel--request">
    <h1>SUP-SUP NEO</h1>
    <p>LINKS</p>
    <nav class="side-nav" aria-label="メニュー">
      <a href="index.php">顧客一覧</a>
    </nav>
  </aside>

  <section class="main-panel">
    <section class="panel content-panel filters">
      <form method="get">
        <div class="filter-grid">
          <div class="field">
            <label for="sheet_id">シートID</label>
            <input id="sheet_id" type="text" name="sheet_id" value="<?= h($sheetId); ?>" placeholder="例: 35016">
          </div>
          <div class="field">
            <label for="name">本名 / LINE名</label>
            <input id="name" type="text" name="name" value="<?= h($name); ?>" placeholder="例: 山田">
          </div>
          <div class="field">
            <label for="status">状態</label>
            <select id="status" name="status">
              <option value="">すべて</option>
              <option value="0" <?= $status === '0' ? 'selected' : ''; ?>>未送付</option>
              <option value="1" <?= $status === '1' ? 'selected' : ''; ?>>送付済</option>
            </select>
          </div>
        </div>
        <div class="actions">
          <button class="btn btn-primary" type="submit">検索する</button>
          <a class="btn btn-ghost" href="request_management.php">リセット</a>
        </div>
      </form>
    </section>
    <section class="panel content-panel table-wrap">
      <?php if (!$requestManagementExists): ?>
        <div class="empty">request_management テーブルが存在しません。</div>
      <?php elseif ($rows === []): ?>
        <div class="empty">request_management のデータがありません。</div>
      <?php else: ?>
        <table class="table">
          <thead>
          <tr>
            <?php
            $headerLabels = [
                'sheet_id' => 'シートID',
                'document_type' => '送付種別',
                'is_completed' => '状態',
            ];
            ?>
            <?php foreach ($visibleRmColumns as $column): ?>
              <th><?= h($headerLabels[$column] ?? $column); ?></th>
            <?php endforeach; ?>
            <th>LINE名</th>
            <th>本名</th>
            <th>メールアドレス</th>
            <!-- <th>演者名</th>
            <th>セールス名</th> -->
            <th>操作</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <?php $rowSheetId = (string) ($row['rm__sheet_id'] ?? ''); ?>
            <?php $isCompletedRow = isset($row['rm__is_completed']) && (int) $row['rm__is_completed'] === 1; ?>
            <tr class="<?= $isCompletedRow ? 'table-row--completed' : ''; ?>">
              <?php foreach ($visibleRmColumns as $column): ?>
                <?php
                $rawValue = (string) ($row['rm__' . $column] ?? '');
                $label = $headerLabels[$column] ?? $column;
                ?>
                <td data-label="<?= h($label); ?>">
                  <?php if ($column === 'is_completed'): ?>
                    <?php
                    $isCompleted = (int) $rawValue === 1;
                    $statusText = $isCompleted ? '送付済' : '未送付';
                    $statusClass = $isCompleted ? 'badge--completed' : 'badge--pending';
                    ?>
                    <span class="badge <?= h($statusClass); ?>"><?= h($statusText); ?></span>
                  <?php elseif ($column === 'send_date'): ?>
                    <?= h(formatDisplayDate($rawValue)); ?>
                  <?php elseif ($column === 'memo'): ?>
                    <div class="memo-cell-scroll"><?= nl2br(h($rawValue)); ?></div>
                  <?php else: ?>
                    <?= h($rawValue); ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td data-label="LINE名" class="cell-inline-scroll cell-inline-scroll--line"><?= h((string) ($row['csr_line_name'] ?? '')); ?></td>
              <td data-label="本名"><?= h((string) ($row['csr_full_name'] ?? '')); ?></td>
              <td data-label="メールアドレス" class="cell-inline-scroll cell-inline-scroll--email"><?= h((string) ($row['csr_email'] ?? '')); ?></td>
              <!-- <td data-label="演者名"><?= h((string) ($row['csr_video_staff'] ?? '')); ?></td>
              <td data-label="セールス名"><?= h((string) ($row['csr_sales_staff'] ?? '')); ?></td> -->
              <td data-label="操作">
                <?php if ($rowSheetId !== ''): ?>
                  <?php $requestId = (string) ($row['rm__id'] ?? ''); ?>
                  <?php
                  $detailParams = ['sheet_id' => $rowSheetId, 'from' => 'request_management', 'page' => $page];
                  if ($requestId !== '') {
                      $detailParams['request_id'] = $requestId;
                  }
                  ?>
                  <a class="btn btn-ghost" href="detail.php?<?= h(http_build_query($detailParams)); ?>">詳細</a>
                <?php else: ?>
                  <span class="meta">sheet_idなし</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <?php if ($requestManagementExists && $rows !== []): ?>
        <div class="pagination">
          <?php
          $query = $_GET;
          $query['page'] = max(1, $page - 1);
          $prevUrl = 'request_management.php?' . http_build_query($query);
          $query['page'] = min($totalPages, $page + 1);
          $nextUrl = 'request_management.php?' . http_build_query($query);
          ?>

          <a class="btn btn-ghost <?= $page <= 1 ? 'is-disabled' : ''; ?>" href="<?= $page <= 1 ? '#' : h($prevUrl); ?>">前へ</a>
          <a class="btn btn-ghost <?= $page >= $totalPages ? 'is-disabled' : ''; ?>" href="<?= $page >= $totalPages ? '#' : h($nextUrl); ?>">次へ</a>
          <span class="meta">全 <?= number_format($total); ?> 件 / <?= $page; ?> / <?= $totalPages; ?> ページ</span>
        </div>
      <?php endif; ?>
    </section>
  </section>
</div>
<?php require 'footer.php'; ?>
