<?php

declare(strict_types=1);
// 本番用
const CHATWORK_API_KEY = '148e610a926709fbe91778aa36d8612c';
const CHATWORK_ROOM_ID = '383085827';
// テスト用
// const CHATWORK_API_KEY = 'fee574510c5ce22d78b85282a0a8acaa';
// const CHATWORK_ROOM_ID = '404615917';
const DEFAULT_CHATWORK_MESSAGE_TEMPLATE = "■新規 送付メール送信完了■※カリキュラム表、個別活動表送付OK\n【db[template_name]】 mention[sales_staff]\ndb[full_name]（db[line_name]）";

function renderChatworkMessageTemplate(string $template, array $context, array $mentionMastersByName, array $actorMastersByName = []): string
{
    $legacyPlaceholders = [
        'メールテンプレート名' => 'db[template_name]',
        'template_name' => 'db[template_name]',
        'sales_staf' => 'db[sales_staff]',
        'sales_staff' => 'db[sales_staff]',
        'full_name' => 'db[full_name]',
        'line_name' => 'db[line_name]',
        'video_staff' => 'db[video_staff]',
    ];

    // 旧記法の [xxx] を db[xxx] に正規化する。
    // mention[xxx] / db[xxx] の内部までは変換しないよう、直前が英数字/アンダースコアのケースは除外する。
    $normalizedTemplate = preg_replace_callback('/(?<![a-zA-Z0-9_])\[([^\[\]]+)\]/u', static function (array $matches) use ($legacyPlaceholders): string {
        $placeholder = trim((string) $matches[1]);
        return $legacyPlaceholders[$placeholder] ?? $matches[0];
    }, $template);

    if (!is_string($normalizedTemplate)) {
        throw new RuntimeException('Chatwork通知テンプレートの正規化に失敗しました。');
    }
    $usedMentionChatworkIds = [];
    $rendered = preg_replace_callback('/(db|mention|actor)\[([a-zA-Z0-9_]+)\]/', static function (array $matches) use ($context, $mentionMastersByName, $actorMastersByName, &$usedMentionChatworkIds): string {
        $type = $matches[1];
        $key = $matches[2];
        if (!array_key_exists($key, $context)) {
            throw new RuntimeException(sprintf('%s[%s] の参照キーが存在しません。', $type, $key));
        }

        if ($type === 'db') {
            return trim((string) $context[$key]);
        }

        $lookupName = trim((string) $context[$key]);
        if ($lookupName === '') {
            throw new RuntimeException(sprintf('%s[%s] の参照値が空です。', $type, $key));
        }

        if ($type === 'actor') {
            $actorCandidates = $actorMastersByName[$lookupName] ?? [];
            if (count($actorCandidates) !== 1) {
                throw new RuntimeException(sprintf('actor[%s] の参照値「%s」に一致する担当者が%s件です。', $key, $lookupName, count($actorCandidates)));
            }

            $actorDisplayName = trim((string) ($actorCandidates[0]['actor_name'] ?? ''));
            if ($actorDisplayName === '') {
                throw new RuntimeException(sprintf('actor[%s] のactor_nameが不正です。', $key));
            }

            return $actorDisplayName;
        }

        if ($key === 'video_staff' && ($lookupName === 'しらほしなつみ' || $lookupName === '坂口亮' || $lookupName === '三木悠斗' || $lookupName === '蝦名真人')) {
            return '';
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
        if (isset($usedMentionChatworkIds[$chatworkId])) {
            return '';
        }
        $usedMentionChatworkIds[$chatworkId] = true;


        return '[To:' . $chatworkId . ']' . $mentionName . 'さん';
    }, $normalizedTemplate);

    if (!is_string($rendered)) {
        throw new RuntimeException('Chatwork通知テンプレートの展開に失敗しました。');
    }

    $rendered = preg_replace('/[ \t]+(\r?\n)/', '$1', $rendered);
    if (!is_string($rendered)) {
        throw new RuntimeException('Chatwork通知テンプレートの整形に失敗しました。');
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
