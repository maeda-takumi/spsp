<?php

declare(strict_types=1);

$pageTitle = '困った一覧';
require __DIR__ . '/header.php';
?>
<section class="panel content-panel help-panel">
    <h2>困った一覧</h2>
    <ul class="help-list">
        <li>
            <a href="mail_error.php">メール送信に失敗しました。Token has been expired or revoked.</a>
        </li>
        <li>
            <a href="send_stop.php">メール送付通知を止めたい時</a>
        </li>
    </ul>
</section>
</main>
</body>
</html>