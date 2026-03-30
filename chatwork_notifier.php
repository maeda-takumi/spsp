<?php

declare(strict_types=1);

const CHATWORK_API_KEY = 'fee574510c5ce22d78b85282a0a8acaa';
const CHATWORK_ROOM_ID = '404615917';
const DEFAULT_CHATWORK_MESSAGE_TEMPLATE = "■新規 送付メール送信完了■※カリキュラム表、個別活動表送付OK\n【[メールテンプレート名]】 ①[sales_staff]\n[full_name]（[line_name]）";

function renderChatworkMessageTemplate(string $template, array $context): string
{
    $replacements = [
        '[メールテンプレート名]' => (string) ($context['template_name'] ?? ''),
        '[template_name]' => (string) ($context['template_name'] ?? ''),
        '[sales_staf]' => (string) ($context['sales_staff'] ?? ''),
        '[sales_staff]' => (string) ($context['sales_staff'] ?? ''),
        '[full_name]' => (string) ($context['full_name'] ?? ''),
        '[line_name]' => (string) ($context['line_name'] ?? ''),
    ];

    return trim(strtr($template, $replacements));
}

function sendChatworkNotification(string $messageBody, array $mentionChatworkIds = []): void
{
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

    $curl = curl_init('https://api.chatwork.com/v2/rooms/' . rawurlencode(CHATWORK_ROOM_ID) . '/messages');
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
