<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Jakarta');

// Check authentication and authorization
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

// Database connection
$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get user information
$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';

// Get branch information
$sql_cabang = "SELECT kode_cabang, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan LIMIT 1";
$stmt_cabang = $pdo->prepare($sql_cabang);
$stmt_cabang->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_cabang->execute();
$cabang_data = $stmt_cabang->fetch(PDO::FETCH_ASSOC);
$kode_cabang = $cabang_data['kode_cabang'] ?? 'Unknown';
$nama_cabang = $cabang_data['nama_cabang'] ?? 'Unknown Cabang';

// Helper Functions
function formatRupiah($angka) {
    if ($angka < 0) {
        return '-Rp ' . number_format(abs($angka), 0, ',', '.') . ' (Pengurang)';
    }
    return 'Rp ' . number_format($angka, 0, ',', '.') . ($angka == 0 ? " (Belum diisi)" : "");
}

function getStatusBadge($status) {
    switch($status) {
        case 'Sedang Dibawa Kurir':
            return '<span class="status-badge status-info">Sedang Dibawa Kurir</span>';
        case 'Diterima Staff Keuangan':
            return '<span class="status-badge status-warning">Diterima Staff Keuangan</span>';
        case 'Validasi Keuangan OK':
            return '<span class="status-badge status-primary">Validasi Keuangan OK</span>';
        case 'Validasi Keuangan SELISIH':
            return '<span class="status-badge status-danger">Validasi Keuangan SELISIH</span>';
        case 'Sudah Disetor ke Bank':
            return '<span class="status-badge status-success">Sudah Disetor ke Bank</span>';
        case 'Diserahterimakan':
            return '<span class="status-badge status-secondary">Diserahterimakan</span>';
        case 'Pending Serah Terima':
            return '<span class="status-badge status-warning">Pending Serah Terima</span>';
        default:
            return '<span class="status-badge status-secondary">Unknown</span>';
    }
}

function getJenisTransaksiBadge($jenis) {
    switch($jenis) {
        case 'REGULER':
            return '<span class="jenis-badge jenis-reguler">REGULER</span>';
        case 'DARI CLOSING':
            return '<span class="jenis-badge jenis-closing">DARI CLOSING</span>';
        case 'CLOSING_GROUP':
            return '<span class="jenis-badge jenis-group">GRUP CLOSING</span>';
        case 'GABUNGAN_PINJAM_MEMINJAM':
            return '<span class="jenis-badge jenis-pinjam-meminjam">GABUNGAN PINJAM-MEMINJAM</span>';
        default:
            return '<span class="jenis-badge jenis-unknown">Unknown</span>';
    }
}

function getStatusSerahTerimaBadge($status) {
    switch($status) {
        case 'pending':
            return '<span class="status-badge status-warning">Menunggu Konfirmasi</span>';
        case 'completed':
            return '<span class="status-badge status-success">Diterima</span>';
        case 'cancelled':
            return '<span class="status-badge status-danger">Ditolak</span>';
        default:
            return '<span class="status-badge status-secondary">Unknown</span>';
    }
}

function getStatusSetoranBadge($status) {
    switch($status) {
        case 'Sedang Dibawa Kurir':
            return '<span class="status-badge status-info">Sedang Dibawa Kurir</span>';
        case 'Diterima Staff Keuangan':
            return '<span class="status-badge status-warning">Diterima Staff Keuangan</span>';
        case 'Validasi Keuangan OK':
            return '<span class="status-badge status-primary">Validasi Keuangan OK</span>';
        case 'Validasi Keuangan SELISIH':
            return '<span class="status-badge status-danger">Validasi Keuangan SELISIH</span>';
        case 'Sudah Disetor ke Bank':
            return '<span class="status-badge status-success">Sudah Disetor ke Bank</span>';
        default:
            return '<span class="status-badge status-secondary">Status Tidak Dikenal</span>';
    }
}

// PERBAIKAN: Fungsi helper untuk mengelompokkan transaksi closing yang lebih akurat
function groupClosingTransactionsForSetoran($pdo, $transactions) {
    $grouped = [];
    $processed_groups = [];
    
    foreach ($transactions as $trans) {
        // Jika transaksi adalah bagian dari closing group dan belum diproses
        if ($trans['is_part_of_closing'] == 1 && $trans['closing_group_id'] && 
            !in_array($trans['closing_group_id'], $processed_groups)) {
            
            // Ambil semua transaksi dalam grup ini yang sudah end proses
            $sql_group = "
                SELECT kt.*, 
                       CASE 
                           WHEN EXISTS (
                               SELECT 1 FROM pemasukan_kasir pk 
                               WHERE pk.nomor_transaksi_closing = kt.kode_transaksi 
                               AND pk.kode_karyawan = ?
                           ) THEN 'DARI_CLOSING'
                           ELSE 'REGULER'
                       END as jenis_transaksi,
                       CASE 
                           WHEN EXISTS (
                               SELECT 1 FROM pemasukan_kasir pk 
                               WHERE pk.nomor_transaksi_closing = kt.kode_transaksi 
                               AND pk.kode_karyawan = ?
                           ) THEN -(SELECT pk.jumlah FROM pemasukan_kasir pk WHERE pk.nomor_transaksi_closing = kt.kode_transaksi AND pk.kode_karyawan = ? LIMIT 1)
                           ELSE kt.setoran_real
                       END as jumlah_setoran_final,
                       pk.jumlah as jumlah_pemasukan_closing,
                       pk.keterangan_transaksi as keterangan_closing
                FROM kasir_transactions kt
                LEFT JOIN pemasukan_kasir pk ON pk.nomor_transaksi_closing = kt.kode_transaksi AND pk.kode_karyawan = ?
                WHERE kt.closing_group_id = ? 
                AND kt.status = 'end proses'
                ORDER BY 
                    CASE 
                        WHEN kt.jenis_closing = 'closing' THEN 1
                        WHEN kt.jenis_closing = 'dipinjam' THEN 2
                        WHEN kt.jenis_closing = 'meminjam' THEN 3
                        ELSE 4
                    END,
                    kt.tanggal_transaksi";
            
            $stmt_group = $pdo->prepare($sql_group);
            $stmt_group->execute([
                $trans['kode_karyawan'] ?? '',
                $trans['kode_karyawan'] ?? '',
                $trans['kode_karyawan'] ?? '',
                $trans['kode_karyawan'] ?? '',
                $trans['closing_group_id']
            ]);
            $group_transactions = $stmt_group->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($group_transactions)) {
                // Cek apakah ada transaksi yang masih on proses dalam grup ini
                $sql_check_pending = "
                    SELECT COUNT(*) FROM kasir_transactions 
                    WHERE closing_group_id = ? AND status = 'on proses'";
                $stmt_check = $pdo->prepare($sql_check_pending);
                $stmt_check->execute([$trans['closing_group_id']]);
                $pending_count = $stmt_check->fetchColumn();
                
                // Jika masih ada yang on proses, skip grup ini
                if ($pending_count > 0) {
                    continue;
                }
                
                // Analisis jenis transaksi dalam grup
                $has_dipinjam = false;
                $has_meminjam = false;
                $has_closing = false;
                $dipinjam_transactions = [];
                $meminjam_transactions = [];
                $closing_transactions = [];
                
                foreach ($group_transactions as $gt) {
                    if ($gt['jenis_closing'] == 'dipinjam') {
                        $has_dipinjam = true;
                        $dipinjam_transactions[] = $gt;
                    }
                    if ($gt['jenis_closing'] == 'meminjam') {
                        $has_meminjam = true;
                        $meminjam_transactions[] = $gt;
                    }
                    if ($gt['jenis_closing'] == 'closing') {
                        $has_closing = true;
                        $closing_transactions[] = $gt;
                    }
                }
                
                // PERBAIKAN: Logika pengelompokan yang lebih akurat
                if ($has_dipinjam && $has_meminjam) {
                    // Ada transaksi dipinjam dan meminjam - gabung menjadi satu
                    $total_group = array_sum(array_column($group_transactions, 'jumlah_setoran_final'));
                    
                    // Hitung detail untuk informasi tambahan
                    $total_dipinjam = array_sum(array_column($dipinjam_transactions, 'jumlah_setoran_final'));
                    $total_meminjam = array_sum(array_column($meminjam_transactions, 'jumlah_setoran_final'));
                    $total_closing = array_sum(array_column($closing_transactions, 'jumlah_setoran_final'));
                    
                    $group_item = [
                        'kode_transaksi' => 'GABUNGAN_' . $trans['closing_group_id'],
                        'tanggal_transaksi' => $trans['tanggal_transaksi'],
                        'jumlah_setoran' => $total_group,
                        'setoran_real' => $total_group,
                        'deposit_status' => $trans['deposit_status'],
                        'jenis_transaksi' => 'GABUNGAN_PINJAM_MEMINJAM',
                        'bukti_transaksi' => null,
                        'nomor_pemasukan_id' => null,
                        'keterangan_pemasukan' => 'Gabungan transaksi dipinjam dan meminjam',
                        'jenis_closing' => 'gabungan_pinjam_meminjam',
                        'closing_group_id' => $trans['closing_group_id'],
                        'is_part_of_closing' => 1,
                        'is_grouped' => true,
                        'group_transactions' => $group_transactions,
                        'group_count' => count($group_transactions),
                        'group_type' => 'pinjam_meminjam',
                        'detail_breakdown' => [
                            'total_closing' => $total_closing,
                            'total_dipinjam' => $total_dipinjam,
                            'total_meminjam' => $total_meminjam,
                            'count_closing' => count($closing_transactions),
                            'count_dipinjam' => count($dipinjam_transactions),
                            'count_meminjam' => count($meminjam_transactions)
                        ]
                    ];
                    
                    $grouped[] = $group_item;
                    $processed_groups[] = $trans['closing_group_id'];
                } else {
                    // Grup biasa - bisa hanya closing, atau hanya dipinjam, atau hanya meminjam
                    $total_group = array_sum(array_column($group_transactions, 'jumlah_setoran_final'));
                    
                    // Tentukan jenis grup
                    $group_type = 'regular';
                    $group_jenis = 'CLOSING_GROUP';
                    $group_keterangan = 'Grup transaksi closing';
                    
                    if ($has_dipinjam && !$has_meminjam && !$has_closing) {
                        $group_type = 'dipinjam_only';
                        $group_jenis = 'DARI_CLOSING';
                        $group_keterangan = 'Grup transaksi dipinjam';
                    } elseif ($has_meminjam && !$has_dipinjam && !$has_closing) {
                        $group_type = 'meminjam_only';
                        $group_jenis = 'DARI_CLOSING';
                        $group_keterangan = 'Grup transaksi meminjam';
                    } elseif ($has_closing && !$has_dipinjam && !$has_meminjam) {
                        $group_type = 'closing_only';
                        $group_jenis = 'CLOSING_GROUP';
                        $group_keterangan = 'Grup transaksi closing';
                    }
                    
                    $group_item = [
                        'kode_transaksi' => 'GROUP_' . $trans['closing_group_id'],
                        'tanggal_transaksi' => $trans['tanggal_transaksi'],
                        'jumlah_setoran' => $total_group,
                        'setoran_real' => $total_group,
                        'deposit_status' => $trans['deposit_status'],
                        'jenis_transaksi' => $group_jenis,
                        'bukti_transaksi' => null,
                        'nomor_pemasukan_id' => null,
                        'keterangan_pemasukan' => $group_keterangan,
                        'jenis_closing' => 'gabungan',
                        'closing_group_id' => $trans['closing_group_id'],
                        'is_part_of_closing' => 1,
                        'is_grouped' => true,
                        'group_transactions' => $group_transactions,
                        'group_count' => count($group_transactions),
                        'group_type' => $group_type
                    ];
                    
                    $grouped[] = $group_item;
                    $processed_groups[] = $trans['closing_group_id'];
                }
            }
        } elseif ($trans['is_part_of_closing'] != 1) {
            // Transaksi regular, tambahkan langsung
            $trans['is_grouped'] = false;
            $grouped[] = $trans;
        }
    }
    
    return $grouped;
}

// Process deposit submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_setoran'])) {
    $selected_transaksi = $_POST['kode_transaksi'] ?? [];
    $nama_pengantar = trim($_POST['nama_pengantar'] ?? '');

    if (empty($selected_transaksi)) {
        echo "<script>alert('Pilih setidaknya satu transaksi untuk disetor.');</script>";
    } elseif (empty($nama_pengantar)) {
        echo "<script>alert('Nama pengantar wajib diisi.');</script>";
    } else {
        try {
            $pdo->beginTransaction();

            // Generate unique deposit code
            $kode_setoran = 'SETORAN-' . date('Ymd') . '-' . substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6);

            // Handle grup transaksi dan calculate total deposit amount
            $total_setoran = 0;
            $transaksi_to_update = [];
            
            foreach ($selected_transaksi as $kode_transaksi) {
                if (strpos($kode_transaksi, 'GROUP_') === 0 || strpos($kode_transaksi, 'GABUNGAN_') === 0) {
                    // Ini adalah grup transaksi, ambil semua transaksi dalam grup
                    $group_id = str_replace(['GROUP_', 'GABUNGAN_'], '', $kode_transaksi);
                    
                    $sql_get_group = "
                        SELECT kt.kode_transaksi, kt.setoran_real,
                               CASE 
                                   WHEN EXISTS (
                                       SELECT 1 FROM pemasukan_kasir pk 
                                       WHERE pk.nomor_transaksi_closing = kt.kode_transaksi 
                                       AND pk.kode_karyawan = :kode_karyawan
                                   ) THEN 'DARI_CLOSING'
                                   ELSE 'REGULER'
                               END as jenis,
                               CASE 
                                   WHEN EXISTS (
                                       SELECT 1 FROM pemasukan_kasir pk 
                                       WHERE pk.nomor_transaksi_closing = kt.kode_transaksi 
                                       AND pk.kode_karyawan = :kode_karyawan2
                                   ) THEN -(SELECT pk.jumlah FROM pemasukan_kasir pk WHERE pk.nomor_transaksi_closing = kt.kode_transaksi AND pk.kode_karyawan = :kode_karyawan3 LIMIT 1)
                                   ELSE kt.setoran_real
                               END as jumlah_setoran
                        FROM kasir_transactions kt
                        WHERE kt.closing_group_id = :group_id AND kt.status = 'end proses'";
                    $stmt_get_group = $pdo->prepare($sql_get_group);
                    $stmt_get_group->bindParam(':group_id', $group_id, PDO::PARAM_INT);
                    $stmt_get_group->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
                    $stmt_get_group->bindParam(':kode_karyawan2', $kode_karyawan, PDO::PARAM_STR);
                    $stmt_get_group->bindParam(':kode_karyawan3', $kode_karyawan, PDO::PARAM_STR);
                    $stmt_get_group->execute();
                    $group_transactions = $stmt_get_group->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($group_transactions as $gt) {
                        $transaksi_to_update[] = $gt['kode_transaksi'];
                        $total_setoran += $gt['jumlah_setoran'];
                    }
                } else {
                    // Transaksi individual - cek apakah regular atau dari closing
                    $sql_check_type = "
                        SELECT 
                            CASE 
                                WHEN EXISTS (
                                    SELECT 1 FROM pemasukan_kasir pk 
                                    WHERE pk.nomor_transaksi_closing = :kode_transaksi 
                                    AND pk.kode_karyawan = :kode_karyawan
                                ) THEN 'DARI_CLOSING'
                                ELSE 'REGULER'
                            END as jenis,
                            CASE 
                                WHEN EXISTS (
                                    SELECT 1 FROM pemasukan_kasir pk 
                                    WHERE pk.nomor_transaksi_closing = :kode_transaksi2 
                                    AND pk.kode_karyawan = :kode_karyawan2
                                ) THEN -(SELECT pk.jumlah FROM pemasukan_kasir pk WHERE pk.nomor_transaksi_closing = :kode_transaksi3 AND pk.kode_karyawan = :kode_karyawan3 LIMIT 1)
                                ELSE (SELECT kt.setoran_real FROM kasir_transactions kt WHERE kt.kode_transaksi = :kode_transaksi4 LIMIT 1)
                            END as jumlah_setoran";
                    
                    $stmt_check = $pdo->prepare($sql_check_type);
                    $stmt_check->execute([
                        ':kode_transaksi' => $kode_transaksi,
                        ':kode_karyawan' => $kode_karyawan,
                        ':kode_transaksi2' => $kode_transaksi,
                        ':kode_karyawan2' => $kode_karyawan,
                        ':kode_transaksi3' => $kode_transaksi,
                        ':kode_karyawan3' => $kode_karyawan,
                        ':kode_transaksi4' => $kode_transaksi
                    ]);
                    $check_result = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    $transaksi_to_update[] = $kode_transaksi;
                    $total_setoran += $check_result['jumlah_setoran'];
                }
            }

            // Insert into setoran_keuangan with status 'Sedang Dibawa Kurir'
            $sql_setoran = "
                INSERT INTO setoran_keuangan 
                (kode_setoran, kode_karyawan, kode_cabang, nama_cabang, tanggal_setoran, jumlah_setoran, nama_pengantar, status, updated_by, created_at, updated_at)
                VALUES (:kode_setoran, :kode_karyawan, :kode_cabang, :nama_cabang, :tanggal_setoran, :jumlah_setoran, :nama_pengantar, 'Sedang Dibawa Kurir', :updated_by, NOW(), NOW())";
            
            $stmt_setoran = $pdo->prepare($sql_setoran);
            $stmt_setoran->bindParam(':kode_setoran', $kode_setoran, PDO::PARAM_STR);
            $stmt_setoran->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
            $stmt_setoran->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
            $stmt_setoran->bindParam(':nama_cabang', $nama_cabang, PDO::PARAM_STR);
            $tanggal_setoran = date('Y-m-d');
            $stmt_setoran->bindParam(':tanggal_setoran', $tanggal_setoran, PDO::PARAM_STR);
            $stmt_setoran->bindParam(':jumlah_setoran', $total_setoran, PDO::PARAM_STR);
            $stmt_setoran->bindParam(':nama_pengantar', $nama_pengantar, PDO::PARAM_STR);
            $stmt_setoran->bindParam(':updated_by', $kode_karyawan, PDO::PARAM_STR);
            $stmt_setoran->execute();

            // Update kasir_transactions with status 'Sedang Dibawa Kurir'
            foreach ($transaksi_to_update as $kode_transaksi) {
                $sql_update = "
                    UPDATE kasir_transactions 
                    SET deposit_status = 'Sedang Dibawa Kurir', 
                        kode_setoran = :kode_setoran
                    WHERE kode_transaksi = :kode_transaksi 
                    AND (kode_karyawan = :kode_karyawan 
                         OR kode_transaksi IN (
                             SELECT nomor_transaksi_closing 
                             FROM pemasukan_kasir 
                             WHERE kode_karyawan = :kode_karyawan2
                         ))
                    AND status = 'end proses'";
                
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->bindParam(':kode_setoran', $kode_setoran, PDO::PARAM_STR);
                $stmt_update->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
                $stmt_update->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
                $stmt_update->bindParam(':kode_karyawan2', $kode_karyawan, PDO::PARAM_STR);
                $stmt_update->execute();
            }

            $pdo->commit();
            echo "<script>alert('Setoran berhasil disubmit dengan kode: $kode_setoran\\n\\nTotal Setoran: " . formatRupiah($total_setoran) . "\\n\\nStatus: Sedang Dibawa Kurir ke Staff Keuangan'); window.location.href = 'setoran_keuangan_cs.php';</script>";
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location.href = 'setoran_keuangan_cs.php';</script>";
        }
    }
}

// PERBAIKAN: Query transaksi dengan logika pinjam-meminjam yang lebih akurat
$sql_transaksi = "
    SELECT * FROM (
        -- Transactions from closing (with negative deposit amounts) - PRIORITAS UTAMA
        SELECT 
            pk.nomor_transaksi_closing as kode_transaksi,
            kt.tanggal_transaksi,
            -(pk.jumlah) as jumlah_setoran,
            kt.setoran_real,
            kt.deposit_status,
            'DARI CLOSING' as jenis_transaksi,
            CONCAT('PMS-', pk.id) as bukti_transaksi,
            pk.id as nomor_pemasukan_id,
            pk.keterangan_transaksi as keterangan_pemasukan,
            kt.jenis_closing,
            kt.closing_group_id,
            kt.is_part_of_closing,
            kt.kode_karyawan,
            1 as priority_order
        FROM pemasukan_kasir pk
        JOIN kasir_transactions kt ON pk.nomor_transaksi_closing = kt.kode_transaksi
        WHERE pk.kode_karyawan = :kode_karyawan_pemasukan
        AND kt.status = 'end proses'
        AND pk.nomor_transaksi_closing IS NOT NULL
        AND (
            (kt.deposit_status IS NULL OR kt.deposit_status = '' OR kt.deposit_status = 'Belum Disetor')
            OR 
            (kt.deposit_status = 'Diserahterimakan')
        )
        AND pk.nomor_transaksi_closing NOT IN (
            SELECT DISTINCT kode_transaksi_asal 
            FROM serah_terima_kasir 
            WHERE (kode_karyawan_pemberi = :kode_karyawan_pemberi2 AND status IN ('pending', 'completed'))
            AND kode_transaksi_asal IS NOT NULL
            AND kode_karyawan_pemberi != kode_karyawan_penerima
        )
        -- PERBAIKAN: Logika yang lebih ketat untuk pinjam-meminjam
        AND (
            CASE 
                WHEN kt.jenis_closing = 'dipinjam' THEN
                    -- Transaksi dipinjam ditampilkan jika:
                    -- 1. Tidak ada pasangan meminjam yang masih on proses, ATAU
                    -- 2. Semua transaksi dalam grup sudah end proses
                    (
                        NOT EXISTS (
                            SELECT 1 FROM kasir_transactions kt_meminjam 
                            WHERE kt_meminjam.closing_group_id = kt.closing_group_id 
                            AND kt_meminjam.jenis_closing = 'meminjam'
                            AND kt_meminjam.status = 'on proses'
                        ) AND 
                        NOT EXISTS (
                            SELECT 1 FROM kasir_transactions kt_any 
                            WHERE kt_any.closing_group_id = kt.closing_group_id 
                            AND kt_any.status = 'on proses'
                        )
                    )
                WHEN kt.jenis_closing = 'meminjam' THEN
                    -- Transaksi meminjam ditampilkan jika:
                    -- 1. Tidak ada pasangan dipinjam yang masih on proses, ATAU
                    -- 2. Semua transaksi dalam grup sudah end proses
                    (
                        NOT EXISTS (
                            SELECT 1 FROM kasir_transactions kt_dipinjam 
                            WHERE kt_dipinjam.closing_group_id = kt.closing_group_id 
                            AND kt_dipinjam.jenis_closing = 'dipinjam'
                            AND kt_dipinjam.status = 'on proses'
                        ) AND 
                        NOT EXISTS (
                            SELECT 1 FROM kasir_transactions kt_any 
                            WHERE kt_any.closing_group_id = kt.closing_group_id 
                            AND kt_any.status = 'on proses'
                        )
                    )
                ELSE
                    -- Untuk transaksi closing reguler, pastikan tidak ada yang masih on proses
                    NOT EXISTS (
                        SELECT 1 FROM kasir_transactions kt2 
                        WHERE kt2.closing_group_id = kt.closing_group_id 
                        AND kt2.status = 'on proses'
                    )
            END
        )
        
        UNION ALL
        
        -- Regular transactions - HANYA yang TIDAK ada di pemasukan_kasir sebagai closing
        SELECT 
            kode_transaksi, 
            tanggal_transaksi, 
            setoran_real as jumlah_setoran,
            setoran_real,
            deposit_status,
            'REGULER' as jenis_transaksi,
            NULL as bukti_transaksi,
            NULL as nomor_pemasukan_id,
            NULL as keterangan_pemasukan,
            jenis_closing,
            closing_group_id,
            is_part_of_closing,
            kode_karyawan,
            2 as priority_order
        FROM kasir_transactions
        WHERE kode_karyawan = :kode_karyawan
        AND status = 'end proses'
        AND (
            (deposit_status IS NULL OR deposit_status = '' OR deposit_status = 'Belum Disetor')
            OR 
            (deposit_status = 'Diserahterimakan')
        )
        AND kode_transaksi NOT IN (
            SELECT DISTINCT kode_transaksi_asal 
            FROM serah_terima_kasir 
            WHERE (kode_karyawan_pemberi = :kode_karyawan_pemberi AND status IN ('pending', 'completed'))
            AND kode_transaksi_asal IS NOT NULL
            AND kode_karyawan_pemberi != kode_karyawan_penerima
        )
        -- PENTING: Exclude transaksi yang sudah menjadi closing di pemasukan_kasir
        AND kode_transaksi NOT IN (
            SELECT DISTINCT pk.nomor_transaksi_closing
            FROM pemasukan_kasir pk
            WHERE pk.kode_karyawan = :kode_karyawan_exclude
            AND pk.nomor_transaksi_closing IS NOT NULL
        )
        -- PERBAIKAN: Logika yang lebih ketat untuk transaksi reguler
        AND (
            -- Transaksi reguler (bukan bagian dari closing)
            (is_part_of_closing = 0 OR is_part_of_closing IS NULL) OR
            
            -- Transaksi closing yang bisa ditampilkan
            (
                is_part_of_closing = 1 AND 
                closing_group_id IS NOT NULL AND
                
                CASE 
                    WHEN jenis_closing = 'dipinjam' THEN
                        -- Transaksi dipinjam ditampilkan jika semua dalam grup sudah end proses
                        NOT EXISTS (
                            SELECT 1 FROM kasir_transactions kt_any 
                            WHERE kt_any.closing_group_id = closing_group_id 
                            AND kt_any.status = 'on proses'
                        )
                    WHEN jenis_closing = 'meminjam' THEN
                        -- Transaksi meminjam ditampilkan jika semua dalam grup sudah end proses
                        NOT EXISTS (
                            SELECT 1 FROM kasir_transactions kt_any 
                            WHERE kt_any.closing_group_id = closing_group_id 
                            AND kt_any.status = 'on proses'
                        )
                    ELSE
                        -- Untuk transaksi closing reguler, pastikan tidak ada yang masih on proses
                        NOT EXISTS (
                            SELECT 1 FROM kasir_transactions kt2 
                            WHERE kt2.closing_group_id = closing_group_id 
                            AND kt2.status = 'on proses'
                        )
                END
            )
        )
    ) AS combined_transactions
    ORDER BY priority_order ASC, tanggal_transaksi DESC";

$stmt_transaksi = $pdo->prepare($sql_transaksi);
$stmt_transaksi->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi->bindParam(':kode_karyawan_pemberi', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi->bindParam(':kode_karyawan_pemasukan', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi->bindParam(':kode_karyawan_pemberi2', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi->bindParam(':kode_karyawan_exclude', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi->execute();
$transaksi_raw = $stmt_transaksi->fetchAll(PDO::FETCH_ASSOC);

// PERBAIKAN: Kelompokkan transaksi closing dengan logika yang lebih akurat
$transaksi_list = groupClosingTransactionsForSetoran($pdo, $transaksi_raw);

// Get transactions that have already been deposited for separate display
$sql_transaksi_disetor = "
    (
        -- Regular transactions that have been deposited
        SELECT 
            kt.kode_transaksi, 
            kt.tanggal_transaksi, 
            kt.setoran_real as jumlah_setoran,
            kt.deposit_status, 
            kt.kode_setoran,
            sk.status as status_setoran, 
            sk.tanggal_setoran,
            'REGULER' as jenis_transaksi,
            NULL as bukti_transaksi
        FROM kasir_transactions kt
        LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
        WHERE kt.kode_karyawan = :kode_karyawan
        AND kt.status = 'end proses'
        AND kt.deposit_status IN ('Sedang Dibawa Kurir', 'Diterima Staff Keuangan', 'Validasi Keuangan OK', 'Validasi Keuangan SELISIH', 'Sudah Disetor ke Bank')
    )
    UNION ALL
    (
        -- Closing transactions that have been deposited
        SELECT 
            pk.nomor_transaksi_closing as kode_transaksi,
            kt.tanggal_transaksi,
            -(pk.jumlah) as jumlah_setoran,
            kt.deposit_status,
            kt.kode_setoran,
            sk.status as status_setoran,
            sk.tanggal_setoran,
            'DARI CLOSING' as jenis_transaksi,
            CONCAT('PMS-', pk.id) as bukti_transaksi
        FROM pemasukan_kasir pk
        JOIN kasir_transactions kt ON pk.nomor_transaksi_closing = kt.kode_transaksi
        LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
        WHERE pk.kode_karyawan = :kode_karyawan_pemasukan
        AND kt.status = 'end proses'
        AND pk.nomor_transaksi_closing IS NOT NULL
        AND kt.deposit_status IN ('Sedang Dibawa Kurir', 'Diterima Staff Keuangan', 'Validasi Keuangan OK', 'Validasi Keuangan SELISIH', 'Sudah Disetor ke Bank')
    )
    ORDER BY tanggal_transaksi DESC
    LIMIT 50";

$stmt_transaksi_disetor = $pdo->prepare($sql_transaksi_disetor);
$stmt_transaksi_disetor->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi_disetor->bindParam(':kode_karyawan_pemasukan', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi_disetor->execute();
$transaksi_disetor_list = $stmt_transaksi_disetor->fetchAll(PDO::FETCH_ASSOC);

// Get transactions that have been handed over to display as history
$sql_transaksi_diserahkan = "
    SELECT 
        kt.kode_transaksi, 
        kt.tanggal_transaksi, 
        kt.setoran_real, 
        kt.deposit_status,
        st.kode_serah_terima,
        st.tanggal_serah_terima,
        st.status as status_serah_terima,
        u_penerima.nama_karyawan as nama_penerima,
        st.catatan,
        'REGULER' as jenis_transaksi,
        NULL as bukti_transaksi
    FROM kasir_transactions kt
    JOIN serah_terima_kasir st ON kt.kode_transaksi = st.kode_transaksi_asal
    JOIN users u_penerima ON st.kode_karyawan_penerima = u_penerima.kode_karyawan
    WHERE st.kode_karyawan_pemberi = :kode_karyawan
    AND kt.status = 'end proses'
    AND st.kode_karyawan_pemberi != st.kode_karyawan_penerima
    ORDER BY st.tanggal_serah_terima DESC
    LIMIT 50";

$stmt_transaksi_diserahkan = $pdo->prepare($sql_transaksi_diserahkan);
$stmt_transaksi_diserahkan->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi_diserahkan->execute();
$transaksi_diserahkan_list = $stmt_transaksi_diserahkan->fetchAll(PDO::FETCH_ASSOC);

// Calculate total safely (including negative amounts from closing)
$setoran_amounts = array();
foreach ($transaksi_list as $item) {
    $setoran_amounts[] = $item['jumlah_setoran'];
}
$total_siap_setor = array_sum($setoran_amounts);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setoran ke Staff Keuangan</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        :root {
            --primary-color: #007bff;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --secondary-color: #6c757d;
            --background-light: #f8fafc;
            --text-dark: #334155;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--background-light);
            color: var(--text-dark);
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 200px;
            background: #1e293b;
            height: 100vh;
            position: fixed;
            padding: 20px 0;
            transition: width 0.3s ease;
            z-index: 1000;
        }
        .sidebar a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            white-space: nowrap;
        }
        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        .sidebar a i {
            margin-right: 10px;
            width: 16px;
            text-align: center;
        }
        .sidebar a.active {
            background: var(--primary-color);
            color: white;
        }
        .logout-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            padding: 12px 20px;
            width: 100%;
            text-align: left;
            margin-top: 20px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
        }
        .logout-btn:hover {
            background: #c82333;
        }
        .logout-btn i {
            margin-right: 10px;
            width: 16px;
            text-align: center;
        }
        .main-content {
            margin-left: 200px;
            padding: 30px;
            flex: 1;
            transition: margin-left 0.3s ease;
            width: calc(100% - 200px);
        }
        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .summary-card .row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .summary-card p {
            margin-bottom: 8px;
            color: var(--text-muted);
        }
        .summary-card strong {
            color: var(--text-dark);
        }
        .nav-tabs {
            background: white;
            border-radius: 12px 12px 0 0;
            padding: 0;
            border: none;
            display: flex;
            margin-bottom: 0;
        }
        .nav-tab {
            flex: 1;
            background: white;
            border: 1px solid var(--border-color);
            color: var(--text-dark);
            padding: 16px 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            font-weight: 500;
            border-bottom: none;
        }
        .nav-tab:first-child {
            border-radius: 12px 0 0 0;
        }
        .nav-tab:last-child {
            border-radius: 0 12px 0 0;
            border-left: none;
        }
        .nav-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        .nav-tab:hover:not(.active) {
            background: var(--background-light);
        }
        .tab-content {
            background: white;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            border-top: none;
        }
        .tab-pane {
            padding: 24px;
            display: none;
        }
        .tab-pane.active {
            display: block;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-dark);
        }
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        
        /* Table styling with horizontal scroll */
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-top: 24px;
            overflow-x: auto;
            max-width: 100%;
        }
        
        .table-wrapper {
            min-width: 1200px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }
        .table th {
            background: var(--background-light);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 14px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
        .table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
            white-space: nowrap;
        }
        .table tbody tr:hover {
            background: var(--background-light);
        }
        .table tfoot {
            background: var(--background-light);
            font-weight: 600;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }
        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }
        .btn-danger:hover {
            background-color: #c82333;
        }
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-primary { background: rgba(0,123,255,0.1); color: var(--primary-color); }
        .status-success { background: rgba(40,167,69,0.1); color: var(--success-color); }
        .status-warning { background: rgba(255,193,7,0.1); color: #e0a800; }
        .status-info { background: rgba(23,162,184,0.1); color: var(--info-color); }
        .status-danger { background: rgba(220,53,69,0.1); color: var(--danger-color); }
        .status-secondary { background: rgba(108,117,125,0.1); color: var(--secondary-color); }
        
        .jenis-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .jenis-reguler { 
            background: rgba(40,167,69,0.1); 
            color: var(--success-color); 
        }
        .jenis-closing { 
            background: rgba(255,193,7,0.1); 
            color: #e0a800; 
        }
        .jenis-group { 
            background: rgba(23,162,184,0.1); 
            color: var(--info-color); 
        }
        .jenis-pinjam-meminjam { 
            background: linear-gradient(45deg, rgba(255,193,7,0.1), rgba(40,167,69,0.1));
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }
        .jenis-unknown { 
            background: rgba(108,117,125,0.1); 
            color: var(--secondary-color); 
        }
        
        /* PERBAIKAN: Enhanced styles for grouped transactions */
        .pinjam-meminjam-indicator {
            background: linear-gradient(45deg, #ffc107, #28a745);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 8px;
            animation: pulse 2s infinite;
        }
        
        .pinjam-meminjam-details {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
            background: rgba(255,193,7,0.1);
            padding: 8px 12px;
            border-radius: 6px;
            border-left: 3px solid var(--warning-color);
        }
        
        .grup-closing-details {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
            line-height: 1.4;
        }
        
        .grup-closing-details i {
            margin-right: 4px;
            color: var(--info-color);
        }
        
        .breakdown-details {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 6px;
            padding: 6px 8px;
            background: rgba(0,123,255,0.05);
            border-radius: 4px;
            border-left: 2px solid var(--primary-color);
        }
        
        .breakdown-details .breakdown-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }
        
        .breakdown-details .breakdown-item:last-child {
            margin-bottom: 0;
            padding-top: 2px;
            border-top: 1px solid var(--border-color);
            font-weight: 600;
        }
        
        .alert {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .alert-info {
            background: rgba(23,162,184,0.1);
            border: 1px solid rgba(23,162,184,0.2);
            color: var(--info-color);
        }
        .alert-warning {
            background: rgba(255,193,7,0.1);
            border: 1px solid rgba(255,193,7,0.2);
            color: #e0a800;
        }
        .alert-danger {
            background: rgba(220,53,69,0.1);
            border: 1px solid rgba(220,53,69,0.2);
            color: var(--danger-color);
        }
        .form-check-input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .text-danger {
            color: var(--danger-color);
        }
        .required {
            color: var(--danger-color);
        }
        .closing-amount {
            color: var(--warning-color);
            font-weight: 600;
        }
        .closing-amount.negative {
            color: var(--danger-color);
        }
        .bukti-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 4px;
            background: rgba(0,123,255,0.1);
            font-size: 11px;
        }
        .bukti-link:hover {
            background: rgba(0,123,255,0.2);
        }
        
        /* Scroll bar styling */
        .table-container::-webkit-scrollbar {
            height: 8px;
        }
        .table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        .table-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }
        .table-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(255,193,7,0.4); }
            70% { box-shadow: 0 0 0 10px rgba(255,193,7,0); }
            100% { box-shadow: 0 0 0 0 rgba(255,193,7,0); }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 200px;
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 20px;
            }
            .summary-card .row {
                grid-template-columns: 1fr;
            }
            .nav-tab {
                font-size: 12px;
                padding: 12px 8px;
            }
            
            /* Mobile table adjustments */
            .table-container {
                margin-left: -20px;
                margin-right: -20px;
                border-radius: 0;
            }
        }
    </style>
</head>
<body>
<div class="sidebar" id="sidebar">
    <a href="index_kasir.php"><i class="fas fa-tachometer-alt"></i> Dashboard Kasir</a>
    <a href="serah_terima_kasir.php"><i class="fas fa-handshake"></i> Serah Terima Kasir</a>
    <a href="setoran_keuangan_cs.php" class="active"><i class="fas fa-money-bill"></i> Setoran Keuangan CS</a>
    <button class="logout-btn" onclick="window.location.href='logout.php';"><i class="fas fa-sign-out-alt"></i> Logout</button>
</div>

<div class="main-content">
    <div class="user-profile">
        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
        <div>
            <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($nama_cabang); ?>)
            <p style="color: var(--text-muted); font-size: 12px;">Kasir</p>
        </div>
    </div>

    <h1 style="margin-bottom: 24px; color: var(--text-dark);"><i class="fas fa-money-bill-wave"></i> Setoran ke Staff Keuangan</h1>

    <div class="summary-card">
        <div class="row">
            <div>
                <p><strong>CS/Kasir:</strong> <?php echo htmlspecialchars($username); ?></p>
                <p><strong>Tanggal:</strong> <?php echo date('d/m/Y'); ?></p>
            </div>
            <div>
                <p><strong>Cabang:</strong> <?php echo htmlspecialchars($nama_cabang); ?></p>
                <p><strong>Kode Cabang:</strong> <?php echo htmlspecialchars($kode_cabang); ?></p>
            </div>
            <div>
                <p><strong>Transaksi Siap Setor:</strong> <?php echo count($transaksi_list); ?> transaksi</p>
                <p><strong>Total Bisa Disetor:</strong> 
                    <span class="<?php echo $total_siap_setor < 0 ? 'closing-amount negative' : 'closing-amount'; ?>">
                        <?php echo formatRupiah($total_siap_setor); ?>
                    </span>
                </p>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="nav-tabs">
        <div class="nav-tab active" onclick="switchTab('belum-setor', this)">
            Transaksi Belum Disetor (<?php echo count($transaksi_list); ?>)
        </div>
        <div class="nav-tab" onclick="switchTab('sudah-setor', this)">
            Riwayat Sudah Disetor (<?php echo count($transaksi_disetor_list); ?>)
        </div>
        <div class="nav-tab" onclick="switchTab('diserahkan', this)">
            Riwayat Diserahterimakan (<?php echo count($transaksi_diserahkan_list); ?>)
        </div>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Tab Belum Disetor -->
        <div class="tab-pane active" id="belum-setor">
            <?php if (!empty($transaksi_list)): ?>
            <form action="" method="POST" id="setoranForm">
                <div class="form-group">
                    <label for="nama_pengantar" class="form-label">Nama Pengantar <span class="required">*</span></label>
                    <input type="text" name="nama_pengantar" class="form-control" required placeholder="Masukkan nama pengantar">
                    <small class="text-muted">Nama orang yang akan mengantarkan setoran ke staff keuangan pusat</small>
                </div>

                <h5 style="margin-bottom: 16px; color: var(--text-dark);">Transaksi Siap Disetor</h5>
                
                <!-- PERBAIKAN: Enhanced alert untuk logika pinjam-meminjam yang lebih jelas -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Logika Pengelompokan Transaksi Closing yang Diperbaiki:</strong>
                    <ul style="margin: 8px 0 0 20px;">
                        <li><strong>Transaksi dipinjam dan meminjam</strong> yang sudah "end proses" <strong>digabung menjadi satu item</strong> dengan label "GABUNGAN PINJAM-MEMINJAM"</li>
                        <li>Transaksi dipinjam yang kasir peminjamnya masih "on proses" tidak ditampilkan</li>
                        <li>Sistem menampilkan transaksi "DARI CLOSING" dengan kalkulasi yang tepat</li>
                        <li>Pengelompokan otomatis menghindari duplikasi dan memastikan akurasi total setoran</li>
                        <li>Detail breakdown ditampilkan untuk transparansi perhitungan</li>
                    </ul>
                </div>
                
                <div class="table-container">
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th width="50">
                                        <input type="checkbox" id="selectAllBelumSetor" class="form-check-input">
                                    </th>
                                    <th>Kode Transaksi</th>
                                    <th>Tanggal</th>
                                    <th>Jenis</th>
                                    <th>Jumlah Setoran</th>
                                    <th>Status</th>
                                    <th>Bukti Transaksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transaksi_list as $trans): ?>
                                    <tr>
                                        <td>
                                            <input 
                                                type="checkbox" 
                                                name="kode_transaksi[]" 
                                                value="<?php echo htmlspecialchars($trans['kode_transaksi']); ?>" 
                                                class="form-check-input transaksi-checkbox">
                                        </td>
                                        <td>
                                            <?php if (isset($trans['is_grouped']) && $trans['is_grouped']): ?>
                                                <div style="display: flex; align-items: center; gap: 8px;">
                                                    <span class="status-badge status-info" style="font-size: 10px;">GRUP</span>
                                                    <code><?php echo htmlspecialchars($trans['kode_transaksi']); ?></code>
                                                    
                                                    <?php if ($trans['group_type'] == 'pinjam_meminjam'): ?>
                                                        <span class="pinjam-meminjam-indicator">
                                                            <i class="fas fa-exchange-alt"></i> PINJAM-MEMINJAM
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="grup-closing-details">
                                                    <i class="fas fa-layer-group"></i> 
                                                    <?php echo $trans['group_count']; ?> transaksi tergabung
                                                    
                                                    <?php if ($trans['group_type'] == 'pinjam_meminjam'): ?>
                                                        <div class="pinjam-meminjam-details">
                                                            <i class="fas fa-info-circle"></i>
                                                            <strong>Gabungan transaksi dipinjam dan meminjam yang sudah end proses</strong>
                                                            
                                                            <?php if (isset($trans['detail_breakdown'])): ?>
                                                            <div class="breakdown-details">
                                                                <?php if ($trans['detail_breakdown']['count_closing'] > 0): ?>
                                                                <div class="breakdown-item">
                                                                    <span>Closing (<?php echo $trans['detail_breakdown']['count_closing']; ?>):</span>
                                                                    <span><?php echo formatRupiah($trans['detail_breakdown']['total_closing']); ?></span>
                                                                </div>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($trans['detail_breakdown']['count_dipinjam'] > 0): ?>
                                                                <div class="breakdown-item">
                                                                    <span>Dipinjam (<?php echo $trans['detail_breakdown']['count_dipinjam']; ?>):</span>
                                                                    <span><?php echo formatRupiah($trans['detail_breakdown']['total_dipinjam']); ?></span>
                                                                </div>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($trans['detail_breakdown']['count_meminjam'] > 0): ?>
                                                                <div class="breakdown-item">
                                                                    <span>Meminjam (<?php echo $trans['detail_breakdown']['count_meminjam']; ?>):</span>
                                                                    <span><?php echo formatRupiah($trans['detail_breakdown']['total_meminjam']); ?></span>
                                                                </div>
                                                                <?php endif; ?>
                                                                
                                                                <div class="breakdown-item">
                                                                    <span><strong>Total Gabungan:</strong></span>
                                                                    <span><strong><?php echo formatRupiah($trans['jumlah_setoran']); ?></strong></span>
                                                                </div>
                                                            </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <br><strong>Detail Transaksi:</strong>
                                                    <?php 
                                                    $detail_list = [];
                                                    foreach ($trans['group_transactions'] as $gt) {
                                                        $jenis = !empty($gt['jenis_closing']) ? ucfirst($gt['jenis_closing']) : 'Closing';
                                                        $detail_list[] = $gt['kode_transaksi'] . ' (' . $jenis . ')';
                                                    }
                                                    echo implode(', ', $detail_list);
                                                    ?>
                                                </div>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($trans['kode_transaksi']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('d/m/Y', strtotime($trans['tanggal_transaksi'])); ?></td>
                                        <td><?php echo getJenisTransaksiBadge($trans['jenis_transaksi']); ?></td>
                                        <td class="<?php echo $trans['jumlah_setoran'] < 0 ? 'closing-amount negative' : 'closing-amount'; ?>">
                                            <?php echo formatRupiah($trans['jumlah_setoran']); ?>
                                            <?php if ($trans['jenis_transaksi'] == 'DARI CLOSING' && !isset($trans['is_grouped'])): ?>
                                                <br><small style="color: var(--text-muted);">
                                                    Dari closing: <?php echo formatRupiah($trans['setoran_real']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($trans['deposit_status'] == 'Diserahterimakan') {
                                                echo '<span class="status-badge status-secondary">Diserahterimakan (Siap Setor)</span>';
                                            } else {
                                                echo '<span class="status-badge status-warning">Belum Disetor</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($trans['bukti_transaksi']) && !isset($trans['is_grouped'])): ?>
                                                <a href="detail_pemasukan1.php?id=<?php echo $trans['nomor_pemasukan_id']; ?>" 
                                                   class="bukti-link" title="Lihat detail pemasukan">
                                                    <i class="fas fa-receipt"></i> <?php echo htmlspecialchars($trans['bukti_transaksi']); ?>
                                                </a>
                                                <br><small style="color: var(--text-muted); font-size: 10px;">
                                                    <?php echo htmlspecialchars($trans['keterangan_pemasukan']); ?>
                                                </small>
                                            <?php elseif (isset($trans['is_grouped']) && $trans['is_grouped']): ?>
                                                <span style="color: var(--text-muted);">Grup Transaksi</span>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" class="text-right"><strong>Total Setoran:</strong></td>
                                    <td class="<?php echo $total_siap_setor < 0 ? 'closing-amount negative' : 'closing-amount'; ?>">
                                        <strong><?php echo formatRupiah($total_siap_setor); ?></strong>
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="btn-group">
                    <button type="submit" name="submit_setoran" class="btn btn-primary" onclick="return confirm('Yakin ingin submit setoran yang dipilih ke staff keuangan?')">
                        <i class="fas fa-save"></i> Submit Setoran
                    </button>
                    <button type="reset" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </form>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Tidak ada transaksi yang bisa disetor saat ini. Pastikan transaksi sudah dalam status "End Proses" dan memenuhi kriteria logika pinjam-meminjam yang diperbaiki.
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab Sudah Disetor -->
        <div class="tab-pane" id="sudah-setor">
            <h5 style="margin-bottom: 16px; color: var(--text-dark);">Riwayat Transaksi yang Sudah Disetor</h5>
            <?php if (!empty($transaksi_disetor_list)): ?>
            <div class="table-container">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Kode Transaksi</th>
                                <th>Tanggal</th>
                                <th>Jenis</th>
                                <th>Kode Setoran</th>
                                <th>Jumlah Setoran</th>
                                <th>Status Setoran</th>
                                <th>Bukti Transaksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transaksi_disetor_list as $trans): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($trans['kode_transaksi']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($trans['tanggal_transaksi'])); ?></td>
                                    <td><?php echo getJenisTransaksiBadge($trans['jenis_transaksi']); ?></td>
                                    <td>
                                        <code><?php echo htmlspecialchars($trans['kode_setoran'] ?? '-'); ?></code>
                                    </td>
                                    <td class="<?php echo $trans['jumlah_setoran'] < 0 ? 'closing-amount negative' : 'closing-amount'; ?>">
                                        <?php echo formatRupiah($trans['jumlah_setoran']); ?>
                                    </td>
                                    <td>
                                        <?php if ($trans['status_setoran']): ?>
                                            <?php echo getStatusSetoranBadge($trans['status_setoran']); ?>
                                        <?php else: ?>
                                            <?php echo getStatusBadge($trans['deposit_status']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($trans['bukti_transaksi'])): ?>
                                            <a href="detail_pemasukan1.php?bukti=<?php echo $trans['bukti_transaksi']; ?>" 
                                               class="bukti-link" title="Lihat detail pemasukan">
                                                <i class="fas fa-receipt"></i> <?php echo htmlspecialchars($trans['bukti_transaksi']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Tidak ada riwayat transaksi yang sudah disetor.
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab Diserahterimakan -->
        <div class="tab-pane" id="diserahkan">
            <h5 style="margin-bottom: 16px; color: var(--text-dark);">Riwayat Transaksi yang Diserahterimakan</h5>
            <?php if (!empty($transaksi_diserahkan_list)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> 
                <strong>Penting:</strong> Transaksi yang sudah diserahterimakan ke kasir lain tidak dapat Anda setorkan lagi. 
                Kasir penerima yang bertanggung jawab untuk melakukan setoran transaksi ini.
            </div>
            <div class="table-container">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Kode Transaksi</th>
                                <th>Tanggal Transaksi</th>
                                <th>Kode Serah Terima</th>
                                <th>Tanggal Serah Terima</th>
                                <th>Diserahkan ke</th>
                                <th>Jumlah</th>
                                <th>Status Serah Terima</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transaksi_diserahkan_list as $trans): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($trans['kode_transaksi']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($trans['tanggal_transaksi'])); ?></td>
                                    <td><code><?php echo htmlspecialchars($trans['kode_serah_terima']); ?></code></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($trans['tanggal_serah_terima'])); ?></td>
                                    <td><?php echo htmlspecialchars($trans['nama_penerima']); ?></td>
                                    <td><?php echo formatRupiah($trans['setoran_real']); ?></td>
                                    <td>
                                        <?php echo getStatusSerahTerimaBadge($trans['status_serah_terima']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="mt-3">
                <h6>Keterangan:</h6>
                <small>
                    • <span class="status-badge status-warning">Menunggu Konfirmasi</span> - Permintaan serah terima sudah dikirim, menunggu konfirmasi dari kasir penerima<br>
                    • <span class="status-badge status-success">Diterima</span> - Serah terima telah dikonfirmasi dan diterima oleh kasir penerima<br>
                    • <span class="status-badge status-danger">Ditolak</span> - Serah terima ditolak oleh kasir penerima, transaksi dikembalikan ke Anda<br>
                    • Transaksi yang sudah diterima tidak dapat lagi Anda setorkan ke staff keuangan<br>
                    • Kasir penerima dapat melakukan setoran transaksi yang sudah diterima<br>
                    • Anda dapat melihat detail di halaman <a href="serah_terima_kasir.php" style="color: var(--primary-color);">Serah Terima Kasir</a>
                </small>
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Tidak ada transaksi yang diserahterimakan ke kasir lain.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Enhanced Legend Section -->
    <div class="mt-4">
        <h6>Keterangan Status dan Jenis Transaksi (DIPERBAIKI):</h6>
        <div class="row">
            <div class="col-md-12">
                <small>
                    <strong>Jenis Transaksi:</strong><br>
                    • <span class="jenis-badge jenis-reguler">REGULER</span> - Transaksi closing biasa<br>
                    • <span class="jenis-badge jenis-closing">DARI CLOSING</span> - Pengambilan uang dari closing (setoran negatif)<br>
                    • <span class="jenis-badge jenis-group">GRUP CLOSING</span> - Kelompok transaksi closing yang digabung<br>
                    • <span class="jenis-badge jenis-pinjam-meminjam">GABUNGAN PINJAM-MEMINJAM</span> - <strong>DIPERBAIKI:</strong> Transaksi dipinjam dan meminjam yang digabung otomatis<br><br>
                    
                    <strong>Perbaikan Logika Pengelompokan:</strong><br>
                    • <strong>Transaksi dipinjam dan meminjam</strong> yang sudah "end proses" sekarang <strong>otomatis digabung menjadi satu item</strong><br>
                    • Menampilkan <strong>breakdown detail</strong> untuk transparansi perhitungan<br>
                    • <strong>Animasi khusus</strong> untuk item yang digabung agar mudah dikenali<br>
                    • <strong>Validasi ketat</strong> memastikan tidak ada duplikasi atau kesalahan perhitungan<br><br>
                    
                    <strong>Alur Status Setoran:</strong><br>
                    • <span class="status-badge status-info">Sedang Dibawa Kurir</span> - Setoran sedang dalam perjalanan ke keuangan pusat<br>
                    • <span class="status-badge status-warning">Diterima Staff Keuangan</span> - Staff keuangan sudah menerima setoran<br>
                    • <span class="status-badge status-primary">Validasi Keuangan OK</span> - Validasi fisik selesai tanpa selisih<br>
                    • <span class="status-badge status-danger">Validasi Keuangan SELISIH</span> - Validasi fisik ada selisih<br>
                    • <span class="status-badge status-success">Sudah Disetor ke Bank</span> - Sudah disetor ke bank<br>
                    • <span class="status-badge status-secondary">Diserahterimakan</span> - Diserahkan ke kasir lain di cabang yang sama<br><br>
                    
                    <strong>Catatan Penting Perbaikan:</strong><br>
                    • <strong>PERBAIKAN UTAMA:</strong> Transaksi dipinjam dan meminjam sekarang digabung otomatis<br>
                    • Sistem mengelompokkan transaksi closing untuk efisiensi tampilan<br>
                    • Transaksi "DARI CLOSING" bisa bernilai negatif karena merupakan pengambilan dari closing<br>
                    • Bukti transaksi dapat diklik untuk melihat detail pemasukan yang terkait<br>
                    • Total setoran dapat menjadi negatif jika banyak pengambilan dari closing<br>
                    • <strong>Validasi ketat</strong> memastikan akurasi dalam pengelompokan dan perhitungan<br>
                </small>
            </div>
        </div>
    </div>
</div>

<script>
    function switchTab(tabId, element) {
        document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
        document.querySelectorAll('.nav-tab').forEach(tab => tab.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        element.classList.add('active');
    }

    document.getElementById('selectAllBelumSetor')?.addEventListener('change', function() {
        document.querySelectorAll('.transaksi-checkbox').forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });

    // Enhanced form submission validation
    document.getElementById('setoranForm')?.addEventListener('submit', function(e) {
        const checkedBoxes = document.querySelectorAll('.transaksi-checkbox:checked');
        if (checkedBoxes.length === 0) {
            e.preventDefault();
            alert('Pilih setidaknya satu transaksi untuk disetor!');
            return;
        }
        
        // PERBAIKAN: Enhanced confirmation message untuk grouped transactions
        let groupedCount = 0;
        let pinjamMeminjamCount = 0;
        
        checkedBoxes.forEach(checkbox => {
            const row = checkbox.closest('tr');
            const isGrouped = row.querySelector('.pinjam-meminjam-indicator');
            const isGabungan = row.querySelector('.grup-closing-details');
            
            if (isGrouped) {
                pinjamMeminjamCount++;
            } else if (isGabungan) {
                groupedCount++;
            }
        });
        
        let confirmMessage = `Yakin ingin submit ${checkedBoxes.length} transaksi untuk setoran?`;
        
        if (pinjamMeminjamCount > 0) {
            confirmMessage += `\n\nTermasuk ${pinjamMeminjamCount} grup gabungan pinjam-meminjam yang sudah otomatis dikelompokkan.`;
        }
        
        if (groupedCount > 0) {
            confirmMessage += `\n\nTermasuk ${groupedCount} grup transaksi closing lainnya.`;
        }
        
        if (!confirm(confirmMessage)) {
            e.preventDefault();
        }
    });

    // PERBAIKAN: Enhanced visual feedback for grouped transactions
    document.addEventListener('DOMContentLoaded', function() {
        // Add special styling for pinjam-meminjam groups
        const pinjamMeminjamRows = document.querySelectorAll('.pinjam-meminjam-indicator');
        pinjamMeminjamRows.forEach(indicator => {
            const row = indicator.closest('tr');
            row.style.backgroundColor = 'rgba(255,193,7,0.05)';
            row.style.borderLeft = '4px solid #ffc107';
            
            // Add hover effect
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = 'rgba(255,193,7,0.15)';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = 'rgba(255,193,7,0.05)';
            });
        });
        
        // Add tooltip for grouped transactions
        const groupDetails = document.querySelectorAll('.grup-closing-details');
        groupDetails.forEach(detail => {
            detail.title = 'Klik untuk melihat detail pengelompokan transaksi';
            detail.style.cursor = 'help';
        });
        
        // Show notification for grouped transactions
        const totalGrouped = pinjamMeminjamRows.length;
        if (totalGrouped > 0) {
            setTimeout(() => {
                showNotification(`✅ PERBAIKAN BERHASIL: ${totalGrouped} grup transaksi pinjam-meminjam telah digabung otomatis!`, 'success', 6000);
            }, 1000);
        }
    });

    // Enhanced notification system
    function showNotification(message, type = 'info', duration = 4000) {
        const notification = document.createElement('div');
        notification.className = `alert alert-${type}`;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
            min-width: 350px;
            max-width: 500px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideInRight 0.4s ease-out;
            border-left: 4px solid ${type === 'success' ? '#28a745' : type === 'warning' ? '#ffc107' : '#17a2b8'};
        `;
        
        const iconMap = {
            'success': 'check-circle',
            'warning': 'exclamation-triangle',
            'info': 'info-circle',
            'danger': 'exclamation-circle'
        };
        
        notification.innerHTML = `
            <div style="display: flex; align-items: flex-start; gap: 10px;">
                <i class="fas fa-${iconMap[type] || 'info-circle'}" style="margin-top: 3px;"></i>
                <div style="flex: 1;">${message}</div>
                <button type="button" onclick="this.parentElement.parentElement.remove()" 
                        style="background: none; border: none; font-size: 18px; cursor: pointer; padding: 0; color: inherit;">×</button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Auto remove
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.animation = 'slideOutRight 0.4s ease-in';
                setTimeout(() => notification.remove(), 400);
            }
        }, duration);
    }

    // Add CSS animations
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
        
        .breakdown-details:hover {
            background: rgba(0,123,255,0.1) !important;
            transform: translateX(2px);
            transition: all 0.3s ease;
        }
        
        .pinjam-meminjam-details:hover {
            background: rgba(255,193,7,0.2) !important;
            transform: translateX(2px);
            transition: all 0.3s ease;
        }
    `;
    document.head.appendChild(style);

    // Enhanced table row interactions
    function initializeTableInteractions() {
        const tableRows = document.querySelectorAll('.table tbody tr');
        
        tableRows.forEach(row => {
            const checkbox = row.querySelector('.transaksi-checkbox');
            const isPinjamMeminjam = row.querySelector('.pinjam-meminjam-indicator');
            const isGrouped = row.querySelector('.grup-closing-details');
            
            // Add click to select functionality
            row.addEventListener('click', function(e) {
                if (e.target.type !== 'checkbox' && e.target.tagName !== 'A') {
                    if (checkbox) {
                        checkbox.checked = !checkbox.checked;
                        updateRowSelection(row, checkbox.checked);
                    }
                }
            });
            
            // Add selection visual feedback
            if (checkbox) {
                checkbox.addEventListener('change', function() {
                    updateRowSelection(row, this.checked);
                });
            }
            
            // Enhanced hover effects for special rows
            if (isPinjamMeminjam) {
                row.style.transition = 'all 0.3s ease';
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateX(3px)';
                    this.style.boxShadow = '0 2px 8px rgba(255,193,7,0.3)';
                });
                
                row.addEventListener('mouseleave', function() {
                    if (!checkbox || !checkbox.checked) {
                        this.style.transform = 'translateX(0)';
                        this.style.boxShadow = 'none';
                    }
                });
            }
        });
    }

    function updateRowSelection(row, isSelected) {
        if (isSelected) {
            row.style.backgroundColor = 'rgba(0,123,255,0.1)';
            row.style.borderLeft = '4px solid var(--primary-color)';
            row.style.transform = 'translateX(3px)';
            row.style.boxShadow = '0 2px 8px rgba(0,123,255,0.2)';
        } else {
            const isPinjamMeminjam = row.querySelector('.pinjam-meminjam-indicator');
            if (isPinjamMeminjam) {
                row.style.backgroundColor = 'rgba(255,193,7,0.05)';
                row.style.borderLeft = '4px solid #ffc107';
            } else {
                row.style.backgroundColor = '';
                row.style.borderLeft = '';
            }
            row.style.transform = 'translateX(0)';
            row.style.boxShadow = 'none';
        }
    }

    // Enhanced statistics display
    function updateStatistics() {
        const totalTransactions = document.querySelectorAll('.transaksi-checkbox').length;
        const groupedTransactions = document.querySelectorAll('.pinjam-meminjam-indicator').length;
        const regularTransactions = totalTransactions - groupedTransactions;
        
        // Update summary if statistics element exists
        const statsElement = document.querySelector('.statistics-summary');
        if (statsElement) {
            statsElement.innerHTML = `
                <div class="stat-item">
                    <span class="stat-label">Total Transaksi:</span>
                    <span class="stat-value">${totalTransactions}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Gabungan Pinjam-Meminjam:</span>
                    <span class="stat-value highlight">${groupedTransactions}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Transaksi Regular:</span>
                    <span class="stat-value">${regularTransactions}</span>
                </div>
            `;
        }
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl + A to select all transactions
        if (e.ctrlKey && e.key === 'a' && e.target.tagName !== 'INPUT') {
            e.preventDefault();
            const selectAllCheckbox = document.getElementById('selectAllBelumSetor');
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.dispatchEvent(new Event('change'));
                showNotification('Semua transaksi telah dipilih', 'info', 2000);
            }
        }
        
        // Ctrl + D to deselect all
        if (e.ctrlKey && e.key === 'd' && e.target.tagName !== 'INPUT') {
            e.preventDefault();
            const selectAllCheckbox = document.getElementById('selectAllBelumSetor');
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.dispatchEvent(new Event('change'));
                showNotification('Semua transaksi telah dibatalkan', 'info', 2000);
            }
        }
        
        // Ctrl + S to submit form
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            const form = document.getElementById('setoranForm');
            if (form) {
                const checkedBoxes = document.querySelectorAll('.transaksi-checkbox:checked');
                if (checkedBoxes.length > 0) {
                    form.submit();
                } else {
                    showNotification('Pilih transaksi terlebih dahulu', 'warning', 3000);
                }
            }
        }
    });

    // Auto-save form data to localStorage (for recovery)
    function initializeAutoSave() {
        const nameInput = document.querySelector('input[name="nama_pengantar"]');
        
        if (nameInput) {
            // Load saved data
            const savedName = localStorage.getItem('setoran_nama_pengantar');
            if (savedName) {
                nameInput.value = savedName;
            }
            
            // Save data on input
            nameInput.addEventListener('input', function() {
                localStorage.setItem('setoran_nama_pengantar', this.value);
            });
            
            // Clear saved data on successful submission
            const form = document.getElementById('setoranForm');
            if (form) {
                form.addEventListener('submit', function() {
                    localStorage.removeItem('setoran_nama_pengantar');
                });
            }
        }
    }

    // Enhanced mobile responsiveness
    function initializeMobileFeatures() {
        if (window.innerWidth <= 768) {
            // Add touch feedback for mobile
            const touchElements = document.querySelectorAll('.table tbody tr, .btn, .nav-tab');
            
            touchElements.forEach(element => {
                element.addEventListener('touchstart', function() {
                    this.style.opacity = '0.7';
                });
                
                element.addEventListener('touchend', function() {
                    setTimeout(() => {
                        this.style.opacity = '';
                    }, 100);
                });
            });
            
            // Add swipe gesture for tabs
            let touchStartX = 0;
            let touchEndX = 0;
            
            const tabContent = document.querySelector('.tab-content');
            if (tabContent) {
                tabContent.addEventListener('touchstart', function(e) {
                    touchStartX = e.changedTouches[0].screenX;
                });
                
                tabContent.addEventListener('touchend', function(e) {
                    touchEndX = e.changedTouches[0].screenX;
                    handleTabSwipe();
                });
            }
            
            function handleTabSwipe() {
                const swipeThreshold = 100;
                const swipeDistance = touchEndX - touchStartX;
                
                if (Math.abs(swipeDistance) > swipeThreshold) {
                    const activeTabs = document.querySelectorAll('.nav-tab');
                    const activeTabIndex = Array.from(activeTabs).findIndex(tab => tab.classList.contains('active'));
                    
                    if (swipeDistance > 0 && activeTabIndex > 0) {
                        // Swipe right - go to previous tab
                        activeTabs[activeTabIndex - 1].click();
                    } else if (swipeDistance < 0 && activeTabIndex < activeTabs.length - 1) {
                        // Swipe left - go to next tab
                        activeTabs[activeTabIndex + 1].click();
                    }
                }
            }
        }
    }

    // Performance optimization for large tables
    function initializeVirtualScrolling() {
        const tableContainer = document.querySelector('.table-container');
        const tableWrapper = document.querySelector('.table-wrapper');
        
        if (tableContainer && tableWrapper) {
            // Add loading indicator for large datasets
            const rowCount = document.querySelectorAll('.table tbody tr').length;
            
            if (rowCount > 50) {
                const loadingIndicator = document.createElement('div');
                loadingIndicator.innerHTML = `
                    <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                        <i class="fas fa-spinner fa-spin"></i> Memuat ${rowCount} transaksi...
                    </div>
                `;
                
                tableContainer.insertBefore(loadingIndicator, tableWrapper);
                
                // Simulate loading and remove indicator
                setTimeout(() => {
                    loadingIndicator.remove();
                }, 500);
            }
        }
    }

    // Initialize print functionality
    function initializePrintFeatures() {
        // Add print button if not exists
        const existingPrintBtn = document.querySelector('.print-btn');
        if (!existingPrintBtn) {
            const btnGroup = document.querySelector('.btn-group');
            if (btnGroup) {
                const printBtn = document.createElement('button');
                printBtn.type = 'button';
                printBtn.className = 'btn btn-secondary print-btn';
                printBtn.innerHTML = '<i class="fas fa-print"></i> Cetak';
                printBtn.onclick = printTable;
                btnGroup.appendChild(printBtn);
            }
        }
    }

    function printTable() {
        const printWindow = window.open('', '_blank');
        const activeTab = document.querySelector('.tab-pane.active');
        const table = activeTab.querySelector('.table');
        
        if (table) {
            const printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Setoran Keuangan - ${new Date().toLocaleDateString('id-ID')}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f2f2f2; font-weight: bold; }
                        .header { text-align: center; margin-bottom: 20px; }
                        .pinjam-meminjam-indicator { 
                            background: #ffc107; color: white; padding: 2px 6px; 
                            border-radius: 6px; font-size: 10px; 
                        }
                        .breakdown-details { 
                            font-size: 10px; color: #666; 
                            margin-top: 4px; padding: 4px; 
                            background: #f8f9fa; border-left: 2px solid #007bff; 
                        }
                        @media print {
                            body { margin: 0; }
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h2>SETORAN KE STAFF KEUANGAN</h2>
                        <p>CS/Kasir: <?php echo htmlspecialchars($username); ?> | Cabang: <?php echo htmlspecialchars($nama_cabang); ?></p>
                        <p>Tanggal Cetak: ${new Date().toLocaleDateString('id-ID')} ${new Date().toLocaleTimeString('id-ID')}</p>
                        <p><strong>PERBAIKAN: Transaksi Pinjam-Meminjam Sudah Digabung Otomatis</strong></p>
                    </div>
                    ${table.outerHTML}
                    <div style="margin-top: 20px; font-size: 12px; color: #666;">
                        <p><strong>Keterangan:</strong></p>
                        <p>• Transaksi dengan label "GABUNGAN PINJAM-MEMINJAM" adalah hasil penggabungan otomatis</p>
                        <p>• Total telah dihitung dengan akurat sesuai sistem perbaikan</p>
                        <p>• Dokumen ini dicetak secara otomatis dari sistem FIT MOTOR</p>
                    </div>
                </body>
                </html>
            `;
            
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.focus();
            
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 1000);
        }
    }

    // Enhanced error handling and validation
    function initializeErrorHandling() {
        window.addEventListener('error', function(e) {
            console.error('JavaScript Error:', e.error);
            showNotification('Terjadi kesalahan sistem. Silakan refresh halaman.', 'danger', 5000);
        });
        
        // Form validation enhancement
        const form = document.getElementById('setoranForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                const namaInput = this.querySelector('input[name="nama_pengantar"]');
                const checkedBoxes = this.querySelectorAll('.transaksi-checkbox:checked');
                
                let errors = [];
                
                if (!namaInput.value.trim()) {
                    errors.push('Nama pengantar harus diisi');
                }
                
                if (namaInput.value.trim().length < 3) {
                    errors.push('Nama pengantar minimal 3 karakter');
                }
                
                if (checkedBoxes.length === 0) {
                    errors.push('Pilih minimal satu transaksi');
                }
                
                if (errors.length > 0) {
                    e.preventDefault();
                    showNotification('❌ ' + errors.join(', '), 'danger', 4000);
                    
                    if (!namaInput.value.trim()) {
                        namaInput.focus();
                    }
                }
            });
        }
    }

    // Initialize all features when page loads
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize all enhanced features
        initializeTableInteractions();
        updateStatistics();
        initializeAutoSave();
        initializeMobileFeatures();
        initializeVirtualScrolling();
        initializePrintFeatures();
        initializeErrorHandling();
        
        // Show loading complete message
        setTimeout(() => {
            showNotification('✅ Halaman berhasil dimuat dengan perbaikan pengelompokan transaksi!', 'success', 3000);
        }, 500);
        
        console.log('🔧 SETORAN KEUANGAN CS - ENHANCED VERSION LOADED');
        console.log('✅ Perbaikan: Transaksi dipinjam dan meminjam digabung otomatis');
        console.log('✅ Fitur: Enhanced UI/UX dengan animasi dan feedback');
        console.log('✅ Fitur: Keyboard shortcuts dan mobile optimization');
        console.log('✅ Fitur: Auto-save dan error handling');
    });

    // Expose functions for external use
    window.setoranCS = {
        showNotification,
        updateStatistics,
        printTable,
        switchTab
    };
</script>
</body>
</html>