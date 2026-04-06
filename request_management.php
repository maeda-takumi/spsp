<?php

declare(strict_types=1);

require_once 'config.php';

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

$pdo = db();
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

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

    $countStmt = $pdo->query('SELECT COUNT(*) FROM request_management');
    $total = (int) ($countStmt !== false ? $countStmt->fetchColumn() : 0);
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
    $selectColumns[] = 'csr.email AS csr_email';
    $selectColumns[] = 'csr.`' . $videoStaffColumn . '` AS csr_video_staff';
    $selectColumns[] = 'csr.sales_staff AS csr_sales_staff';

    $orderSql = $orderColumn !== '' ? 'ORDER BY rm.`' . str_replace('`', '``', $orderColumn) . '` ' . $orderDirection : '';

    $sql = 'SELECT ' . implode(', ', $selectColumns)
        . ' FROM request_management rm'
        . ' LEFT JOIN customer_sales_records csr ON rm.sheet_id = csr.sheet_id '
        . $orderSql
        . ' LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

$pageTitle = 'request_management 一覧';
require 'header.php';
?>
<div class="glass-board" aria-hidden="true" style="display:none;"></div>
<div class="dashboard-shell panel">
  <aside class="side-panel">
    <h1>SUP-SUP NEO</h1>
    <p>LINKS</p>
    <nav class="side-nav" aria-label="メニュー">
      <a href="index.php">顧客一覧</a>
    </nav>
  </aside>

  <section class="main-panel">
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
            <th>メールアドレス</th>
            <th>演者名</th>
            <th>セールス名</th>
            <th>操作</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <?php $rowSheetId = (string) ($row['rm__sheet_id'] ?? ''); ?>
            <tr>
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
                  <?php else: ?>
                    <?= h($rawValue); ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td data-label="LINE名"><?= h((string) ($row['csr_line_name'] ?? '')); ?></td>
              <td data-label="メールアドレス"><?= h((string) ($row['csr_email'] ?? '')); ?></td>
              <td data-label="演者名"><?= h((string) ($row['csr_video_staff'] ?? '')); ?></td>
              <td data-label="セールス名"><?= h((string) ($row['csr_sales_staff'] ?? '')); ?></td>
              <td data-label="操作">
                <?php if ($rowSheetId !== ''): ?>
                  <a class="btn btn-ghost" href="detail.php?<?= h(http_build_query(['sheet_id' => $rowSheetId, 'from' => 'request_management', 'page' => $page])); ?>">詳細</a>
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
