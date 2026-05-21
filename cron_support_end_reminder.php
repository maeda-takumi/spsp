<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/support_end_reminder.php';

function cronDb(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$pdo = cronDb();
$sql = 'SELECT csr.sheet_id, csr.full_name, csr.line_name, csr.sales_staff, rm.send_date
        FROM customer_sales_records csr
        INNER JOIN (
            SELECT sheet_id, MAX(send_date) AS send_date
            FROM request_management
            WHERE send_date IS NOT NULL
              AND send_date <> ""
            GROUP BY sheet_id
        ) rm ON rm.sheet_id = csr.sheet_id
        WHERE csr.sales_staff IS NOT NULL
          AND csr.sales_staff <> ""';

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll();
$sentCount = 0;
foreach ($rows as $row) {
    $record = [
        'sheet_id' => (string) ($row['sheet_id'] ?? ''),
        'full_name' => (string) ($row['full_name'] ?? ''),
        'line_name' => (string) ($row['line_name'] ?? ''),
        'sales_staff' => (string) ($row['sales_staff'] ?? ''),
    ];
    $supportEndDateRows = [['send_date' => (string) ($row['send_date'] ?? '')]];
    try {
        if (sendSupportEndReminderIfNeeded($record, $supportEndDateRows)) {
            $sentCount++;
        }
    } catch (Throwable $e) {
        error_log('サポート終了通知cronエラー sheet_id=' . $record['sheet_id'] . ' ' . $e->getMessage());
    }
}

echo 'checked=' . count($rows) . ', sent=' . $sentCount . PHP_EOL;
