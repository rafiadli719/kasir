-- Database Updates untuk Sistem Closing Transaction
-- Jalankan script ini untuk memperbarui database dengan fitur closing transaction

-- 1. Pastikan kolom jenis_closing sudah ada di kasir_transactions
ALTER TABLE kasir_transactions 
ADD COLUMN IF NOT EXISTS jenis_closing ENUM('closing','dipinjam','meminjam') DEFAULT NULL 
COMMENT 'Jenis transaksi dalam closing: closing, dipinjam, meminjam';

-- 2. Pastikan kolom closing_group_id sudah ada
ALTER TABLE kasir_transactions 
ADD COLUMN IF NOT EXISTS closing_group_id INT(11) DEFAULT NULL 
COMMENT 'ID grup closing transaction';

-- 3. Pastikan kolom is_part_of_closing sudah ada
ALTER TABLE kasir_transactions 
ADD COLUMN IF NOT EXISTS is_part_of_closing TINYINT(1) DEFAULT 0 
COMMENT 'Flag apakah transaksi ini bagian dari closing';

-- 4. Pastikan kolom jenis_setoran_id sudah ada
ALTER TABLE kasir_transactions 
ADD COLUMN IF NOT EXISTS jenis_setoran_id INT(11) DEFAULT NULL 
COMMENT 'ID jenis setoran';

-- 5. Update tabel jenis_setoran untuk menambah flag closing
ALTER TABLE jenis_setoran 
ADD COLUMN IF NOT EXISTS is_closing_related TINYINT(1) DEFAULT 0 
COMMENT 'Flag apakah jenis setoran ini terkait closing';

ALTER TABLE jenis_setoran 
ADD COLUMN IF NOT EXISTS requires_grouping TINYINT(1) DEFAULT 0 
COMMENT 'Flag apakah jenis setoran ini memerlukan pengelompokan';

-- 6. Update tabel setoran_keuangan untuk menambah informasi closing
ALTER TABLE setoran_keuangan 
ADD COLUMN IF NOT EXISTS has_closing_transactions TINYINT(1) DEFAULT 0 
COMMENT 'Flag apakah setoran ini mengandung transaksi closing';

ALTER TABLE setoran_keuangan 
ADD COLUMN IF NOT EXISTS total_closing_groups INT(11) DEFAULT 0 
COMMENT 'Jumlah grup closing dalam setoran ini';

ALTER TABLE setoran_keuangan 
ADD COLUMN IF NOT EXISTS closing_summary LONGTEXT DEFAULT NULL 
COMMENT 'Ringkasan data closing dalam format JSON';

-- 7. Pastikan tabel closing_transaction_groups sudah ada
CREATE TABLE IF NOT EXISTS closing_transaction_groups (
    id INT(11) NOT NULL AUTO_INCREMENT,
    group_code VARCHAR(50) NOT NULL COMMENT 'Kode unik grup closing',
    nama_cabang VARCHAR(100) NOT NULL COMMENT 'Nama cabang',
    kode_setoran VARCHAR(50) DEFAULT NULL COMMENT 'Kode setoran terkait',
    tanggal_closing DATE NOT NULL COMMENT 'Tanggal closing',
    total_closing DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Total transaksi closing',
    total_dipinjam DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Total uang dipinjam',
    total_meminjam DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Total uang meminjam',
    total_gabungan DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Total gabungan semua transaksi',
    jumlah_transaksi INT(11) DEFAULT 0 COMMENT 'Jumlah transaksi dalam grup',
    status_validasi ENUM('pending','validated','rejected') DEFAULT 'pending' COMMENT 'Status validasi grup',
    validated_by VARCHAR(50) DEFAULT NULL COMMENT 'Kode karyawan yang memvalidasi',
    validated_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Waktu validasi',
    catatan_validasi TEXT DEFAULT NULL COMMENT 'Catatan validasi',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_group_code (group_code),
    INDEX idx_nama_cabang (nama_cabang),
    INDEX idx_tanggal_closing (tanggal_closing),
    INDEX idx_status_validasi (status_validasi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Tabel untuk mengelompokkan transaksi closing';

-- 8. Pastikan tabel closing_transaction_details sudah ada
CREATE TABLE IF NOT EXISTS closing_transaction_details (
    id INT(11) NOT NULL AUTO_INCREMENT,
    group_id INT(11) NOT NULL COMMENT 'ID grup closing',
    transaction_id INT(11) NOT NULL COMMENT 'ID transaksi kasir',
    jenis_dalam_closing ENUM('closing','dipinjam','meminjam') NOT NULL COMMENT 'Jenis transaksi dalam closing',
    nominal DECIMAL(15,2) NOT NULL COMMENT 'Nominal transaksi',
    keterangan TEXT DEFAULT NULL COMMENT 'Keterangan transaksi',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (group_id) REFERENCES closing_transaction_groups(id) ON DELETE CASCADE,
    INDEX idx_group_id (group_id),
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_jenis_dalam_closing (jenis_dalam_closing)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Detail transaksi dalam grup closing';

-- 9. Tambah foreign key constraints
ALTER TABLE kasir_transactions 
ADD CONSTRAINT IF NOT EXISTS fk_kasir_closing_group 
FOREIGN KEY (closing_group_id) REFERENCES closing_transaction_groups(id) ON DELETE SET NULL;

ALTER TABLE kasir_transactions 
ADD CONSTRAINT IF NOT EXISTS fk_kasir_jenis_setoran 
FOREIGN KEY (jenis_setoran_id) REFERENCES jenis_setoran(id) ON DELETE SET NULL;

-- 10. Insert default jenis setoran untuk closing (hanya 3 jenis)
INSERT IGNORE INTO jenis_setoran (kode_jenis, nama_jenis, deskripsi, is_closing_related, requires_grouping, status) VALUES
('CLO001', 'Closing Normal', 'Transaksi penutupan kasir normal', 1, 1, 'active'),
('CLO002', 'Closing Dipinjam', 'Uang yang dipinjam dari kasir lain saat closing', 1, 1, 'active'),
('CLO003', 'Closing Meminjam', 'Uang yang dipinjamkan ke kasir lain saat closing', 1, 1, 'active');

-- 11. Create view untuk laporan closing yang lebih komprehensif
CREATE VIEW view_closing_summary AS
SELECT 
    cg.id AS group_id,
    cg.group_code,
    cg.nama_cabang,
    cg.kode_setoran,
    cg.tanggal_closing,
    cg.total_closing,
    cg.total_dipinjam,
    cg.total_meminjam,
    cg.total_gabungan,
    cg.jumlah_transaksi,
    cg.status_validasi,
    cg.validated_by,
    cg.validated_at,
    sk.status AS status_setoran,
    sk.tanggal_setoran,
    COUNT(DISTINCT ctd.transaction_id) AS detail_count,
    GROUP_CONCAT(DISTINCT kt.kode_transaksi SEPARATOR ', ') AS daftar_transaksi
FROM closing_transaction_groups cg
LEFT JOIN setoran_keuangan sk 
    ON cg.kode_setoran COLLATE utf8mb4_unicode_ci = sk.kode_setoran COLLATE utf8mb4_unicode_ci
LEFT JOIN closing_transaction_details ctd 
    ON cg.id = ctd.group_id
LEFT JOIN kasir_transactions kt 
    ON ctd.transaction_id = kt.id
GROUP BY cg.id;

CREATE VIEW view_closing_monitoring AS
SELECT 
    kt.kode_transaksi,
    kt.nama_cabang,
    kt.tanggal_transaksi,
    kt.setoran_real,
    kt.status,
    kt.deposit_status,
    kt.is_part_of_closing,
    kt.jenis_closing,
    cg.group_code,
    cg.status_validasi AS group_status,
    pk.jumlah AS jumlah_pemasukan_closing,
    pk.keterangan_transaksi AS keterangan_closing,
    CASE 
        WHEN kt.is_part_of_closing = 1 THEN 'Closing Transaction'
        WHEN pk.nomor_transaksi_closing IS NOT NULL THEN 'Has Closing Reference'
        ELSE 'Regular Transaction'
    END AS transaction_type
FROM kasir_transactions kt
LEFT JOIN closing_transaction_groups cg 
    ON kt.closing_group_id = cg.id
LEFT JOIN pemasukan_kasir pk 
    ON pk.nomor_transaksi_closing COLLATE utf8mb4_unicode_ci 
       = kt.kode_transaksi COLLATE utf8mb4_unicode_ci
WHERE kt.status = 'end proses';

-- 13. Create stored procedure untuk proses closing otomatis
DELIMITER //

CREATE OR REPLACE PROCEDURE ProcessClosingTransaction(
    IN p_kode_transaksi VARCHAR(255),
    IN p_jenis_closing ENUM('closing','dipinjam','meminjam'),
    IN p_nama_cabang VARCHAR(100),
    IN p_kode_karyawan VARCHAR(50),
    OUT p_group_id INT,
    OUT p_result_message VARCHAR(500)
)
BEGIN
    DECLARE v_group_code VARCHAR(50);
    DECLARE v_existing_group_id INT DEFAULT NULL;
    DECLARE v_kode_setoran VARCHAR(50);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            p_result_message = MESSAGE_TEXT;
        SET p_group_id = 0;
    END;

    START TRANSACTION;

    -- Generate group code
    SET v_group_code = CONCAT('CLO-', 
        UPPER(LEFT(p_nama_cabang, 3)), '-', 
        DATE_FORMAT(NOW(), '%Y%m%d-%H%i%s'));

    -- Check if group exists for today
    SELECT id INTO v_existing_group_id 
    FROM closing_transaction_groups 
    WHERE nama_cabang = p_nama_cabang 
    AND DATE(tanggal_closing) = CURDATE()
    AND status_validasi = 'pending'
    LIMIT 1;

    -- Create or use existing group
    IF v_existing_group_id IS NULL THEN
        INSERT INTO closing_transaction_groups 
        (group_code, nama_cabang, tanggal_closing, status_validasi)
        VALUES (v_group_code, p_nama_cabang, CURDATE(), 'pending');
        
        SET p_group_id = LAST_INSERT_ID();
    ELSE
        SET p_group_id = v_existing_group_id;
    END IF;

    -- Update kasir_transactions
    UPDATE kasir_transactions 
    SET status = 'end proses',
        deposit_status = 'Belum Disetor',
        is_part_of_closing = 1,
        jenis_closing = p_jenis_closing,
        closing_group_id = p_group_id
    WHERE kode_transaksi = p_kode_transaksi;

    -- Generate setoran code
    SET v_kode_setoran = CONCAT('SET-', 
        UPPER(LEFT(p_nama_cabang, 3)), '-', 
        DATE_FORMAT(NOW(), '%Y%m%d-%H%i%s'));

    -- Update group with setoran code
    UPDATE closing_transaction_groups 
    SET kode_setoran = v_kode_setoran
    WHERE id = p_group_id;

    COMMIT;
    SET p_result_message = 'Closing transaction processed successfully';

END //

DELIMITER ;

-- 14. Create trigger untuk update otomatis total di closing_transaction_groups
DELIMITER //

CREATE OR REPLACE TRIGGER update_closing_group_totals
AFTER INSERT ON closing_transaction_details
FOR EACH ROW
BEGIN
    UPDATE closing_transaction_groups 
    SET 
        total_closing = (
            SELECT COALESCE(SUM(CASE WHEN jenis_dalam_closing = 'closing' THEN nominal ELSE 0 END), 0)
            FROM closing_transaction_details 
            WHERE group_id = NEW.group_id
        ),
        total_dipinjam = (
            SELECT COALESCE(SUM(CASE WHEN jenis_dalam_closing = 'dipinjam' THEN nominal ELSE 0 END), 0)
            FROM closing_transaction_details 
            WHERE group_id = NEW.group_id
        ),
        total_meminjam = (
            SELECT COALESCE(SUM(CASE WHEN jenis_dalam_closing = 'meminjam' THEN nominal ELSE 0 END), 0)
            FROM closing_transaction_details 
            WHERE group_id = NEW.group_id
        ),
        total_gabungan = (
            SELECT COALESCE(SUM(nominal), 0)
            FROM closing_transaction_details 
            WHERE group_id = NEW.group_id
        ),
        jumlah_transaksi = (
            SELECT COUNT(*)
            FROM closing_transaction_details 
            WHERE group_id = NEW.group_id
        ),
        updated_at = NOW()
    WHERE id = NEW.group_id;
END //

DELIMITER ;

-- 15. Insert audit log untuk tracking perubahan
INSERT INTO audit_log (kode_karyawan, action, table_name, record_id, new_data, created_at)
VALUES ('SYSTEM', 'UPDATE', 'database_schema', 'closing_transaction_enhancement', 
        JSON_OBJECT(
            'description', 'Enhanced database schema for closing transaction management',
            'version', '1.0',
            'features', JSON_ARRAY(
                'Closing transaction grouping',
                'Enhanced setoran keuangan with closing support',
                'Automated closing process',
                'Comprehensive reporting views'
            )
        ), 
        NOW());

-- 16. Create indexes untuk performance
CREATE INDEX IF NOT EXISTS idx_kasir_closing_status ON kasir_transactions(is_part_of_closing, status, deposit_status);
CREATE INDEX IF NOT EXISTS idx_kasir_closing_group ON kasir_transactions(closing_group_id);
CREATE INDEX IF NOT EXISTS idx_kasir_jenis_closing ON kasir_transactions(jenis_closing);
CREATE INDEX IF NOT EXISTS idx_setoran_closing_flag ON setoran_keuangan(has_closing_transactions);
CREATE INDEX IF NOT EXISTS idx_closing_group_status ON closing_transaction_groups(status_validasi, tanggal_closing);

-- Selesai - Database telah diperbarui dengan fitur closing transaction
SELECT 'Database update completed successfully. Closing transaction features are now available.' as status;
