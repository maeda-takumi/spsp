<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Dashboard';
$showImportButton = $showImportButton ?? false;
$importCompletedAt = isset($importCompletedAt) && is_string($importCompletedAt) ? trim($importCompletedAt) : '';
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/png" href="img/icon2.png">
    <link rel="shortcut icon" type="image/png" href="img/icon2.png">
    <link rel="apple-touch-icon" href="img/icon2.png">
    <link rel="stylesheet" href="css/style.css?v=<?= time(); ?>">
</head>
<body>
<div class="global-loading-overlay" data-global-loading-overlay hidden>
    <div class="global-loading-overlay__content">
        <p>取り込み中...</p>
    </div>
</div>
<div class="bg-blur bg-blur-top" aria-hidden="true"></div>
<div class="bg-blur bg-blur-bottom" aria-hidden="true"></div>
<main class="page-wrap">
    <header class="app-header panel">
        <div class="app-header-tools">
            <a class="btn btn-icon" href="help/index.php" aria-label="ヘルプを開く">
                <img src="img/help.png" alt="" loading="lazy">
            </a>
            <span class="import-completed-at" data-import-completed-at>
                <?= $importCompletedAt !== '' ? '最終取込: ' . htmlspecialchars($importCompletedAt, ENT_QUOTES, 'UTF-8') : '最終取込: 未実行'; ?>
            </span>
            <button type="button" class="btn btn-icon" data-run-import-sheet aria-label="シートをDBに取り込み">
                <img src="img/db.png" alt="" loading="lazy">
            </button>
        </div>
    </header>
