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
$sql = 'SELECT csr.sheet_id, csr.full_name, csr.line_name, csr.sales_staff, rm.send_date, seo.support_end_date, seo.updated_at AS support_end_date_updated_at
        FROM customer_sales_records csr
        INNER JOIN (
            SELECT sheet_id, MAX(send_date) AS send_date
            FROM request_management
            WHERE send_date IS NOT NULL
              AND send_date <> ""
            GROUP BY sheet_id
        LEFT JOIN support_end_date_overrides seo ON seo.sheet_id = csr.sheet_id
        WHERE csr.sales_staff IS NOT NULL
          AND csr.sales_staff <> ""';

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll();
$sentCount = 0;
$skipCount = 0;
$errorCount = 0;
$today = date('Y-m-d');
foreach ($rows as $row) {
    $record = [
        'sheet_id' => (string) ($row['sheet_id'] ?? ''),
        'full_name' => (string) ($row['full_name'] ?? ''),
        'line_name' => (string) ($row['line_name'] ?? ''),
        'sales_staff' => (string) ($row['sales_staff'] ?? ''),
    ];
    $sendDate = (string) ($row['send_date'] ?? '');
    $supportEndDateRows = [[
        'send_date' => $sendDate,
        'support_end_date' => (string) ($row['support_end_date'] ?? ''),
        'support_end_date_updated_at' => (string) ($row['support_end_date_updated_at'] ?? ''),
    ]];

    $sendTimestamp = strtotime($sendDate);
    $notifyAt = $sendTimestamp === false ? false : strtotime('+5 months', $sendTimestamp);
    $notifyAtText = $notifyAt === false ? 'invalid-send-date' : date('Y-m-d', $notifyAt);
    try {
        if (sendSupportEndReminderIfNeeded($record, $supportEndDateRows)) {
            $sentCount++;
            echo 'SENT sheet_id=' . $record['sheet_id']
                . ' sales_staff=' . $record['sales_staff']
                . ' send_date=' . $sendDate
                . ' notify_at=' . $notifyAtText
                . PHP_EOL;
            continue;
        }
        $skipCount++;
        echo 'SKIP sheet_id=' . $record['sheet_id']
            . ' sales_staff=' . $record['sales_staff']
            . ' send_date=' . $sendDate
            . ' notify_at=' . $notifyAtText
            . ' today=' . $today
            . PHP_EOL;
    } catch (Throwable $e) {
        $errorCount++;
        $message = 'ERROR sheet_id=' . $record['sheet_id']
            . ' sales_staff=' . $record['sales_staff']
            . ' send_date=' . $sendDate
            . ' notify_at=' . $notifyAtText
            . ' message=' . $e->getMessage();
        error_log('サポート終了通知cronエラー sheet_id=' . $record['sheet_id'] . ' ' . $e->getMessage());
        echo $message . PHP_EOL;
    }
}

echo 'checked=' . count($rows)
    . ', sent=' . $sentCount
    . ', skipped=' . $skipCount
    . ', errors=' . $errorCount
    . PHP_EOL;
