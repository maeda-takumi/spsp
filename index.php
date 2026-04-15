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
$name = getQuery('name');
$sheetId = getQuery('sheet_id');
$email = getQuery('email');
$videoStaff = getQuery('video_staff');
$salesStaff = getQuery('sales_staff');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;


$where = [];
$params = [];

if ($name !== null) {
    $where[] = '(line_name LIKE :name OR full_name LIKE :name)';
    $params[':name'] = '%' . $name . '%';
}
if ($sheetId !== null) {
    $where[] = 'sheet_id LIKE :sheet_id';
    $params[':sheet_id'] = '%' . $sheetId . '%';
}
if ($email !== null) {
    $where[] = 'email LIKE :email';
    $params[':email'] = '%' . $email . '%';
}
if ($videoStaff !== null) {
    $where[] = 'video_staff = :video_staff';
    $params[':video_staff'] = $videoStaff;
}
if ($salesStaff !== null) {
    $where[] = 'sales_staff = :sales_staff';
    $params[':sales_staff'] = $salesStaff;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$pdo = db();

$videoStaffColumn = tableHasColumn($pdo, 'customer_sales_records', 'video_dtaff') ? 'video_dtaff' : 'video_staff';

$hasPendingRequest = false;
$hasOverduePendingRequest = false;
if (tableHasColumn($pdo, 'request_management', 'is_completed')) {
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
}

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

$sql = "SELECT sheet_id, entry_point, status, line_name, full_name, email, {$videoStaffColumn} AS video_staff_display, sales_staff
        FROM customer_sales_records
        {$whereSql}
        ORDER BY sheet_id DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$videoStaffStmt = $pdo->query("SELECT DISTINCT video_staff FROM customer_sales_records WHERE video_staff IS NOT NULL AND video_staff <> '' ORDER BY video_staff ASC");
$videoStaffOptions = $videoStaffStmt->fetchAll(PDO::FETCH_COLUMN);

$salesStaffStmt = $pdo->query("SELECT DISTINCT sales_staff FROM customer_sales_records WHERE sales_staff IS NOT NULL AND sales_staff <> '' ORDER BY sales_staff ASC");
$salesStaffOptions = $salesStaffStmt->fetchAll(PDO::FETCH_COLUMN);

$entryPointSummary = [];
foreach ($rows as $row) {
    $key = (string) ($row['entry_point'] ?? '未設定');
    $entryPointSummary[$key] = ($entryPointSummary[$key] ?? 0) + 1;
}
arsort($entryPointSummary);
$topEntryPoint = array_key_first($entryPointSummary) ?? '未設定';
$topEntryPointCount = $entryPointSummary[$topEntryPoint] ?? 0;

$importCompletedAt = trim((string) ($_GET['import_completed_at'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $importCompletedAt)) {
    $importCompletedAt = '';
}
$detailReturnParams = ['page' => $page];
if ($name !== null) {
    $detailReturnParams['name'] = $name;
}
if ($sheetId !== null) {
    $detailReturnParams['sheet_id'] = $sheetId;
}
if ($email !== null) {
    $detailReturnParams['email'] = $email;
}
if ($videoStaff !== null) {
    $detailReturnParams['video_staff'] = $videoStaff;
}
if ($salesStaff !== null) {
    $detailReturnParams['sales_staff'] = $salesStaff;
}

$pageTitle = 'SUP-SUP NEO 顧客一覧';
require 'header.php';
?>
<div class="glass-board" aria-hidden="true" style="display:none;"></div>
<div class="dashboard-shell panel dashboard-shell--customer">
  <aside class="side-panel side-panel--customer">
    <!-- <img class="avatar" src="img/neo.png" alt="担当者アイコン" loading="lazy"> -->
    <h1>SUP-SUP NEO</h1>
    <p>PAGES</p>
    <nav class="side-nav" aria-label="メニュー">
      <a class="side-nav__request-link" href="request_management.php">
        <span>送付依頼一覧</span>
        <?php if ($hasPendingRequest): ?>
          <img class="side-nav__alert-icon" src="<?= $hasOverduePendingRequest ? 'img/dokuro.png' : 'img/alert.png'; ?>" alt="未完了の送付依頼あり" loading="lazy">
        <?php endif; ?>
      </a>
      <p>Application</p>
      <div class="side-nav__app-link">
        <a href="https://totalappworks.com/support_aori/" target="_blank" rel="noopener noreferrer">Bull-Fight</a>
        <img src="img/aori.png" alt="Bull-Fight" loading="lazy">
      </div>
      <div class="side-nav__app-link">
        <a href="https://totalappworks.com/support_support/curriculum_answers_list.php" target="_blank" rel="noopener noreferrer">フィードバック</a>
        <img src="img/fb.png" alt="フィードバック" loading="lazy">
      </div>
      <div class="side-nav__app-link">
        <a href="http://schoolai.biz/curriculum/login/admin.php" target="_blank" rel="noopener noreferrer">カリキュラム管理</a>
        <img src="img/user.png" alt="カリキュラム管理" loading="lazy">
      </div>
      <div class="side-nav__app-link">
        <a href="https://step.lme.jp/basic/chat-v3?lastTimeUpdateFriend=0" target="_blank" rel="noopener noreferrer">LMessage</a>
        <img src="img/lme.png" alt="LMessage" loading="lazy">
      </div>
      <div class="side-nav__app-link">
        <a href="https://docs.google.com/document/d/1Cq5sYRV-Ppj4r-ld_y-5OfLGeHSKmM2qAikmW2LPYJk/edit?tab=t.5bcbhp93fbnt" target="_blank" rel="noopener noreferrer">フローマニュアル</a>
        <img src="img/doc.png" alt="フローマニュアル" loading="lazy">
      </div>
    </nav>
  </aside>

  <section class="main-panel">
    <section id="filters" class="panel content-panel filters">
      <form method="get" data-filter-form>
        <div class="filter-grid">
          <div class="field">
            <label for="sheet_id">シートID検索</label>
            <input id="sheet_id" type="text" name="sheet_id" value="<?= h($sheetId); ?>" placeholder="例: 35016">
          </div>
          <div class="field">
            <label for="name">名前検索（LINE名 / 氏名）</label>
            <input id="name" type="text" name="name" value="<?= h($name); ?>" placeholder="例: 山田">
          </div>
          <div class="field">
            <label for="email">メールアドレス検索</label>
            <input id="email" type="text" name="email" value="<?= h($email); ?>" placeholder="例: sample@example.com">
          </div>
          <div class="field">
            <label for="video_staff">演者検索</label>
            <select id="video_staff" name="video_staff">
              <option value="">すべて</option>
              <?php foreach ($videoStaffOptions as $option): ?>
                <option value="<?= h((string) $option); ?>" <?= $videoStaff === $option ? 'selected' : ''; ?>>
                  <?= h((string) $option); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="sales_staff">セールス検索</label>
            <select id="sales_staff" name="sales_staff">
              <option value="">すべて</option>
              <?php foreach ($salesStaffOptions as $option): ?>
                <option value="<?= h((string) $option); ?>" <?= $salesStaff === $option ? 'selected' : ''; ?>>
                  <?= h((string) $option); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="actions">
          <button class="btn btn-primary" type="submit">検索する</button>
          <a class="btn btn-ghost" href="index.php">リセット</a>
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
            <th>シートID</th>
            <th>入口</th>
            <th>状態</th>
            <th>LINE名</th>
            <th>本名</th>
            <th>メールアドレス</th>
            <th>演者名</th>
            <th>セールス名</th>
            <th>操作</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td data-label="シートID"><?= h((string) ($row['sheet_id'] ?? '')); ?></td>
              <td data-label="入口"><span class="badge"><?= h((string) ($row['entry_point'] ?? '')); ?></span></td>
              <td data-label="状態"><?= h((string) ($row['status'] ?? '')); ?></td>
              <td data-label="LINE名"><?= h((string) ($row['line_name'] ?? '')); ?></td>
              <td data-label="本名"><?= h((string) ($row['full_name'] ?? '')); ?></td>
              <td data-label="メールアドレス"><?= h((string) ($row['email'] ?? '')); ?></td>
              <td data-label="演者名"><?= h((string) ($row['video_staff_display'] ?? '')); ?></td>
              <td data-label="セールス名"><?= h((string) ($row['sales_staff'] ?? '')); ?></td>
              <td data-label="操作"><a class="btn btn-ghost" href="detail.php?<?= h(http_build_query(array_merge(['sheet_id' => (string) ($row['sheet_id'] ?? '')], $detailReturnParams))); ?>">詳細</a></td>
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
