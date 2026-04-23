<?php

declare(strict_types=1);

$pageTitle = '通知停止の手順';
require __DIR__ . '/header.php';
?>
<section class="panel" style="padding: 20px;">
    <h2 class="mail-error-title">通知を止める方法</h2>

    <ol class="mail-error-steps">
        <li class="mail-error-step">
            <p class="mail-error-step-text">
                まず、以下のスプレッドシートを開きます。<br>
                <a href="https://docs.google.com/spreadsheets/d/1mDccfeN9sR8OJdWLv6wPN0DzRr5Y5OfLSmrjjHOvMIs/edit?gid=0#gid=0" target="_blank" rel="noopener noreferrer">
                    https://docs.google.com/spreadsheets/d/1mDccfeN9sR8OJdWLv6wPN0DzRr5Y5OfLSmrjjHOvMIs/edit?gid=0#gid=0
                </a>
            </p>
        </li>

        <li class="mail-error-step">
            <p class="mail-error-step-text">
                停止したい通知の種類に応じて、対象シートを開きます。<br>
                ・サポート面談の通知を止めたい場合：<strong>依頼用_サポート面談後</strong><br>
                ・目次面談の通知を止めたい場合：<strong>送付管理</strong>
            </p>
        </li>

        <li class="mail-error-step">
            <p class="mail-error-step-text">
                「送付管理」シートでの止め方：<br>
                通知が来ている対象者のデータを探し、該当行の <strong>N列</strong> と <strong>P列</strong> にチェックを入れます。
            </p>
            <img src="img/send_stop_1.png" alt="送付管理シートでN列とP列にチェックを入れる" class="mail-error-image" />
        </li>

        <li class="mail-error-step">
            <p class="mail-error-step-text">
                「依頼用_サポート面談後」シートでの止め方：<br>
                通知が来ている対象者のデータを探し、該当行の <strong>I列</strong> の選択肢を <strong>送付完了</strong> に変更します。
            </p>
            <img src="img/send_stop_0.png" alt="依頼用_サポート面談後シートでI列を送付完了に変更" class="mail-error-image" />
        </li>
    </ol>
</section>
</main>
</body>
</html>
