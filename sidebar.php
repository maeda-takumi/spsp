<?php

declare(strict_types=1);

/**
 * サイドバー「サポート終了管理一覧」のアラート画像表示フラグを取得。
 * 今日+30日に該当する support_end_date が1件でも存在するとtrue。
 */
function getSupportEndAlertFlag(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query(
            "SELECT 1 FROM support_end_date_overrides
             WHERE support_end_date = DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)
             LIMIT 1"
        );
        if ($stmt === false) {
            return false;
        }
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function renderSidebar(string $currentPage, array $options = []): void
{
    $hasPendingRequest = (bool) ($options['hasPendingRequest'] ?? false);
    $hasOverduePendingRequest = (bool) ($options['hasOverduePendingRequest'] ?? false);
    $hasUpcomingSupportEnd = (bool) ($options['hasUpcomingSupportEnd'] ?? false);
    $lineName = (string) ($options['lineName'] ?? '');
    $detailBackUrl = (string) ($options['detailBackUrl'] ?? '');

    $baseLinks = [
        [
            'key' => 'index',
            'href' => 'index.php',
            'label' => '顧客一覧',
        ],
        [
            'key' => 'request_management',
            'href' => 'request_management.php',
            'label' => '送付依頼一覧',
            'class' => 'side-nav__request-link',
        ],
        [
            'key' => 'support_end_users',
            'href' => 'support_end_users.php',
            'label' => 'サポート終了管理一覧',
        ],
    ];

    if ($currentPage === 'detail') {
        $baseLinks[] = [
            'key' => 'detail',
            'href' => '#',
            'label' => '顧客詳細',
        ];
    }

    $isCustomerShell = in_array($currentPage, ['index', 'detail'], true);
    ?>
    <aside class="side-panel <?= $isCustomerShell ? 'side-panel--customer' : 'side-panel--support-end'; ?>">
      <h1>SUP-SUP NEO</h1>
      <p><?= $lineName !== '' ? h($lineName) : 'PAGES'; ?></p>
      <nav class="side-nav" aria-label="メニュー">
        <?php if ($currentPage === 'detail' && $detailBackUrl !== ''): ?>
          <a href="<?= h($detailBackUrl); ?>">一覧へ戻る</a>
        <?php endif; ?>
        <?php foreach ($baseLinks as $link): ?>
          <?php
            $classes = [];
            if (!empty($link['class'])) {
                $classes[] = (string) $link['class'];
            }
            if ($currentPage === $link['key']) {
                $classes[] = 'is-current';
            }
          ?>
          <a class="<?= h(implode(' ', $classes)); ?>" href="<?= h((string) $link['href']); ?>">
            <span><?= h((string) $link['label']); ?></span>
            <?php if ($link['key'] === 'request_management' && $hasPendingRequest): ?>
              <img class="side-nav__alert-icon" src="<?= $hasOverduePendingRequest ? 'img/dokuro.png' : 'img/alert.png'; ?>" alt="未完了の送付依頼あり" loading="lazy">
            <?php endif; ?>
            <?php if ($link['key'] === 'support_end_users' && $hasUpcomingSupportEnd): ?>
              <img class="side-nav__alert-icon" src="img/alert.png" alt="サポート終了予定あり" loading="lazy">
            <?php endif; ?>
          </a>
        <?php endforeach; ?>

        <p>Application</p>
        <div class="side-nav__app-link">
          <a href="https://totalappworks.com/support_aori/" target="_blank" rel="noopener noreferrer">Bull-Fight</a>
          <img src="img/aori.png" alt="Bull-Fight" loading="lazy">
        </div>
        <div class="side-nav__app-link">
          <a href="https://totalappworks.com/support_support/curriculum_answers_list.php" target="_blank" rel="noopener noreferrer">フィードバック</a>
          <img src="img/fb.png" alt="フィードバック" loading="lazy">
        </div>
        <div class="side-nav__app-link">
          <a href="http://schoolai.biz/curriculum/login/admin.php" target="_blank" rel="noopener noreferrer">カリキュラム管理</a>
          <img src="img/user.png" alt="カリキュラム管理" loading="lazy">
        </div>
        <div class="side-nav__app-link">
          <a href="https://totalappworks.com/chatwork/" target="_blank" rel="noopener noreferrer">ChatSave</a>
          <img src="img/chatsave.png" alt="ChatSave" loading="lazy">
        </div>
        <div class="side-nav__app-link">
          <a href="https://step.lme.jp/basic/chat-v3?lastTimeUpdateFriend=0" target="_blank" rel="noopener noreferrer">LMessage</a>
          <img src="img/lme.png" alt="LMessage" loading="lazy">
        </div>
        <div class="side-nav__app-link">
          <a href="https://docs.google.com/document/d/1Cq5sYRV-Ppj4r-ld_y-5OfLGeHSKmM2qAikmW2LPYJk/edit?tab=t.5bcbhp93fbnt" target="_blank" rel="noopener noreferrer">フローマニュアル</a>
          <img src="img/doc.png" alt="フローマニュアル" loading="lazy">
        </div>
      </nav>
    </aside>
    <?php
}
