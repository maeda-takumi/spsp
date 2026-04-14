<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? 'ヘルプ';
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/png" href="../img/icon2.png">
    <link rel="shortcut icon" type="image/png" href="../img/icon2.png">
    <link rel="apple-touch-icon" href="../img/icon2.png">
    <link rel="stylesheet" href="../css/style.css?v=<?= time(); ?>">
</head>
<body>
<div class="bg-blur bg-blur-top" aria-hidden="true"></div>
<div class="bg-blur bg-blur-bottom" aria-hidden="true"></div>
<main class="page-wrap">
    <header class="app-header panel help-page-header">
        <h1 class="app-header-title">HELP</h1>
        <div class="app-header-tools">
            <a class="btn btn-ghost" href="../index.php">一覧へ戻る</a>
        </div>
    </header>