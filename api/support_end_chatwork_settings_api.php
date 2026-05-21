<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

function readJsonFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

$settingsPath = dirname(__DIR__) . '/support_end_chatwork_settings.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $settings = readJsonFile($settingsPath);
    echo json_encode([
        'to_id' => (string) ($settings['to_id'] ?? ''),
        'sales_staff_group_ids' => is_array($settings['sales_staff_group_ids'] ?? null) ? $settings['sales_staff_group_ids'] : [],
        'sales_staff_notifications' => is_array($settings['sales_staff_notifications'] ?? null) ? $settings['sales_staff_notifications'] : [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput !== false ? $rawInput : '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON形式が不正です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$toId = trim((string) ($payload['to_id'] ?? ''));
$groupMap = $payload['sales_staff_group_ids'] ?? [];
if (!is_array($groupMap)) {
    http_response_code(400);
    echo json_encode(['error' => 'sales_staff_group_idsの形式が不正です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$notifications = $payload['sales_staff_notifications'] ?? [];
if (!is_array($notifications)) {
    http_response_code(400);
    echo json_encode(['error' => 'sales_staff_notificationsの形式が不正です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$normalizedNotifications = [];
foreach ($notifications as $staffName => $target) {
    $name = trim((string) $staffName);
    if ($name === '') {
        continue;
    }
    $targetData = is_array($target) ? $target : [];
    $normalizedNotifications[$name] = [
        'to_id' => trim((string) ($targetData['to_id'] ?? '')),
        'room_id' => trim((string) ($targetData['room_id'] ?? $targetData['chatwork_id'] ?? '')),
        'chatwork_id' => trim((string) ($targetData['chatwork_id'] ?? $targetData['room_id'] ?? '')),
    ];
}

$normalizedMap = [];
foreach ($groupMap as $staffName => $chatworkGroupId) {
    $name = trim((string) $staffName);
    if ($name === '') {
        continue;
    }
    $normalizedMap[$name] = trim((string) $chatworkGroupId);
}

$data = [
    'to_id' => $toId,
    'sales_staff_group_ids' => $normalizedMap,
    'sales_staff_notifications' => $normalizedNotifications,
    'updated_at' => date('c'),
];

$result = file_put_contents($settingsPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
if ($result === false) {
    http_response_code(500);
    echo json_encode(['error' => '設定の保存に失敗しました。'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
