<?php

declare(strict_types=1);

function normalizeTagColor(string $color): string
{
    $trimmed = trim($color);
    if ($trimmed === '') {
        return '#3b82f6';
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $trimmed)) {
        return '#3b82f6';
    }

    return strtolower($trimmed);
}

function ensureCustomerTagTables(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS customer_tags (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        color CHAR(7) NOT NULL DEFAULT "#3b82f6",
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_customer_tags_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $pdo->exec('CREATE TABLE IF NOT EXISTS customer_sales_record_tags (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sheet_id VARCHAR(100) NOT NULL,
        tag_id BIGINT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_customer_sales_record_tags_sheet_tag (sheet_id, tag_id),
        KEY idx_customer_sales_record_tags_tag_id (tag_id),
        CONSTRAINT fk_customer_sales_record_tags_sheet_id
            FOREIGN KEY (sheet_id)
            REFERENCES customer_sales_records (sheet_id)
            ON UPDATE CASCADE
            ON DELETE CASCADE,
        CONSTRAINT fk_customer_sales_record_tags_tag_id
            FOREIGN KEY (tag_id)
            REFERENCES customer_tags (id)
            ON UPDATE CASCADE
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function fetchCustomerTagMaster(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name, color FROM customer_tags ORDER BY name ASC');
    return $stmt->fetchAll() ?: [];
}

function fetchCustomerTagsBySheetId(PDO $pdo, string $sheetId): array
{
    $stmt = $pdo->prepare(
        'SELECT t.id, t.name, t.color
         FROM customer_sales_record_tags AS srt
         INNER JOIN customer_tags AS t ON t.id = srt.tag_id
         WHERE srt.sheet_id = :sheet_id
         ORDER BY t.name ASC'
    );
    $stmt->bindValue(':sheet_id', $sheetId);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function createCustomerTag(PDO $pdo, string $name, string $color): int
{
    $trimmedName = trim($name);
    if ($trimmedName === '') {
        throw new InvalidArgumentException('タグ名を入力してください。');
    }

    $stmt = $pdo->prepare('INSERT INTO customer_tags (name, color) VALUES (:name, :color)');
    $stmt->bindValue(':name', $trimmedName);
    $stmt->bindValue(':color', normalizeTagColor($color));
    $stmt->execute();

    return (int) $pdo->lastInsertId();
}

function updateCustomerTag(PDO $pdo, int $tagId, string $name, string $color): void
{
    $trimmedName = trim($name);
    if ($trimmedName === '') {
        throw new InvalidArgumentException('タグ名を入力してください。');
    }

    $stmt = $pdo->prepare('UPDATE customer_tags SET name = :name, color = :color WHERE id = :id');
    $stmt->bindValue(':id', $tagId, PDO::PARAM_INT);
    $stmt->bindValue(':name', $trimmedName);
    $stmt->bindValue(':color', normalizeTagColor($color));
    $stmt->execute();
}

function deleteCustomerTag(PDO $pdo, int $tagId): void
{
    $stmt = $pdo->prepare('DELETE FROM customer_tags WHERE id = :id');
    $stmt->bindValue(':id', $tagId, PDO::PARAM_INT);
    $stmt->execute();
}

function attachTagToSheet(PDO $pdo, string $sheetId, int $tagId): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO customer_sales_record_tags (sheet_id, tag_id) VALUES (:sheet_id, :tag_id)');
    $stmt->bindValue(':sheet_id', $sheetId);
    $stmt->bindValue(':tag_id', $tagId, PDO::PARAM_INT);
    $stmt->execute();
}

function detachTagFromSheet(PDO $pdo, string $sheetId, int $tagId): void
{
    $stmt = $pdo->prepare('DELETE FROM customer_sales_record_tags WHERE sheet_id = :sheet_id AND tag_id = :tag_id');
    $stmt->bindValue(':sheet_id', $sheetId);
    $stmt->bindValue(':tag_id', $tagId, PDO::PARAM_INT);
    $stmt->execute();
}
