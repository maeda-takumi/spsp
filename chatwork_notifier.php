<?php

declare(strict_types=1);

const CHATWORK_API_KEY = 'fee574510c5ce22d78b85282a0a8acaa';
const CHATWORK_ROOM_ID = '404615917';
const DEFAULT_CHATWORK_MESSAGE_TEMPLATE = "■新規 送付メール送信完了■※カリキュラム表、個別活動表送付OK\n【db[template_name]】 mention[sales_staff]\ndb[full_name]（db[line_name]）";

function renderChatworkMessageTemplate(string $template, array $context, array $mentionMastersByName): string
{
    $normalizedTemplate = strtr($template, [
        '[メールテンプレート名]' => 'db[template_name]',
        '[template_name]' => 'db[template_name]',
        '[sales_staf]' => 'db[sales_staff]',
        '[sales_staff]' => 'db[sales_staff]',
        '[full_name]' => 'db[full_name]',
        '[line_name]' => 'db[line_name]',
        '[video_staff]' => 'db[video_staff]',
    ]);

    $rendered = preg_replace_callback('/(db|mention)\[([a-zA-Z0-9_]+)\]/', static function (array $matches) use ($context, $mentionMastersByName): string {
        $type = $matches[1];
        $key = $matches[2];
        if (!array_key_exists($key, $context)) {
            throw new RuntimeException(sprintf('Chatwork通知テンプレートのキー「%s」が存在しません。', $key));
        }

        if ($type === 'db') {
            return trim((string) $context[$key]);
        }

        $lookupName = trim((string) $context[$key]);
        if ($lookupName === '') {
            throw new RuntimeException(sprintf('mention[%s] の参照値が空です。', $key));
        }

        $mentionCandidates = $mentionMastersByName[$lookupName] ?? [];
        if (count($mentionCandidates) !== 1) {
            throw new RuntimeException(sprintf('mention[%s] の参照値「%s」に一致するメンション先が%s件です。', $key, $lookupName, count($mentionCandidates)));
        }

        $chatworkId = trim((string) ($mentionCandidates[0]['chatwork_id'] ?? ''));
        if ($chatworkId === '' || !preg_match('/^\d+$/', $chatworkId)) {
            throw new RuntimeException(sprintf('mention[%s] のchatwork_idが不正です。', $key));
        }

        $mentionName = trim((string) ($mentionCandidates[0]['name'] ?? $lookupName));
        if ($mentionName === '') {
            $mentionName = $lookupName;
        }

        return '[To:' . $chatworkId . ']' . $mentionName . 'さん';
    }, $normalizedTemplate);

    if (!is_string($rendered)) {
        throw new RuntimeException('Chatwork通知テンプレートの展開に失敗しました。');
    }

    return trim($rendered);
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
