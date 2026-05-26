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

$supportEndColumnExists = tableHasColumn($pdo, 'request_management', 'support_end_date');
$sendDateColumnExists = tableHasColumn($pdo, 'request_management', 'send_date');

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

    $countSql = 'SELECT COUNT(*)
        FROM request_management rm
        LEFT JOIN customer_sales_records csr ON rm.sheet_id = csr.sheet_id
        WHERE ' . $computedSupportEndDateSql . ' IS NOT NULL';
    $countStmt = $pdo->query($countSql);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $sql = 'SELECT
            rm.sheet_id,
            csr.line_name,
            csr.full_name,
            csr.email,
            csr.sales_staff,
            ' . $computedSupportEndDateSql . ' AS support_end_date
        FROM request_management rm
        LEFT JOIN customer_sales_records csr ON rm.sheet_id = csr.sheet_id
        WHERE ' . $computedSupportEndDateSql . ' IS NOT NULL
        ORDER BY support_end_date ASC, rm.sheet_id ASC
        LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

$pageTitle = 'SUP-SUP NEO サポート終了者一覧';
require 'header.php';
?>
<div class="glass-board" aria-hidden="true" style="display:none;"></div>
<div class="dashboard-shell panel dashboard-shell--support-end">
  <aside class="side-panel side-panel--support-end">
    <h1>SUP-SUP NEO</h1>
    <p>PAGES</p>
    <nav class="side-nav" aria-label="メニュー">
      <a href="index.php">顧客一覧</a>
      <a href="request_management.php">送付依頼一覧</a>
      <a class="is-current" href="support_end_users.php">サポート終了者一覧</a>
    </nav>
  </aside>

  <section class="main-panel">
    <section class="panel content-panel table-wrap">
      <?php if ($rows === []): ?>
        <div class="empty">表示できるデータがありません。</div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>シートID</th>
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
                <td data-label="シートID"><?= h((string) ($row['sheet_id'] ?? '')); ?></td>
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
<?php require 'footer.php'; ?>
