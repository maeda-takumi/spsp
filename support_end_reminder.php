<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function readSupportEndReminderSettings(string $settingsPath): array
{
    if (!is_file($settingsPath)) {
        return [];
    }
    $raw = file_get_contents($settingsPath);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function sendSupportEndChatworkNotification(string $roomId, string $messageBody, array $mentionChatworkIds = []): void
{
    $roomId = trim($roomId);
    if ($roomId === '' || !preg_match('/^\d+$/', $roomId)) {
        throw new RuntimeException('ChatworkルームIDが不正です。');
    }

    $mentions = [];
    foreach ($mentionChatworkIds as $chatworkId) {
        $chatworkId = trim((string) $chatworkId);
        if ($chatworkId === '' || !preg_match('/^\d+$/', $chatworkId)) {
            continue;
        }
        $mentions[] = '[To:' . $chatworkId . ']';
    }

    $message = trim(implode(' ', $mentions) . "\n" . trim($messageBody));
    if ($message === '') {
        return;
    }

    $curl = curl_init('https://api.chatwork.com/v2/rooms/' . rawurlencode($roomId) . '/messages');
    if ($curl === false) {
        throw new RuntimeException('Chatwork通知の初期化に失敗しました。');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'X-ChatWorkToken: ' . CHATWORK_API_KEY,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'body' => $message,
        ]),
    ]);

    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($raw === false) {
        throw new RuntimeException('Chatwork通知送信に失敗しました。' . ($error !== '' ? ' ' . $error : ''));
    }

    if ($status >= 300) {
        throw new RuntimeException('Chatwork通知送信に失敗しました。HTTP Status: ' . $status);
    }
}

function resolveSupportEndNotificationTarget(string $salesStaff, array $settings): array
{
    $targets = is_array($settings['sales_staff_notifications'] ?? null) ? $settings['sales_staff_notifications'] : [];
    $target = is_array($targets[$salesStaff] ?? null) ? $targets[$salesStaff] : [];

    $roomId = trim((string) ($target['room_id'] ?? ''));
    $toId = trim((string) ($target['to_id'] ?? ''));

    if ($roomId === '') {
        $groupMap = is_array($settings['sales_staff_group_ids'] ?? null) ? $settings['sales_staff_group_ids'] : [];
        $roomId = trim((string) ($groupMap[$salesStaff] ?? ''));
    }
    if ($toId === '') {
        $toId = trim((string) ($settings['to_id'] ?? ''));
    }

    return ['room_id' => $roomId, 'to_id' => $toId];
}

function sendSupportEndReminderIfNeeded(array $record, array $supportEndDateRows): bool
{
    if ($supportEndDateRows === []) {
        return false;
    }

    $salesStaff = trim((string) ($record['sales_staff'] ?? ''));
    if ($salesStaff === '') {
        return false;
    }

    $settingsPath = __DIR__ . '/support_end_chatwork_settings.json';
    $settings = readSupportEndReminderSettings($settingsPath);
    $target = resolveSupportEndNotificationTarget($salesStaff, $settings);
    $roomId = $target['room_id'];
    if ($roomId === '' || !preg_match('/^\d+$/', $roomId)) {
        return false;
    }

    $mentions = [];
    if ($target['to_id'] !== '' && preg_match('/^\d+$/', $target['to_id'])) {
        $mentions[] = $target['to_id'];
    }

    $latestRow = $supportEndDateRows[0] ?? null;
    if (!is_array($latestRow)) {
        return false;
    }

    $supportSendTimestamp = strtotime((string) ($latestRow['send_date'] ?? ''));
    if ($supportSendTimestamp === false) {
        return false;
    }

    $notifyAt = strtotime('+5 months', $supportSendTimestamp);
    if ($notifyAt === false || strtotime(date('Y-m-d')) !== $notifyAt) {
        return false;
    }

    $logPath = __DIR__ . '/support_end_chatwork_notification_logs.json';
    $logs = readSupportEndReminderSettings($logPath);
    $sheetId = trim((string) ($record['sheet_id'] ?? ''));
    $logKey = $sheetId . '_' . date('Y-m-d', $notifyAt);
    if (isset($logs[$logKey])) {
        return false;
    }

    $supportEndDate = date('Y/m/d', strtotime('+6 months', $supportSendTimestamp));
    $message = "■サポート終了1ヶ月前通知\n"
        . "シートID: " . $sheetId . "\n"
        . "顧客名: " . trim((string) ($record['full_name'] ?? '')) . "\n"
        . "LINE名: " . trim((string) ($record['line_name'] ?? '')) . "\n"
        . "セールス担当: " . $salesStaff . "\n"
        . "サポート終了日: " . $supportEndDate;

    sendSupportEndChatworkNotification($roomId, $message, $mentions);
    $logs[$logKey] = [
        'notified_at' => date('c'),
        'sheet_id' => $sheetId,
        'sales_staff' => $salesStaff,
        'room_id' => $roomId,
    ];
    file_put_contents($logPath, json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return true;
}
