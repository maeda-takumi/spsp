<?php

declare(strict_types=1);

const SUPPORT_INTERVIEW_MAIL_COMPLETION_SPREADSHEET_ID = '1mDccfeN9sR8OJdWLv6wPN0DzRr5Y5OfLSmrjjHOvMIs';
const SUPPORT_INTERVIEW_MAIL_COMPLETION_SHEET_NAME = '依頼用_サポート面談後';

function supportInterviewMailCompletionFindRowNumberBySheetId(string $targetSheetId, string $accessToken): ?int
{
    $range = rawurlencode(SUPPORT_INTERVIEW_MAIL_COMPLETION_SHEET_NAME . '!B:B');
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode(SUPPORT_INTERVIEW_MAIL_COMPLETION_SPREADSHEET_ID)
        . '/values/'
        . $range;

    $response = supportInterviewHttpGetJson($url, ['Authorization: Bearer ' . $accessToken]);
    $values = $response['values'] ?? [];
    if (!is_array($values)) {
        throw new RuntimeException('依頼用_サポート面談後シートのB列取得に失敗しました。');
    }

    foreach ($values as $index => $row) {
        $cellValue = trim((string) ($row[0] ?? ''));
        if ($cellValue !== '' && $cellValue === $targetSheetId) {
            return $index + 1;
        }
    }

    return null;
}

function supportInterviewMailCompletionMarkSent(int $rowNumber, string $accessToken): void
{
    $range = SUPPORT_INTERVIEW_MAIL_COMPLETION_SHEET_NAME . '!I' . $rowNumber;
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode(SUPPORT_INTERVIEW_MAIL_COMPLETION_SPREADSHEET_ID)
        . '/values:batchUpdate';

    $payload = [
        'valueInputOption' => 'USER_ENTERED',
        'data' => [
            [
                'range' => $range,
                'majorDimension' => 'ROWS',
                'values' => [
                    ['送付完了'],
                ],
            ],
        ],
    ];

    supportInterviewHttpPostJson($url, $payload, ['Authorization: Bearer ' . $accessToken]);
}

function appendSupportInterviewMailCompletedForSupportInterview(array $record): void
{
    $sheetId = trim((string) ($record['sheet_id'] ?? ''));
    if ($sheetId === '') {
        throw new RuntimeException('連携対象のsheet_idがありません。');
    }

    $serviceAccountFilePath = __DIR__ . '/' . SUPPORT_INTERVIEW_SERVICE_ACCOUNT_FILE;
    $accessToken = supportInterviewFetchAccessToken($serviceAccountFilePath);
    $rowNumber = supportInterviewMailCompletionFindRowNumberBySheetId($sheetId, $accessToken);

    if ($rowNumber === null) {
        throw new RuntimeException('依頼用_サポート面談後シートにsheet_id=' . $sheetId . ' の行が見つかりません。');
    }

    supportInterviewMailCompletionMarkSent($rowNumber, $accessToken);
}
