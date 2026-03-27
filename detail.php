<?php

declare(strict_types=1);

require_once 'config.php';

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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fileName = '';
    $writing = trim((string) ($_POST['writing'] ?? ''));
    $writingNotes = trim((string) ($_POST['writing_notes'] ?? ''));
    $audioFile = $_FILES['audio_file'] ?? null;

    if (!is_array($audioFile) || (int) ($audioFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = '音声ファイルは必須です。';
    } elseif ((int) $audioFile['error'] !== UPLOAD_ERR_OK) {
        $errors[] = '音声ファイルのアップロードに失敗しました。';
    } else {
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

    if ($errors === []) {
        $insertStmt = $pdo->prepare('INSERT INTO customer_sales_record_writings (sheet_id, file_name, writing, writing_notes) VALUES (:sheet_id, :file_name, :writing, :writing_notes)');
        $insertStmt->bindValue(':sheet_id', (int) ($record['id'] ?? 0), PDO::PARAM_INT);
        $insertStmt->bindValue(':file_name', $fileName);
        $insertStmt->bindValue(':writing', $writing === '' ? null : $writing);
        $insertStmt->bindValue(':writing_notes', $writingNotes === '' ? null : $writingNotes);
        $insertStmt->execute();

        header('Location: detail.php?sheet_id=' . rawurlencode($sheetId) . '&saved=1');
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
      <a href="#customer-info">顧客情報</a>
      <a href="#writing-list">Writing一覧</a>
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
        <?php endif; ?>

        <?php if ($errors !== []): ?>
          <ul class="error-list">
            <?php foreach ($errors as $error): ?>
              <li><?= h($error); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($writings === []): ?>
          <p class="empty">writingデータがまだありません。</p>
        <?php else: ?>
          <table class="table">
            <thead>
            <tr>
              <th>ID</th>
              <th>updated_at</th>
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
      <h3>Writing詳細</h3>
      <button type="button" class="btn btn-ghost" data-close-modal>閉じる</button>
    </div>
    <dl class="writing-detail">
      <dt>writing</dt>
      <dd data-modal-writing></dd>
      <dt>writing_notes</dt>
      <dd data-modal-writing-notes></dd>
      <dt>音声ファイル</dt>
      <dd>
        <p class="audio-file" data-modal-file-name></p>
        <audio controls data-modal-audio></audio>
      </dd>
    </dl>
  </div>
</div>

<div class="modal" id="create-writing-modal" hidden>
  <div class="modal-dialog panel content-panel">
    <div class="section-head">
      <h3>Writing追加</h3>
      <button type="button" class="btn btn-ghost" data-close-modal>閉じる</button>
    </div>
    <form method="post" class="writing-form" enctype="multipart/form-data">
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
        <textarea id="writing" name="writing" rows="4"></textarea>
      </div>
      <div class="field">
        <label for="writing_notes">writing_notes</label>
        <textarea id="writing_notes" name="writing_notes" rows="4"></textarea>
      </div>
      <div class="actions">
        <button class="btn btn-primary" type="submit">保存</button>
      </div>
    </form>
  </div>
</div>
<?php require 'footer.php'; ?>
