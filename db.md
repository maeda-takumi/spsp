CREATE TABLE customer_sales_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    sheet_id VARCHAR(100) NULL COMMENT 'スプシのID',
    serial_no INT NULL COMMENT '通し番号',

    sales_year_month CHAR(7) NULL COMMENT 'YYYY-MM',
    payment_year_month CHAR(7) NULL COMMENT 'YYYY-MM',

    full_name VARCHAR(255) NULL,
    system_name VARCHAR(255) NULL,
    entry_point VARCHAR(255) NULL,
    status VARCHAR(100) NULL,
    line_name VARCHAR(255) NULL,
    phone_number VARCHAR(50) NULL,
    email VARCHAR(255) NULL,

    sales_date DATE NULL,
    payment_date DATE NULL,

    expected_payment_amount DECIMAL(12,2) NULL,
    payment_amount DECIMAL(12,2) NULL,

    payment_installment_no INT NULL,
    login_id VARCHAR(255) NULL,
    payment_destination VARCHAR(255) NULL,
    video_staff VARCHAR(255) NULL,
    sales_staff VARCHAR(255) NULL,
    acquisition_channel VARCHAR(255) NULL,

    age TINYINT UNSIGNED NULL,
    system_delivery_status VARCHAR(100) NULL,
    notes TEXT NULL,
    payment_week VARCHAR(20) NULL,

    data1 TEXT NULL,
    data2 TEXT NULL,

    line_registered_date DATE NULL,
    gender VARCHAR(20) NULL,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_serial_no (serial_no),
    INDEX idx_sales_date (sales_date),
    INDEX idx_payment_date (payment_date),
    INDEX idx_phone_number (phone_number),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_sales_record_writings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sheet_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    writing TEXT,
    writing_notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_sales_record_writings_sheet_id (sheet_id),
    CONSTRAINT fk_customer_sales_record_writings_sheet_id
        FOREIGN KEY (sheet_id)
        REFERENCES customer_sales_records (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_name VARCHAR(255) NOT NULL,
    mail_subject VARCHAR(255) NOT NULL,
    mail_body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_sales_record_email_drafts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_sales_record_email_send_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;