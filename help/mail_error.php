<?php
    include ("header.php");
?>
<div class="mail-error-body">
  <main class="mail-error-container">
    <section class="panel" style="padding: 20px;">
      <h1 class="mail-error-title">メール送信エラー</h1>
      <p class="mail-error-lead">
        エラー原因：認証ファイルの期限切れ<br>
        以下の手順で再認証を行って下さい
      </p>

      <ol class="mail-error-steps">
        <li class="mail-error-step">
          <p class="mail-error-step-text">systemsoufu@gmail.comのグーグルアカウントにログイン</p>
          <img src="img/email_0.PNG" alt="Googleアカウントにログイン" class="mail-error-image" />
        </li>
        <li class="mail-error-step">
          <p class="mail-error-step-text">
            <a href="https://totalappworks.com/supsup_neo/oauth_gmail_token.php" target="_blank" rel="noopener noreferrer">https://totalappworks.com/supsup_neo/oauth_gmail_token.php</a>へアクセス
          </p>
        </li>
        <li class="mail-error-step">
          <p class="mail-error-step-text">Googleで許可するをクリック</p>
          <img src="img/email_1.PNG" alt="Googleで許可するをクリック" class="mail-error-image" />
        </li>
        <li class="mail-error-step">
          <p class="mail-error-step-text">systemsoufu@gmail.comを選択</p>
          <img src="img/email_2.PNG" alt="systemsoufu@gmail.comを選択" class="mail-error-image" />
        </li>
        <li class="mail-error-step">
          <p class="mail-error-step-text">詳細→totalappworks.com（安全ではないページ）に移動の順でクリック</p>
          <img src="img/email_3.PNG" alt="詳細から安全ではないページへ移動" class="mail-error-image" />
        </li>
        <li class="mail-error-step">
          <p class="mail-error-step-text">続行をクリック</p>
          <img src="img/email_4.PNG" alt="続行をクリック" class="mail-error-image" />
        </li>
        <li class="mail-error-step">
          <p class="mail-error-step-text">成功と出たらOK</p>
          <img src="img/email_5.PNG" alt="成功画面" class="mail-error-image" />
        </li>
      </ol>
    </section>
  </main>
</div>
</html>
