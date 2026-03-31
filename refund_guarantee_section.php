<?php

declare(strict_types=1);

function refundGuaranteeQuestions(): array
{
    return [
        'understood_term' => '返金保証の適用期間を案内し、理解を得られた',
        'understood_scope' => '返金対象外となる条件を説明した',
        'understood_procedure' => '返金申請の手順（連絡先・期限）を説明した',
        'customer_agreed' => 'お客様が返金保証条件に同意した',
    ];
}

function ensureRefundGuaranteeTable(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS customer_refund_guarantee_checks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sheet_id VARCHAR(100) NOT NULL,
        question_key VARCHAR(100) NOT NULL,
        question_label VARCHAR(255) NOT NULL,
        is_checked TINYINT(1) NOT NULL DEFAULT 0,
        checked_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_sheet_question (sheet_id, question_key),
        KEY idx_sheet_id (sheet_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function fetchRefundGuaranteeStatuses(PDO $pdo, string $sheetId): array
{
    $stmt = $pdo->prepare('SELECT question_key, is_checked FROM customer_refund_guarantee_checks WHERE sheet_id = :sheet_id');
    $stmt->bindValue(':sheet_id', $sheetId);
    $stmt->execute();

    $statuses = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = (string) ($row['question_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $statuses[$key] = (int) ($row['is_checked'] ?? 0) === 1;
    }

    return $statuses;
}

function renderRefundGuaranteeSection(string $sheetId, array $statuses): void
{
    $questions = refundGuaranteeQuestions();
    ?>
    <section id="refund-guarantee" class="panel content-panel detail-panel">
      <div class="section-head">
        <h2>返金保証条件</h2>
        <button
          type="button"
          class="btn btn-icon section-toggle-button"
          data-section-toggle
          data-target-id="refund-guarantee-body"
          aria-controls="refund-guarantee-body"
          aria-expanded="false"
          aria-label="開ける"
        >
          <img src="img/open.png" alt="" loading="lazy" data-toggle-icon>
        </button>
      </div>
      <div id="refund-guarantee-body" class="collapsible-body" hidden>
        <p class="muted">チェック時に自動保存されます。</p>
        <div class="refund-guarantee-list" data-refund-guarantee-list>
          <?php foreach ($questions as $key => $label): ?>
            <label class="refund-guarantee-item">
              <input
                type="checkbox"
                data-refund-guarantee-checkbox
                data-question-key="<?= h($key); ?>"
                data-sheet-id="<?= h($sheetId); ?>"
                <?= !empty($statuses[$key]) ? 'checked' : ''; ?>
              >
              <span><?= h($label); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php
}
