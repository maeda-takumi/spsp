<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="css/style.css?v=<?= time(); ?>">
</head>
<body>
<div class="bg-blur bg-blur-top" aria-hidden="true"></div>
<div class="bg-blur bg-blur-bottom" aria-hidden="true"></div>
<main class="page-wrap">
