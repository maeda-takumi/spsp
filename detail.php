<?php

declare(strict_types=1);

require_once 'config.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function db(): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$sheetId = trim((string) ($_GET['sheet_id'] ?? ''));
if ($sheetId === '') {
    http_response_code(400);
    echo 'sheet_id が指定されていません。';
    exit;
}

$pdo = db();

$recordStmt = $pdo->prepare('SELECT * FROM customer_sales_records WHERE sheet_id = :sheet_id LIMIT 1');
$recordStmt->bindValue(':sheet_id', $sheetId);
$recordStmt->execute();
$record = $recordStmt->fetch();

if (!$record) {
    http_response_code(404);
    echo '対象の顧客レコードが見つかりません。';
    exit;
}

$pdo->exec('CREATE TABLE IF NOT EXISTS email_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_name VARCHAR(255) NOT NULL,
    mail_subject VARCHAR(255) NOT NULL,
    mail_body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$pdo->exec('CREATE TABLE IF NOT EXISTS customer_sales_record_email_drafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_sales_record_id BIGINT UNSIGNED NOT NULL,
    email_template_id BIGINT UNSIGNED NULL,
    mail_subject VARCHAR(255) NULL,
    mail_body TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_customer_sales_record_id (customer_sales_record_id),
    CONSTRAINT fk_customer_sales_record_email_drafts_record_id
        FOREIGN KEY (customer_sales_record_id)
        REFERENCES customer_sales_records (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_customer_sales_record_email_drafts_template_id
        FOREIGN KEY (email_template_id)
        REFERENCES email_templates (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$pdo->exec('CREATE TABLE IF NOT EXISTS customer_sales_record_email_send_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_sales_record_id BIGINT UNSIGNED NOT NULL,
    email_template_id BIGINT UNSIGNED NULL,
    mail_subject VARCHAR(255) NOT NULL,
    mail_body TEXT NOT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_sales_record_email_send_logs_record_id (customer_sales_record_id),
    CONSTRAINT fk_customer_sales_record_email_send_logs_record_id
        FOREIGN KEY (customer_sales_record_id)
        REFERENCES customer_sales_records (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_customer_sales_record_email_send_logs_template_id
        FOREIGN KEY (email_template_id)
        REFERENCES email_templates (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$recordId = (int) ($record['id'] ?? 0);
$draftStmt = $pdo->prepare('SELECT email_template_id, mail_subject, mail_body FROM customer_sales_record_email_drafts WHERE customer_sales_record_id = :record_id LIMIT 1');
$draftStmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
$draftStmt->execute();
$existingDraft = $draftStmt->fetch() ?: [];

$errors = [];
$formValues = [
    'writing' => '',
    'writing_notes' => '',
    'email_template_id' => isset($existingDraft['email_template_id']) ? (string) $existingDraft['email_template_id'] : '',
    'mail_subject' => (string) ($existingDraft['mail_subject'] ?? ''),
    'mail_body' => (string) ($existingDraft['mail_body'] ?? ''),
    'template_name' => '',
    'template_subject' => '',
    'template_body' => '',
    'template_id' => '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    $writingId = (int) ($_POST['writing_id'] ?? 0);
    $writing = trim((string) ($_POST['writing'] ?? ''));
    $writingNotes = trim((string) ($_POST['writing_notes'] ?? ''));
    $formValues['writing'] = $writing;
    $formValues['writing_notes'] = $writingNotes;
    $audioFile = $_FILES['audio_file'] ?? null;
    $recordId = (int) ($record['id'] ?? 0);
    $isUpdate = $action === 'update';
    $isDelete = $action === 'delete';
    $isWritingAction = $action === 'create' || $isUpdate || $isDelete;
    $targetWriting = null;
    $fileName = '';

    if ($action === 'template_create' || $action === 'template_update' || $action === 'template_delete') {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $templateName = trim((string) ($_POST['template_name'] ?? ''));
        $templateSubject = trim((string) ($_POST['template_subject'] ?? ''));
        $templateBody = trim((string) ($_POST['template_body'] ?? ''));
        $formValues['template_id'] = (string) $templateId;
        $formValues['template_name'] = $templateName;
        $formValues['template_subject'] = $templateSubject;
        $formValues['template_body'] = $templateBody;

        if ($action === 'template_delete') {
            if ($templateId <= 0) {
                $errors[] = '削除対象テンプレートが不正です。';
            } else {
                $deleteTemplateStmt = $pdo->prepare('DELETE FROM email_templates WHERE id = :id');
                $deleteTemplateStmt->bindValue(':id', $templateId, PDO::PARAM_INT);
                $deleteTemplateStmt->execute();
                header('Location: detail.php?sheet_id=' . rawurlencode($sheetId) . '&template_deleted=1&refresh=' . time() . '#email-compose');
                exit;
            }
        } else {
            if ($templateName === '' || $templateSubject === '' || $templateBody === '') {
                $errors[] = 'テンプレート名・件名・本文は必須です。';
            } elseif ($action === 'template_create') {
                $createTemplateStmt = $pdo->prepare('INSERT INTO email_templates (template_name, mail_subject, mail_body) VALUES (:template_name, :mail_subject, :mail_body)');
                $createTemplateStmt->bindValue(':template_name', $templateName);
                $createTemplateStmt->bindValue(':mail_subject', $templateSubject);
                $createTemplateStmt->bindValue(':mail_body', $templateBody);
                $createTemplateStmt->execute();
                header('Location: detail.php?sheet_id=' . rawurlencode($sheetId) . '&template_saved=1&refresh=' . time() . '#email-compose');
                exit;
            } else {
                if ($templateId <= 0) {
                    $errors[] = '更新対象テンプレートが不正です。';
                } else {
                    $updateTemplateStmt = $pdo->prepare('UPDATE email_templates SET template_name = :template_name, mail_subject = :mail_subject, mail_body = :mail_body WHERE id = :id');
                    $updateTemplateStmt->bindValue(':template_name', $templateName);
                    $updateTemplateStmt->bindValue(':mail_subject', $templateSubject);
                    $updateTemplateStmt->bindValue(':mail_body', $templateBody);
                    $updateTemplateStmt->bindValue(':id', $templateId, PDO::PARAM_INT);
                    $updateTemplateStmt->execute();
                    header('Location: detail.php?sheet_id=' . rawurlencode($sheetId) . '&template_saved=1&refresh=' . time() . '#email-compose');
                    exit;
                }
            }
        }
    } elseif ($action === 'save_email_draft' || $action === 'send_email') {
        $templateId = (int) ($_POST['email_template_id'] ?? 0);
        $mailSubject = trim((string) ($_POST['mail_subject'] ?? ''));
        $mailBody = trim((string) ($_POST['mail_body'] ?? ''));
        $slideConfirmed = (string) ($_POST['slide_confirmed'] ?? '');
        $formValues['email_template_id'] = $templateId > 0 ? (string) $templateId : '';
        $formValues['mail_subject'] = $mailSubject;
        $formValues['mail_body'] = $mailBody;

        if ($mailSubject === '' || $mailBody === '') {
            $errors[] = '件名と本文を入力してください。';
        }

        if ($action === 'send_email' && $slideConfirmed !== '1') {
            $errors[] = '送信スライドを完了してください。';
        }

        if ($errors === []) {
            $upsertDraftStmt = $pdo->prepare(
                'INSERT INTO customer_sales_record_email_drafts (customer_sales_record_id, email_template_id, mail_subject, mail_body)
                 VALUES (:record_id, :template_id, :mail_subject, :mail_body)
                 ON DUPLICATE KEY UPDATE email_template_id = VALUES(email_template_id), mail_subject = VALUES(mail_subject), mail_body = VALUES(mail_body)'
            );
            $upsertDraftStmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
            $upsertDraftStmt->bindValue(':template_id', $templateId > 0 ? $templateId : null, $templateId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $upsertDraftStmt->bindValue(':mail_subject', $mailSubject);
            $upsertDraftStmt->bindValue(':mail_body', $mailBody);
            $upsertDraftStmt->execute();

            if ($action === 'send_email') {
                $sendLogStmt = $pdo->prepare(
                    'INSERT INTO customer_sales_record_email_send_logs (customer_sales_record_id, email_template_id, mail_subject, mail_body)
                     VALUES (:record_id, :template_id, :mail_subject, :mail_body)'
                );
                $sendLogStmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
                $sendLogStmt->bindValue(':template_id', $templateId > 0 ? $templateId : null, $templateId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $sendLogStmt->bindValue(':mail_subject', $mailSubject);
                $sendLogStmt->bindValue(':mail_body', $mailBody);
                $sendLogStmt->execute();
                header('Location: detail.php?sheet_id=' . rawurlencode($sheetId) . '&mail_sent=1&refresh=' . time() . '#email-compose');
                exit;
            }

            header('Location: detail.php?sheet_id=' . rawurlencode($sheetId) . '&draft_saved=1&refresh=' . time() . '#email-compose');
            exit;
        }
    }

    if ($isWritingAction && ($isUpdate || $isDelete) && $writingId <= 0) {
        $errors[] = '対象データが不正です。';
    }

    if ($isWritingAction && ($isUpdate || $isDelete) && $errors === []) {
        $targetStmt = $pdo->prepare('SELECT id, file_name FROM customer_sales_record_writings WHERE id = :id AND sheet_id = :sheet_id LIMIT 1');
        $targetStmt->bindValue(':id', $writingId, PDO::PARAM_INT);
        $targetStmt->bindValue(':sheet_id', $recordId, PDO::PARAM_INT);
        $targetStmt->execute();
        $targetWriting = $targetStmt->fetch();

        if (!$targetWriting) {
            $errors[] = '対象のwritingデータが見つかりません。';
        } else {
            $fileName = (string) ($targetWriting['file_name'] ?? '');
        }
    }

    if ($isWritingAction && $isDelete && $errors === []) {
        $deleteStmt = $pdo->prepare('DELETE FROM customer_sales_record_writings WHERE id = :id AND sheet_id = :sheet_id');
        $deleteStmt->bindValue(':id', $writingId, PDO::PARAM_INT);
        $deleteStmt->bindValue(':sheet_id', $recordId, PDO::PARAM_INT);
        $deleteStmt->execute();

        header('Location: detail.php?sheet_id=' . rawurlencode($sheetId) . '&deleted=1&refresh=' . time() . '#writing-list');
        exit;
    }

    if ($isWritingAction && !$isDelete) {
        $isNoFile = !is_array($audioFile) || (int) ($audioFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;
        if (!$isUpdate && $isNoFile) {
            $errors[] = '音声ファイルは必須です。';
        } elseif (!$isNoFile && (int) $audioFile['error'] !== UPLOAD_ERR_OK) {
            $errors[] = '音声ファイルのアップロードに失敗しました。';
        } elseif (!$isNoFile) {
            $originalName = (string) ($audioFile['name'] ?? '');
            $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedExtensions = ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac', 'webm', 'mp4'];
            if ($extension !== '' && !in_array($extension, $allowedExtensions, true)) {
                $errors[] = '対応していない音声ファイル形式です。';
            }

            $mimeType = (string) ($audioFile['type'] ?? '');
            if ($mimeType !== '' && strpos($mimeType, 'audio/') !== 0) {
                $errors[] = '音声ファイルを選択してください。';
            }

            $maxBytes = 100 * 1024 * 1024;
            if ((int) ($audioFile['size'] ?? 0) > $maxBytes) {
                $errors[] = '音声ファイルサイズは100MB以下にしてください。';
            }

            if ($errors === []) {
                $uploadDir = __DIR__ . '/uploads/audio';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                    $errors[] = '保存先フォルダの作成に失敗しました。';
                } else {
                    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                    $sanitizedBaseName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $baseName) ?: 'audio';
                    $storedFileName = sprintf('%s_%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(4)), $sanitizedBaseName, $extension ?: 'dat');
                    $destination = $uploadDir . '/' . $storedFileName;

                    if (!move_uploaded_file((string) ($audioFile['tmp_name'] ?? ''), $destination)) {
                        $errors[] = '音声ファイルの保存に失敗しました。';
                    } else {
                        $fileName = 'uploads/audio/' . $storedFileName;
                    }
                }
            }
        }
    }

    if ($isWritingAction && $errors === []) {
        if ($isUpdate) {
            $updateStmt = $pdo->prepare('UPDATE customer_sales_record_writings SET file_name = :file_name, writing = :writing, writing_notes = :writing_notes WHERE id = :id AND sheet_id = :sheet_id');
            $updateStmt->bindValue(':file_name', $fileName);
            $updateStmt->bindValue(':writing', $writing === '' ? null : $writing);
            $updateStmt->bindValue(':writing_notes', $writingNotes === '' ? null : $writingNotes);
            $updateStmt->bindValue(':id', $writingId, PDO::PARAM_INT);
            $updateStmt->bindValue(':sheet_id', $recordId, PDO::PARAM_INT);
            $updateStmt->execute();
            $noticeParam = 'updated=1';
        } else {
            $insertStmt = $pdo->prepare('INSERT INTO customer_sales_record_writings (sheet_id, file_name, writing, writing_notes) VALUES (:sheet_id, :file_name, :writing, :writing_notes)');
            $insertStmt->bindValue(':sheet_id', $recordId, PDO::PARAM_INT);
            $insertStmt->bindValue(':file_name', $fileName);
            $insertStmt->bindValue(':writing', $writing === '' ? null : $writing);
            $insertStmt->bindValue(':writing_notes', $writingNotes === '' ? null : $writingNotes);
            $insertStmt->execute();
            $noticeParam = 'saved=1';
        }

        header('Location: detail.php?sheet_id=' . rawurlencode($sheetId) . '&' . $noticeParam . '&refresh=' . time() . '#writing-list');
        exit;
    }
}

$writingsStmt = $pdo->prepare('SELECT id, file_name, writing, writing_notes, updated_at FROM customer_sales_record_writings WHERE sheet_id = :sheet_id ORDER BY updated_at DESC');
$writingsStmt->bindValue(':sheet_id', (int) ($record['id'] ?? 0), PDO::PARAM_INT);
$writingsStmt->execute();
$writings = $writingsStmt->fetchAll();

$templatesStmt = $pdo->query('SELECT id, template_name, mail_subject, mail_body, updated_at FROM email_templates ORDER BY updated_at DESC, id DESC');
$emailTemplates = $templatesStmt->fetchAll();
$customerFields = [
    'sheet_id' => 'シートID',
    'serial_no' => '通し番号',
    'sales_year_month' => '売上年月',
    'payment_year_month' => '入金年月',
    'full_name' => '氏名',
    'system_name' => 'システム名',
    'entry_point' => '流入経路',
    'status' => 'ステータス',
    'line_name' => 'LINE名',
    'phone_number' => '電話番号',
    'email' => 'メールアドレス',
    'sales_date' => 'セールス日',
    'payment_date' => '入金日',
    'expected_payment_amount' => '見込み入金額',
    'payment_amount' => '入金額',
    'payment_installment_no' => '分割回数',
    'login_id' => 'ログインID',
    'payment_destination' => '振込先',
    'video_staff' => '演者',
    'sales_staff' => 'セールス担当',
    'acquisition_channel' => '獲得チャネル',
    'age' => '年齢',
    'system_delivery_status' => 'システム納品状況',
    'notes' => '備考',
    'payment_week' => '入金週',
    'data1' => 'data1',
    'data2' => 'data2',
    'line_registered_date' => 'LINE登録日',
    'gender' => '性別',
    'created_at' => '作成日時',
    'updated_at' => '更新日時',
];

$pageTitle = '顧客詳細';
require 'header.php';
?>
<div class="glass-board" aria-hidden="true" style="display:none;"></div>
<div class="dashboard-shell panel dashboard-shell--detail">
  <aside class="side-panel">
    <img class="avatar" src="img/human.png" alt="顧客詳細アイコン" loading="lazy">
    <h1>Customer Detail</h1>
    <p><?= h((string) ($record['line_name'] ?? '名称未設定')); ?></p>

    <nav class="side-nav" aria-label="メニュー">
      <a href="index.php">一覧へ戻る</a>
      <!-- <a href="#customer-info">顧客情報</a>
      <a href="#writing-list">Writing一覧</a> -->
    </nav>
  </aside>

  <section class="main-panel detail-main-panel">
    <div class="detail-columns">
      <section id="customer-info" class="panel content-panel detail-panel">
        <h2>顧客情報</h2>
        <div class="customer-grid">
          <?php foreach ($customerFields as $field => $label): ?>
            <article class="customer-item">
              <span><?= h($label); ?></span>
              <strong><?= h((string) ($record[$field] ?? '')); ?></strong>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <div class="detail-right-stack">
        <section id="email-compose" class="panel content-panel detail-panel">
          <div class="section-head">
            <h2>メール作成</h2>
            <button type="button" class="btn btn-icon" data-open-modal="template-editor-modal" aria-label="テンプレート編集">
              <img src="img/option.png" alt="" loading="lazy">
            </button>
          </div>

          <?php if (isset($_GET['template_saved'])): ?>
            <p class="notice">テンプレートを保存しました。</p>
          <?php elseif (isset($_GET['template_deleted'])): ?>
            <p class="notice">テンプレートを削除しました。</p>
          <?php elseif (isset($_GET['draft_saved'])): ?>
            <p class="notice">メール下書きを保存しました。</p>
          <?php elseif (isset($_GET['mail_sent'])): ?>
            <p class="notice">メール送信を受け付けました。</p>
          <?php endif; ?>

          <form method="post" class="email-form" data-email-form>
            <input type="hidden" name="slide_confirmed" value="0" data-slide-confirmed>
            <div class="field">
              <label for="email_template_id">テンプレート</label>
              <select id="email_template_id" name="email_template_id" data-template-select>
                <option value="">テンプレートを選択</option>
                <?php foreach ($emailTemplates as $template): ?>
                  <option
                    value="<?= h((string) $template['id']); ?>"
                    data-template-subject="<?= h((string) ($template['mail_subject'] ?? '')); ?>"
                    data-template-body="<?= h((string) ($template['mail_body'] ?? '')); ?>"
                    <?= $formValues['email_template_id'] === (string) $template['id'] ? 'selected' : ''; ?>
                  >
                    <?= h((string) ($template['template_name'] ?? '')); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label for="mail_subject">件名</label>
              <input id="mail_subject" name="mail_subject" type="text" value="<?= h($formValues['mail_subject']); ?>" data-mail-subject>
            </div>

            <div class="field">
              <label for="mail_body">本文</label>
              <textarea id="mail_body" name="mail_body" rows="12" class="mail-body-textarea" data-mail-body><?= h($formValues['mail_body']); ?></textarea>
            </div>

            <div class="actions email-actions">
              <button class="btn btn-ghost" type="submit" name="action" value="save_email_draft">保存</button>
            </div>

            <div class="swipe-send" data-swipe-send>
              <div class="swipe-send-track">
                <span class="swipe-send-label">→ 右にスワイプして送信</span>
                <button type="button" class="swipe-send-thumb" aria-label="送信スライダー" data-swipe-thumb>送信</button>
              </div>
            </div>
          </form>
        </section>

        <section id="writing-list" class="panel content-panel detail-panel">
          <div class="section-head">
            <h2>サポート面談記録</h2>
            <button type="button" class="btn btn-primary" data-open-modal="create-writing-modal">追加</button>
          </div>

          <?php if (isset($_GET['saved'])): ?>
            <p class="notice">保存しました。</p>
          <?php elseif (isset($_GET['updated'])): ?>
            <p class="notice">更新しました。</p>
          <?php elseif (isset($_GET['deleted'])): ?>
            <p class="notice">削除しました。</p>
          <?php endif; ?>

          <?php if ($errors !== []): ?>
            <ul class="error-list">
              <?php foreach ($errors as $error): ?>
                <li><?= h($error); ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if ($writings === []): ?>
            <p class="empty">サポート面談記録が登録されていません。</p>
          <?php else: ?>
            <table class="table">
              <thead>
              <tr>
                <th>ID</th>
                <th>登録日</th>
                <th>操作</th>
              </tr>
              </thead>
              <tbody>
              <?php foreach ($writings as $writing): ?>
                <tr>
                  <td><?= h((string) ($writing['id'] ?? '')); ?></td>
                  <td><?= h((string) ($writing['updated_at'] ?? '')); ?></td>
                  <td>
                    <button
                      type="button"
                      class="btn btn-ghost"
                      data-open-modal="writing-modal"
                      data-writing="<?= h((string) ($writing['writing'] ?? '')); ?>"
                      data-writing-notes="<?= h((string) ($writing['writing_notes'] ?? '')); ?>"
                      data-file-name="<?= h((string) ($writing['file_name'] ?? '')); ?>"
                      data-writing-id="<?= h((string) ($writing['id'] ?? '')); ?>"
                    >
                      詳細を見る
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </section>
</div>

<div class="modal" id="writing-modal" hidden>
  <div class="modal-dialog panel content-panel">
    <div class="section-head">
      <h3>サポート面談記録</h3>
      <button type="button" class="btn btn-ghost" data-close-modal>閉じる</button>
    </div>
    <form method="post" class="writing-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update" data-modal-action>
      <input type="hidden" name="writing_id" value="" data-modal-writing-id>
      <dl class="writing-detail">
        <div class="writing-detail-audio">
          <dt>音声ファイル（差し替え任意）</dt>
          <dd>
            <p class="audio-file" data-modal-file-name>未登録</p>
            <audio controls data-modal-audio preload="metadata"></audio>
            <input name="audio_file" type="file" accept="audio/*">
          </dd>
        </div>
        <div class="writing-detail-body">
          <div class="writing-detail-block">
            <dt>文字起こし</dt>
            <dd>
              <textarea name="writing" rows="14" data-modal-writing-input></textarea>
            </dd>
          </div>
          <div class="writing-detail-block">
            <dt>要約</dt>
            <dd>
              <textarea name="writing_notes" rows="14" data-modal-writing-notes-input></textarea>
            </dd>
          </div>
        </div>
      </dl>
      <div class="actions">
        <button class="btn btn-primary" type="submit">更新する</button>
        <button class="btn btn-ghost btn-danger" type="submit" data-modal-delete>削除する</button>
      </div>
    </form>
  </div>
</div>

<div class="modal" id="create-writing-modal" hidden>
  <div class="modal-dialog panel content-panel">
    <div class="section-head">
      <h3>Writing追加</h3>
      <button type="button" class="btn btn-ghost" data-close-modal>閉じる</button>
    </div>
    <form method="post" class="writing-form" enctype="multipart/form-data">
      <input type="hidden" name="action" value="create">
      <div class="field">
        <label for="audio_file">音声ファイル</label>
        <div class="dropzone" data-dropzone>
          <p>ここに音声ファイルをドロップ&ドラッグするか、下のボタンから選択してください。</p>
          <input id="audio_file" name="audio_file" type="file" accept="audio/*" required data-audio-input>
          <p class="file-meta" data-file-meta>未選択</p>
        </div>
      </div>
      <div class="field">
        <label for="writing">writing</label>
        <textarea id="writing" name="writing" rows="4"><?= h($formValues['writing']); ?></textarea>
      </div>
      <div class="field">
        <label for="writing_notes">writing_notes</label>
        <textarea id="writing_notes" name="writing_notes" rows="4"><?= h($formValues['writing_notes']); ?></textarea>
      </div>
      <div class="actions">
        <button class="btn btn-primary" type="submit">保存</button>
      </div>
    </form>
  </div>
</div>
<div class="modal" id="template-editor-modal" hidden>
  <div class="modal-dialog panel content-panel">
    <div class="section-head">
      <h3>メールテンプレート編集</h3>
      <button type="button" class="btn btn-ghost" data-close-modal>閉じる</button>
    </div>

    <?php if ($emailTemplates === []): ?>
      <p class="empty">テンプレートはまだありません。</p>
    <?php else: ?>
      <div class="template-list">
        <?php foreach ($emailTemplates as $template): ?>
          <form method="post" class="template-item">
            <input type="hidden" name="template_id" value="<?= h((string) $template['id']); ?>">
            <div class="field">
              <label>テンプレート名</label>
              <input type="text" name="template_name" value="<?= h((string) ($template['template_name'] ?? '')); ?>" required>
            </div>
            <div class="field">
              <label>件名</label>
              <input type="text" name="template_subject" value="<?= h((string) ($template['mail_subject'] ?? '')); ?>" required>
            </div>
            <div class="field">
              <label>本文</label>
              <textarea name="template_body" rows="6" required><?= h((string) ($template['mail_body'] ?? '')); ?></textarea>
            </div>
            <div class="actions">
              <button class="btn btn-primary" type="submit" name="action" value="template_update">更新</button>
              <button class="btn btn-ghost btn-danger" type="submit" name="action" value="template_delete">削除</button>
            </div>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <hr class="template-divider">
    <form method="post" class="template-item">
      <h4>テンプレート追加</h4>
      <div class="field">
        <label>テンプレート名</label>
        <input type="text" name="template_name" value="<?= h($formValues['template_name']); ?>" required>
      </div>
      <div class="field">
        <label>件名</label>
        <input type="text" name="template_subject" value="<?= h($formValues['template_subject']); ?>" required>
      </div>
      <div class="field">
        <label>本文</label>
        <textarea name="template_body" rows="6" required><?= h($formValues['template_body']); ?></textarea>
      </div>
      <div class="actions">
        <button class="btn btn-primary" type="submit" name="action" value="template_create">追加</button>
      </div>
    </form>
  </div>
</div>
<?php require 'footer.php'; ?>
