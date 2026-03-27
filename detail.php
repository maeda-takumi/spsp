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

$errors = [];
$formValues = [
    'writing' => '',
    'writing_notes' => '',
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
    $targetWriting = null;
    $fileName = '';

    if (($isUpdate || $isDelete) && $writingId <= 0) {
        $errors[] = '対象データが不正です。';
    }

    if (($isUpdate || $isDelete) && $errors === []) {
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

    if ($isDelete && $errors === []) {
        $deleteStmt = $pdo->prepare('DELETE FROM customer_sales_record_writings WHERE id = :id AND sheet_id = :sheet_id');
        $deleteStmt->bindValue(':id', $writingId, PDO::PARAM_INT);
        $deleteStmt->bindValue(':sheet_id', $recordId, PDO::PARAM_INT);
        $deleteStmt->execute();

        header('Location: detail.php?sheet_id=' . rawurlencode($sheetId) . '&deleted=1&refresh=' . time() . '#writing-list');
        exit;
    }

    if (!$isDelete) {
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

    if ($errors === []) {
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
<?php require 'footer.php'; ?>
