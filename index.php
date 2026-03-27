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

function getQuery(string $key): ?string
{
    $value = $_GET[$key] ?? null;
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    return $value === '' ? null : $value;
}

$fullName = getQuery('full_name');
$status = getQuery('status');
$dateFrom = getQuery('date_from');
$dateTo = getQuery('date_to');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($fullName !== null) {
    $where[] = 'full_name LIKE :full_name';
    $params[':full_name'] = '%' . $fullName . '%';
}
if ($status !== null) {
    $where[] = 'status = :status';
    $params[':status'] = $status;
}
if ($dateFrom !== null) {
    $where[] = 'sales_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== null) {
    $where[] = 'sales_date <= :date_to';
    $params[':date_to'] = $dateTo;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$pdo = db();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM customer_sales_records {$whereSql}");
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

$sql = "SELECT sheet_id, entry_point, status, line_name
        FROM customer_sales_records
        {$whereSql}
        ORDER BY updated_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$statusStmt = $pdo->query("SELECT DISTINCT status FROM customer_sales_records WHERE status IS NOT NULL AND status <> '' ORDER BY status ASC");
$statusOptions = $statusStmt->fetchAll(PDO::FETCH_COLUMN);

$entryPointSummary = [];
foreach ($rows as $row) {
    $key = (string) ($row['entry_point'] ?? '未設定');
    $entryPointSummary[$key] = ($entryPointSummary[$key] ?? 0) + 1;
}
arsort($entryPointSummary);
$topEntryPoint = array_key_first($entryPointSummary) ?? '未設定';
$topEntryPointCount = $entryPointSummary[$topEntryPoint] ?? 0;

$pageTitle = '顧客セールスレコード一覧';
require 'header.php';
?>
<div class="glass-board" aria-hidden="true" style="display:none;"></div>
<div class="dashboard-shell panel">
  <aside class="side-panel">
    <img class="avatar" src="img/dummy.png" alt="担当者アイコン" loading="lazy">
    <h1>Sales Console</h1>
    <p>顧客セールスレコード一覧</p>

    <nav class="side-nav" aria-label="メニュー">
      <a href="#summary">Summary</a>
      <a href="#filters">Filter</a>
      <a href="#records">Records</a>
    </nav>
  </aside>

  <section class="main-panel">
    <!-- <section id="summary" class="summary-grid" aria-label="概要">
      <article class="mini-card">
        <span>総件数</span>
        <strong><?= number_format($total); ?></strong>
        <small>records</small>
      </article>
      <article class="mini-card">
        <span>現在ページ</span>
        <strong><?= $page; ?> / <?= $totalPages; ?></strong>
        <small>pages</small>
      </article>
      <article class="mini-card">
        <span>主要流入</span>
        <strong><?= h($topEntryPoint); ?></strong>
        <small><?= number_format($topEntryPointCount); ?>件</small>
      </article>
      <article class="mini-card">
        <span>ステータス数</span>
        <strong><?= count($statusOptions); ?></strong>
        <small>types</small>
      </article>
    </section> -->

    <section id="filters" class="panel content-panel filters">
      <form method="get" data-filter-form>
        <div class="filter-grid">
          <div class="field">
            <label for="full_name">名前（部分一致）</label>
            <input id="full_name" type="text" name="full_name" value="<?= h($fullName); ?>" placeholder="例: 山田">
          </div>
          <div class="field">
            <label for="status">ステータス</label>
            <select id="status" name="status">
              <option value="">すべて</option>
              <?php foreach ($statusOptions as $option): ?>
                <option value="<?= h((string) $option); ?>" <?= $status === $option ? 'selected' : ''; ?>>
                  <?= h((string) $option); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="date_from">売上日（開始）</label>
            <input id="date_from" type="date" name="date_from" value="<?= h($dateFrom); ?>">
          </div>
          <div class="field">
            <label for="date_to">売上日（終了）</label>
            <input id="date_to" type="date" name="date_to" value="<?= h($dateTo); ?>">
          </div>
        </div>
        <div class="actions">
          <button class="btn btn-primary" type="submit">検索する</button>
          <a class="btn btn-ghost" href="/index.php">リセット</a>
        </div>
      </form>
    </section>

    <section id="records" class="panel content-panel table-wrap">
      <?php if ($rows === []): ?>
        <div class="empty">該当データがありません。</div>
      <?php else: ?>
        <table class="table">
          <thead>
          <tr>
            <th>sheet_id</th>
            <th>entry_point</th>
            <th>status</th>
            <th>line_name</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= h((string) ($row['sheet_id'] ?? '')); ?></td>
              <td><span class="badge"><?= h((string) ($row['entry_point'] ?? '')); ?></span></td>
              <td><?= h((string) ($row['status'] ?? '')); ?></td>
              <td><?= h((string) ($row['line_name'] ?? '')); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <div class="pagination">
        <?php
        $query = $_GET;
        $query['page'] = max(1, $page - 1);
        $prevUrl = 'index.php?' . http_build_query($query);
        $query['page'] = min($totalPages, $page + 1);
        $nextUrl = 'index.php?' . http_build_query($query);
        ?>

        <a class="btn btn-ghost <?= $page <= 1 ? 'is-disabled' : ''; ?>" href="<?= $page <= 1 ? '#' : h($prevUrl); ?>">前へ</a>
        <a class="btn btn-ghost <?= $page >= $totalPages ? 'is-disabled' : ''; ?>" href="<?= $page >= $totalPages ? '#' : h($nextUrl); ?>">次へ</a>
        <span class="meta">全 <?= number_format($total); ?> 件 / <?= $page; ?> / <?= $totalPages; ?> ページ</span>
      </div>
    </section>
  </section>
</div>
<?php require 'footer.php'; ?>
