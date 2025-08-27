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
        return '-Rp ' . number_format(abs($angka), 0, ',', '.') . ' (Negatif)';
    }
    return 'Rp ' . number_format($angka, 0, ',', '.') . ($angka == 0 ? " (Belum diisi)" : "");
}

function formatRupiahWithStatus($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.') . ($angka == 0 ? " (Belum diisi)" : "");
}

function getStatusBadge($status) {
    switch($status) {
        case 'completed':
            return '<span class="status-badge status-success">Selesai</span>';
        case 'pending':
            return '<span class="status-badge status-warning">Menunggu Konfirmasi</span>';
        case 'cancelled':
            return '<span class="status-badge status-danger">Ditolak</span>';
        default:
            return '<span class="status-badge status-secondary">Unknown</span>';
    }
}

function getRoleBadge($role) {
    switch($role) {
        case 'kasir':
            return '<span class="role-badge role-kasir">Kasir</span>';
        case 'admin':
            return '<span class="role-badge role-admin">Admin</span>';
        case 'super_admin':
            return '<span class="role-badge role-super-admin">Super Admin</span>';
        default:
            return '<span class="role-badge role-unknown">Unknown</span>';
    }
}

function getJenisTransaksiBadge($jenis) {
    switch($jenis) {
        case 'REGULER':
            return '<span class="jenis-badge jenis-reguler">REGULER</span>';
        case 'DARI_CLOSING':
            return '<span class="jenis-badge jenis-closing">DARI CLOSING</span>';
        case 'GABUNGAN':
            return '<span class="jenis-badge jenis-gabungan">Setoran Rill - Pemasukan</span>';
        default:
            return '<span class="jenis-badge jenis-unknown">Unknown</span>';
    }
}

// Create serah_terima_kasir table if not exists
try {
    $sql_create_table = "
        CREATE TABLE IF NOT EXISTS serah_terima_kasir (
            id INT(11) NOT NULL AUTO_INCREMENT,
            kode_serah_terima VARCHAR(50) NOT NULL,
            kode_karyawan_pemberi VARCHAR(12) NOT NULL,
            kode_karyawan_penerima VARCHAR(12) NOT NULL,
            kode_cabang VARCHAR(9) NOT NULL,
            kode_transaksi_asal VARCHAR(20) NOT NULL,
            tanggal_serah_terima DATETIME NOT NULL,
            total_setoran DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            catatan TEXT,
            status ENUM('pending','completed','cancelled') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_pemberi (kode_karyawan_pemberi),
            INDEX idx_penerima (kode_karyawan_penerima),
            INDEX idx_cabang (kode_cabang),
            INDEX idx_transaksi (kode_transaksi_asal),
            INDEX idx_serah_terima (kode_serah_terima)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql_create_table);
} catch (PDOException $e) {
    // Table might already exist
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Process confirmation of serah terima reception
    if (isset($_POST['terima_serah_terima'])) {
        $kode_serah_terima = trim($_POST['kode_serah_terima'] ?? '');
        $catatan_penerima = trim($_POST['catatan_penerima'] ?? '');
        $nominal_diterima = floatval($_POST['nominal_diterima'] ?? 0);

        if (empty($kode_serah_terima)) {
            echo "<script>alert('Kode serah terima tidak valid.');</script>";
        } else {
            try {
                $pdo->beginTransaction();

                // Verify serah terima is still pending and for this kasir
                $sql_verify = "
                    SELECT st.*, u_pemberi.nama_karyawan as nama_pemberi,
                           SUM(st.total_setoran) as total_nilai_serah
                    FROM serah_terima_kasir st
                    JOIN users u_pemberi ON st.kode_karyawan_pemberi = u_pemberi.kode_karyawan
                    WHERE st.kode_serah_terima = :kode_serah_terima 
                    AND st.kode_karyawan_penerima = :kode_karyawan_penerima 
                    AND st.status = 'pending'
                    GROUP BY st.kode_serah_terima";
                $stmt_verify = $pdo->prepare($sql_verify);
                $stmt_verify->bindParam(':kode_serah_terima', $kode_serah_terima, PDO::PARAM_STR);
                $stmt_verify->bindParam(':kode_karyawan_penerima', $kode_karyawan, PDO::PARAM_STR);
                $stmt_verify->execute();
                $serah_terima_main = $stmt_verify->fetch(PDO::FETCH_ASSOC);

                if (empty($serah_terima_main)) {
                    throw new Exception("Serah terima tidak ditemukan atau sudah diproses.");
                }

                // Check difference
                $selisih = $nominal_diterima - $serah_terima_main['total_nilai_serah'];
                if ($selisih > 0) {
                    throw new Exception("Nominal diterima tidak boleh lebih besar dari nilai serah terima. Silakan gunakan tombol Tolak jika ada masalah.");
                }

                // Get all transactions in this serah terima
                $sql_get_transaksi = "
                    SELECT st.*, u_pemberi.nama_karyawan as nama_pemberi
                    FROM serah_terima_kasir st
                    JOIN users u_pemberi ON st.kode_karyawan_pemberi = u_pemberi.kode_karyawan
                    WHERE st.kode_serah_terima = :kode_serah_terima 
                    AND st.kode_karyawan_penerima = :kode_karyawan_penerima 
                    AND st.status = 'pending'";
                $stmt_get_transaksi = $pdo->prepare($sql_get_transaksi);
                $stmt_get_transaksi->bindParam(':kode_serah_terima', $kode_serah_terima, PDO::PARAM_STR);
                $stmt_get_transaksi->bindParam(':kode_karyawan_penerima', $kode_karyawan, PDO::PARAM_STR);
                $stmt_get_transaksi->execute();
                $serah_terima_data = $stmt_get_transaksi->fetchAll(PDO::FETCH_ASSOC);

                // Create complete notes with difference info if any
                $catatan_lengkap = '';
                
                if (!empty($serah_terima_main['catatan'])) {
                    $catatan_lengkap .= $serah_terima_main['catatan'] . "\n\n";
                }
                
                if (!empty($catatan_penerima)) {
                    $catatan_lengkap .= "Catatan Penerima: " . $catatan_penerima . "\n";
                }
                
                if ($selisih != 0) {
                    $catatan_lengkap .= "Info Selisih:\n";
                    $catatan_lengkap .= "Nominal Serah: Rp " . number_format($serah_terima_main['total_nilai_serah'], 0, ',', '.') . "\n";
                    $catatan_lengkap .= "Nominal Diterima: Rp " . number_format($nominal_diterima, 0, ',', '.') . "\n";
                    $catatan_lengkap .= "Selisih: Rp " . number_format($selisih, 0, ',', '.') . "\n";
                    $catatan_lengkap .= "Status: " . ($selisih > 0 ? "Lebih besar" : "Kurang dari nominal serah");
                }

                $sql_update_st = "
                    UPDATE serah_terima_kasir 
                    SET status = 'completed',
                        catatan = :catatan_lengkap,
                        updated_at = NOW()
                    WHERE kode_serah_terima = :kode_serah_terima 
                    AND kode_karyawan_penerima = :kode_karyawan_penerima";
                $stmt_update_st = $pdo->prepare($sql_update_st);
                $stmt_update_st->bindParam(':catatan_lengkap', $catatan_lengkap, PDO::PARAM_STR);
                $stmt_update_st->bindParam(':kode_serah_terima', $kode_serah_terima, PDO::PARAM_STR);
                $stmt_update_st->bindParam(':kode_karyawan_penerima', $kode_karyawan, PDO::PARAM_STR);
                $stmt_update_st->execute();

                // Update kasir_transactions: transfer ownership to receiver and change status
                foreach ($serah_terima_data as $st_item) {
                    $sql_update_kt = "
                        UPDATE kasir_transactions 
                        SET deposit_status = 'Diserahterimakan',
                            kode_karyawan = :kode_karyawan_penerima
                        WHERE kode_transaksi = :kode_transaksi";
                    $stmt_update_kt = $pdo->prepare($sql_update_kt);
                    $stmt_update_kt->bindParam(':kode_karyawan_penerima', $kode_karyawan, PDO::PARAM_STR);
                    $stmt_update_kt->bindParam(':kode_transaksi', $st_item['kode_transaksi_asal'], PDO::PARAM_STR);
                    $stmt_update_kt->execute();
                }

                $pdo->commit();
                
                $nama_pemberi = $serah_terima_data[0]['nama_pemberi'];
                $jumlah_transaksi = count($serah_terima_data);
                
                $message = "Serah terima berhasil diterima!\\n\\nKode: $kode_serah_terima\\nDari: $nama_pemberi\\nJumlah Transaksi: $jumlah_transaksi";
                if ($selisih != 0) {
                    $message .= "\\nSelisih: Rp " . number_format($selisih, 0, ',', '.');
                }
                $message .= "\\n\\nTransaksi sudah menjadi tanggung jawab Anda dan dapat disetor ke keuangan.";
                
                echo "<script>alert('$message'); window.location.href = 'serah_terima_kasir.php';</script>";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location.href = 'serah_terima_kasir.php';</script>";
            }
        }
    }

    // Process serah terima rejection
    if (isset($_POST['tolak_serah_terima'])) {
        $kode_serah_terima = trim($_POST['kode_serah_terima'] ?? '');
        $alasan_penolakan = trim($_POST['alasan_penolakan'] ?? '');

        if (empty($kode_serah_terima)) {
            echo "<script>alert('Kode serah terima tidak valid.');</script>";
        } elseif (empty($alasan_penolakan)) {
            echo "<script>alert('Alasan penolakan wajib diisi.');</script>";
        } else {
            try {
                $pdo->beginTransaction();

                // Verify serah terima is still pending and for this kasir
                $sql_verify = "
                    SELECT st.*, u_pemberi.nama_karyawan as nama_pemberi
                    FROM serah_terima_kasir st
                    JOIN users u_pemberi ON st.kode_karyawan_pemberi = u_pemberi.kode_karyawan
                    WHERE st.kode_serah_terima = :kode_serah_terima 
                    AND st.kode_karyawan_penerima = :kode_karyawan_penerima 
                    AND st.status = 'pending'";
                $stmt_verify = $pdo->prepare($sql_verify);
                $stmt_verify->bindParam(':kode_serah_terima', $kode_serah_terima, PDO::PARAM_STR);
                $stmt_verify->bindParam(':kode_karyawan_penerima', $kode_karyawan, PDO::PARAM_STR);
                $stmt_verify->execute();
                $serah_terima_data = $stmt_verify->fetchAll(PDO::FETCH_ASSOC);

                if (empty($serah_terima_data)) {
                    throw new Exception("Serah terima tidak ditemukan atau sudah diproses.");
                }

                // Update serah terima status to cancelled with simplified notes
                $catatan_lengkap = '';
                
                if (!empty($serah_terima_data[0]['catatan'])) {
                    $catatan_lengkap .= $serah_terima_data[0]['catatan'] . "\n\n";
                }
                
                $catatan_lengkap .= "Alasan Penolakan: " . $alasan_penolakan;

                $sql_update_st = "
                    UPDATE serah_terima_kasir 
                    SET status = 'cancelled',
                        catatan = :catatan_lengkap,
                        updated_at = NOW()
                    WHERE kode_serah_terima = :kode_serah_terima 
                    AND kode_karyawan_penerima = :kode_karyawan_penerima";
                $stmt_update_st = $pdo->prepare($sql_update_st);
                $stmt_update_st->bindParam(':catatan_lengkap', $catatan_lengkap, PDO::PARAM_STR);
                $stmt_update_st->bindParam(':kode_serah_terima', $kode_serah_terima, PDO::PARAM_STR);
                $stmt_update_st->bindParam(':kode_karyawan_penerima', $kode_karyawan, PDO::PARAM_STR);
                $stmt_update_st->execute();

                // Return transaction status to original
                foreach ($serah_terima_data as $st_item) {
                    $sql_update_kt = "
                        UPDATE kasir_transactions 
                        SET deposit_status = 'Belum Disetor'
                        WHERE kode_transaksi = :kode_transaksi";
                    $stmt_update_kt = $pdo->prepare($sql_update_kt);
                    $stmt_update_kt->bindParam(':kode_transaksi', $st_item['kode_transaksi_asal'], PDO::PARAM_STR);
                    $stmt_update_kt->execute();
                }

                $pdo->commit();
                
                $nama_pemberi = $serah_terima_data[0]['nama_pemberi'];
                
                echo "<script>alert('Serah terima berhasil ditolak!\\n\\nKode: $kode_serah_terima\\nDari: $nama_pemberi\\n\\nTransaksi dikembalikan ke kasir pemberi.'); window.location.href = 'serah_terima_kasir.php';</script>";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location.href = 'serah_terima_kasir.php';</script>";
            }
        }
    }

    // Process serah terima submission - PERBAIKAN: Handle gabungan transactions
    if (isset($_POST['submit_serah_terima'])) {
        $selected_transaksi = $_POST['kode_transaksi'] ?? [];
        $kode_karyawan_penerima = trim($_POST['kode_karyawan_penerima'] ?? '');
        $catatan_serah_terima = trim($_POST['catatan_serah_terima'] ?? '');

        if (empty($selected_transaksi)) {
            echo "<script>alert('Pilih setidaknya satu transaksi untuk diserahterimakan.');</script>";
        } elseif (empty($kode_karyawan_penerima)) {
            echo "<script>alert('Pilih kasir penerima.');</script>";
        } else {
            try {
                $pdo->beginTransaction();

                // Generate unique kode_serah_terima
                $kode_serah_terima = 'ST-' . date('Ymd') . '-' . substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6);

                $total_setoran = 0;
                $transaksi_to_insert = [];

                // PERBAIKAN: Handle gabungan transactions
                foreach ($selected_transaksi as $kode_transaksi) {
                    if (strpos($kode_transaksi, 'GABUNGAN_') === 0) {
                        // This is a combined transaction, get the base transaction code
                        $base_code = str_replace('GABUNGAN_', '', $kode_transaksi);
                        
                        // Get regular transaction
                        $sql_regular = "SELECT setoran_real FROM kasir_transactions WHERE kode_transaksi = :kode_transaksi AND status = 'end proses'";
                        $stmt_regular = $pdo->prepare($sql_regular);
                        $stmt_regular->bindParam(':kode_transaksi', $base_code, PDO::PARAM_STR);
                        $stmt_regular->execute();
                        $regular_setoran = $stmt_regular->fetchColumn() ?: 0;
                        
                        // Get closing transaction
                        $sql_closing = "SELECT jumlah FROM pemasukan_kasir WHERE nomor_transaksi_closing = :kode_transaksi AND kode_karyawan = :kode_karyawan";
                        $stmt_closing = $pdo->prepare($sql_closing);
                        $stmt_closing->bindParam(':kode_transaksi', $base_code, PDO::PARAM_STR);
                        $stmt_closing->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
                        $stmt_closing->execute();
                        $closing_amount = $stmt_closing->fetchColumn() ?: 0;
                        
                        // PERBAIKAN: MINUS closing amount karena closing adalah pengambilan
                        $total_amount = $regular_setoran - $closing_amount;
                        $transaksi_to_insert[] = [
                            'kode_transaksi' => $base_code,
                            'setoran_real' => $total_amount
                        ];
                        $total_setoran += $total_amount;
                        
                    } else {
                        // Individual transaction
                        $sql_get_setoran = "
                            SELECT 
                                kt.setoran_real,
                                COALESCE((
                                    SELECT pk.jumlah FROM pemasukan_kasir pk 
                                    WHERE pk.nomor_transaksi_closing = kt.kode_transaksi 
                                    AND pk.kode_karyawan = :kode_karyawan
                                ), 0) as closing_amount
                            FROM kasir_transactions kt 
                            WHERE kt.kode_transaksi = :kode_transaksi";
                        $stmt_get_setoran = $pdo->prepare($sql_get_setoran);
                        $stmt_get_setoran->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
                        $stmt_get_setoran->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
                        $stmt_get_setoran->execute();
                        $setoran_data = $stmt_get_setoran->fetch(PDO::FETCH_ASSOC);
                        
                        $setoran_transaksi = $setoran_data['setoran_real'] ?: 0;
                        $closing_amount = $setoran_data['closing_amount'] ?: 0;
                        // PERBAIKAN: MINUS closing amount karena closing adalah pengambilan
                        $total_amount = $setoran_transaksi - $closing_amount;
                        
                        $transaksi_to_insert[] = [
                            'kode_transaksi' => $kode_transaksi,
                            'setoran_real' => $total_amount
                        ];
                        $total_setoran += $total_amount;
                    }
                }

                // Insert into serah_terima_kasir table for each transaction with 'pending' status
                $sql_serah_terima = "
                    INSERT INTO serah_terima_kasir 
                    (kode_serah_terima, kode_karyawan_pemberi, kode_karyawan_penerima, kode_cabang, 
                     kode_transaksi_asal, tanggal_serah_terima, total_setoran, catatan, status, created_at)
                    VALUES (:kode_serah_terima, :kode_karyawan_pemberi, :kode_karyawan_penerima, :kode_cabang,
                            :kode_transaksi_asal, :tanggal_serah_terima, :total_setoran, :catatan, 'pending', NOW())";
                
                $stmt_serah_terima = $pdo->prepare($sql_serah_terima);
                
                foreach ($transaksi_to_insert as $transaksi) {
                    $stmt_serah_terima->bindParam(':kode_serah_terima', $kode_serah_terima, PDO::PARAM_STR);
                    $stmt_serah_terima->bindParam(':kode_karyawan_pemberi', $kode_karyawan, PDO::PARAM_STR);
                    $stmt_serah_terima->bindParam(':kode_karyawan_penerima', $kode_karyawan_penerima, PDO::PARAM_STR);
                    $stmt_serah_terima->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
                    $stmt_serah_terima->bindParam(':kode_transaksi_asal', $transaksi['kode_transaksi'], PDO::PARAM_STR);
                    $tanggal_serah_terima = date('Y-m-d H:i:s');
                    $stmt_serah_terima->bindParam(':tanggal_serah_terima', $tanggal_serah_terima, PDO::PARAM_STR);
                    $stmt_serah_terima->bindParam(':total_setoran', $transaksi['setoran_real'], PDO::PARAM_STR);
                    $stmt_serah_terima->bindParam(':catatan', $catatan_serah_terima, PDO::PARAM_STR);
                    $stmt_serah_terima->execute();
                }

                // Update deposit_status in kasir_transactions to 'Pending Serah Terima'
                foreach ($transaksi_to_insert as $transaksi) {
                    $sql_update = "
                        UPDATE kasir_transactions 
                        SET deposit_status = 'Pending Serah Terima'
                        WHERE kode_transaksi = :kode_transaksi 
                        AND kode_karyawan = :kode_karyawan_pemberi";
                    $stmt_update = $pdo->prepare($sql_update);
                    $stmt_update->bindParam(':kode_transaksi', $transaksi['kode_transaksi'], PDO::PARAM_STR);
                    $stmt_update->bindParam(':kode_karyawan_pemberi', $kode_karyawan, PDO::PARAM_STR);
                    $stmt_update->execute();
                }

                $pdo->commit();
                
                // Get receiver name
                $sql_nama_penerima = "SELECT nama_karyawan FROM users WHERE kode_karyawan = :kode_karyawan";
                $stmt_nama_penerima = $pdo->prepare($sql_nama_penerima);
                $stmt_nama_penerima->bindParam(':kode_karyawan', $kode_karyawan_penerima, PDO::PARAM_STR);
                $stmt_nama_penerima->execute();
                $nama_penerima = $stmt_nama_penerima->fetchColumn();
                
                $message = "Permintaan serah terima berhasil dikirim!\\n\\nKode: $kode_serah_terima\\nPenerima: $nama_penerima\\nJumlah Item: " . count($transaksi_to_insert) . "\\nTotal: Rp " . number_format($total_setoran, 0, ',', '.') . "\\n\\nStatus: Menunggu konfirmasi dari penerima";
                if ($total_setoran < 0) {
                    $message .= "\\n\\nCATATAN: Total serah terima negatif karena pengambilan closing lebih besar dari setoran reguler.";
                }
                
                echo "<script>alert('$message'); window.location.href = 'serah_terima_kasir.php';</script>";
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location.href = 'serah_terima_kasir.php';</script>";
            }
        }
    }
}

// PERBAIKAN: Query transaksi yang bisa diserahterimakan dengan logika penggabungan
$sql_transaksi_available = "
    SELECT DISTINCT
        kt.kode_transaksi,
        kt.tanggal_transaksi,
        kt.setoran_real,
        kt.deposit_status,
        kt.status,
        -- Cek apakah ada transaksi closing terkait
        CASE 
            WHEN EXISTS (
                SELECT 1 FROM pemasukan_kasir pk 
                WHERE pk.nomor_transaksi_closing = kt.kode_transaksi 
                AND pk.kode_karyawan = :kode_karyawan_check
            ) THEN 'HAS_CLOSING'
            ELSE 'REGULER'
        END as has_closing_transaction,
        
        -- Ambil jumlah closing jika ada
        COALESCE((
            SELECT pk.jumlah FROM pemasukan_kasir pk 
            WHERE pk.nomor_transaksi_closing = kt.kode_transaksi 
            AND pk.kode_karyawan = :kode_karyawan_closing
            LIMIT 1
        ), 0) as closing_amount,
        
        -- PERBAIKAN: Total gabungan MINUS closing (karena closing adalah pengambilan)
        kt.setoran_real - COALESCE((
            SELECT pk.jumlah FROM pemasukan_kasir pk 
            WHERE pk.nomor_transaksi_closing = kt.kode_transaksi 
            AND pk.kode_karyawan = :kode_karyawan_total
            LIMIT 1
        ), 0) as total_gabungan
        
    FROM kasir_transactions kt
    WHERE kt.kode_karyawan = :kode_karyawan
    AND kt.status = 'end proses'
    AND (kt.deposit_status IS NULL OR kt.deposit_status = '' OR kt.deposit_status = 'Belum Disetor')
    AND kt.kode_transaksi NOT IN (
        SELECT DISTINCT kode_transaksi_asal 
        FROM serah_terima_kasir 
        WHERE kode_transaksi_asal IS NOT NULL
        AND status IN ('pending', 'completed')
    )
    ORDER BY kt.tanggal_transaksi DESC";

$stmt_available = $pdo->prepare($sql_transaksi_available);
$stmt_available->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_available->bindParam(':kode_karyawan_check', $kode_karyawan, PDO::PARAM_STR);
$stmt_available->bindParam(':kode_karyawan_closing', $kode_karyawan, PDO::PARAM_STR);
$stmt_available->bindParam(':kode_karyawan_total', $kode_karyawan, PDO::PARAM_STR);
$stmt_available->execute();
$transaksi_raw = $stmt_available->fetchAll(PDO::FETCH_ASSOC);

// Proses data untuk menggabungkan transaksi yang memiliki reguler dan closing
$transaksi_available = [];
foreach ($transaksi_raw as $trans) {
    if ($trans['has_closing_transaction'] === 'HAS_CLOSING' && $trans['closing_amount'] != 0) {
        // Gabungkan transaksi reguler dan closing menjadi satu item
        $gabungan_item = [
            'kode_transaksi' => 'GABUNGAN_' . $trans['kode_transaksi'],
            'kode_transaksi_asli' => $trans['kode_transaksi'],
            'tanggal_transaksi' => $trans['tanggal_transaksi'],
            'setoran_real' => $trans['total_gabungan'],
            'deposit_status' => $trans['deposit_status'],
            'status' => $trans['status'],
            'jenis_transaksi' => 'GABUNGAN',
            'detail_reguler' => $trans['setoran_real'],
            'detail_closing' => $trans['closing_amount'],
            'is_combined' => true
        ];
        $transaksi_available[] = $gabungan_item;
    } else {
        // Transaksi reguler biasa
        $regular_item = [
            'kode_transaksi' => $trans['kode_transaksi'],
            'kode_transaksi_asli' => $trans['kode_transaksi'],
            'tanggal_transaksi' => $trans['tanggal_transaksi'],
            'setoran_real' => $trans['setoran_real'],
            'deposit_status' => $trans['deposit_status'],
            'status' => $trans['status'],
            'jenis_transaksi' => 'REGULER',
            'is_combined' => false
        ];
        $transaksi_available[] = $regular_item;
    }
}

// Get list of all kasir, admin, and super admin from all branches
$sql_kasir_cabang = "
    SELECT kode_karyawan, nama_karyawan, role, kode_cabang, nama_cabang
    FROM users 
    WHERE kode_karyawan != :kode_karyawan_current
    AND role IN ('kasir', 'admin', 'super_admin')
    AND (status = 1 OR status IS NULL)
    ORDER BY 
        nama_cabang,
        CASE 
            WHEN role = 'kasir' THEN 1 
            WHEN role = 'admin' THEN 2 
            WHEN role = 'super_admin' THEN 3 
        END,
        nama_karyawan";

$stmt_kasir = $pdo->prepare($sql_kasir_cabang);
$stmt_kasir->bindParam(':kode_karyawan_current', $kode_karyawan, PDO::PARAM_STR);
$stmt_kasir->execute();
$kasir_list = $stmt_kasir->fetchAll(PDO::FETCH_ASSOC);

// Get incoming serah terima requests (as receiver) - pending status
$sql_permintaan_masuk = "
    SELECT 
        st.kode_serah_terima,
        st.tanggal_serah_terima,
        st.catatan,
        st.status,
        u_pemberi.nama_karyawan as nama_pemberi,
        GROUP_CONCAT(st.kode_transaksi_asal SEPARATOR ', ') as daftar_transaksi,
        COUNT(st.kode_transaksi_asal) as jumlah_transaksi,
        SUM(st.total_setoran) as total_nilai
    FROM serah_terima_kasir st
    JOIN users u_pemberi ON st.kode_karyawan_pemberi = u_pemberi.kode_karyawan
    WHERE st.kode_karyawan_penerima = :kode_karyawan
    AND st.status = 'pending'
    GROUP BY st.kode_serah_terima
    ORDER BY st.tanggal_serah_terima DESC";

$stmt_permintaan_masuk = $pdo->prepare($sql_permintaan_masuk);
$stmt_permintaan_masuk->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_permintaan_masuk->execute();
$permintaan_masuk = $stmt_permintaan_masuk->fetchAll(PDO::FETCH_ASSOC);

// Get serah terima history that I made (as giver)
$sql_riwayat_keluar = "
    SELECT 
        st1.kode_serah_terima,
        st1.tanggal_serah_terima,
        st1.catatan,
        st1.status,
        u_penerima.nama_karyawan as nama_penerima,
        GROUP_CONCAT(st2.kode_transaksi_asal SEPARATOR ', ') as daftar_transaksi,
        COUNT(st2.kode_transaksi_asal) as jumlah_transaksi,
        SUM(st2.total_setoran) as total_nilai
    FROM serah_terima_kasir st1
    JOIN users u_penerima ON st1.kode_karyawan_penerima = u_penerima.kode_karyawan
    JOIN serah_terima_kasir st2 ON st1.kode_serah_terima = st2.kode_serah_terima
    WHERE st1.kode_karyawan_pemberi = :kode_karyawan
    AND st1.id = (
        SELECT MAX(id) 
        FROM serah_terima_kasir 
        WHERE kode_serah_terima = st1.kode_serah_terima
    )
    GROUP BY st1.kode_serah_terima
    ORDER BY st1.tanggal_serah_terima DESC
    LIMIT 50";

$stmt_riwayat_keluar = $pdo->prepare($sql_riwayat_keluar);
$stmt_riwayat_keluar->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_riwayat_keluar->execute();
$riwayat_keluar = $stmt_riwayat_keluar->fetchAll(PDO::FETCH_ASSOC);

// Get serah terima history that I received (as receiver) - including rejected
$sql_riwayat_masuk = "
    SELECT 
        st1.kode_serah_terima,
        st1.tanggal_serah_terima,
        st1.catatan,
        st1.status,
        u_pemberi.nama_karyawan as nama_pemberi,
        GROUP_CONCAT(st2.kode_transaksi_asal SEPARATOR ', ') as daftar_transaksi,
        COUNT(st2.kode_transaksi_asal) as jumlah_transaksi,
        SUM(st2.total_setoran) as total_nilai
    FROM serah_terima_kasir st1
    JOIN users u_pemberi ON st1.kode_karyawan_pemberi = u_pemberi.kode_karyawan
    JOIN serah_terima_kasir st2 ON st1.kode_serah_terima = st2.kode_serah_terima
    WHERE st1.kode_karyawan_penerima = :kode_karyawan
    AND st1.status IN ('completed', 'cancelled')
    AND st1.id = (
        SELECT MAX(id) 
        FROM serah_terima_kasir 
        WHERE kode_serah_terima = st1.kode_serah_terima
    )
    GROUP BY st1.kode_serah_terima
    ORDER BY st1.tanggal_serah_terima DESC
    LIMIT 50";

$stmt_riwayat_masuk = $pdo->prepare($sql_riwayat_masuk);
$stmt_riwayat_masuk->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_riwayat_masuk->execute();
$riwayat_masuk = $stmt_riwayat_masuk->fetchAll(PDO::FETCH_ASSOC);

// Calculate total available safely
$total_available = array_sum(array_column($transaksi_available, 'setoran_real'));
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Serah Terima Antar Kasir</title>
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
        .step-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .step-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            background: var(--background-light);
            border-radius: 12px 12px 0 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .step-number {
            width: 32px;
            height: 32px;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
        }
        .step-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
            margin: 0;
        }
        .step-content {
            padding: 24px;
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
        .form-control:disabled {
            background-color: #f1f3f4;
            color: #5f6368;
            cursor: not-allowed;
        }
        .search-dropdown {
            position: relative;
        }
        .search-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        .dropdown-list {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }
        .dropdown-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s ease;
        }
        .dropdown-item:hover {
            background-color: var(--background-light);
        }
        .dropdown-item:last-child {
            border-bottom: none;
        }
        .dropdown-item.selected {
            background-color: var(--primary-color);
            color: white;
        }
        .user-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .user-name {
            font-weight: 500;
        }
        .user-details {
            font-size: 12px;
            color: var(--text-muted);
        }
        .user-role {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .role-kasir-small { background: rgba(0,123,255,0.1); color: var(--primary-color); }
        .role-admin-small { background: rgba(40,167,69,0.1); color: var(--success-color); }
        .role-super-admin-small { background: rgba(220,53,69,0.1); color: var(--danger-color); }
        .cabang-info {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        
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
        .jenis-gabungan { 
            background: linear-gradient(45deg, rgba(40,167,69,0.1), rgba(255,193,7,0.1));
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }
        .jenis-unknown { 
            background: rgba(108,117,125,0.1); 
            color: var(--secondary-color); 
        }
        
        .gabungan-details {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
            background: rgba(0,123,255,0.1);
            padding: 4px 8px;
            border-radius: 6px;
            border-left: 3px solid var(--primary-color);
        }
        
        .nominal-input {
            width: 150px;
            padding: 8px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 14px;
            text-align: right;
        }
        .selisih-positive {
            color: var(--danger-color);
            font-weight: 600;
        }
        .selisih-zero {
            color: var(--success-color);
            font-weight: 600;
        }
        .selisih-negative {
            color: var(--danger-color);
            font-weight: 600;
        }
        .amount-negative {
            color: var(--danger-color);
            font-weight: 600;
        }
        .amount-positive {
            color: var(--success-color);
            font-weight: 600;
        }
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
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
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
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        .btn-primary:hover:not(:disabled) {
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
        
        .role-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .role-kasir { background: rgba(0,123,255,0.1); color: var(--primary-color); }
        .role-admin { background: rgba(40,167,69,0.1); color: var(--success-color); }
        .role-super-admin { background: rgba(220,53,69,0.1); color: var(--danger-color); }
        .role-unknown { background: rgba(108,117,125,0.1); color: var(--secondary-color); }
        
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
        .form-check-input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .required {
            color: var(--danger-color);
        }
        .small-text {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
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
    <a href="serah_terima_kasir.php" class="active"><i class="fas fa-handshake"></i> Serah Terima Kasir</a>
    <a href="setoran_keuangan_cs.php"><i class="fas fa-money-bill"></i> Setoran Keuangan CS</a>
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

    <h1 style="margin-bottom: 24px; color: var(--text-dark);"><i class="fas fa-handshake"></i> Serah Terima Antar Kasir</h1>

    <div class="summary-card">
        <div class="row">
            <div>
                <p><strong>Kasir:</strong> <?php echo htmlspecialchars($username); ?></p>
                <p><strong>Tanggal:</strong> <?php echo date('d/m/Y'); ?></p>
            </div>
            <div>
                <p><strong>Cabang:</strong> <?php echo htmlspecialchars($nama_cabang); ?></p>
                <p><strong>Kode Cabang:</strong> <?php echo htmlspecialchars($kode_cabang); ?></p>
            </div>
            <div>
                <p><strong>Item Dapat Diserahkan:</strong> <?php echo count($transaksi_available); ?> item</p>
                <p><strong>Total Nilai:</strong> <span class="<?php echo $total_available < 0 ? 'amount-negative' : 'amount-positive'; ?>"><?php echo formatRupiah($total_available); ?></span></p>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="nav-tabs">
        <div class="nav-tab active" onclick="switchTab('serah-terima', this)">
            Serah Terima (<?php echo count($transaksi_available); ?>)
        </div>
        <div class="nav-tab" onclick="switchTab('permintaan-masuk', this)">
            Permintaan Masuk (<?php echo count($permintaan_masuk); ?>)
        </div>
        <div class="nav-tab" onclick="switchTab('riwayat-keluar', this)">
            Riwayat Diserahkan (<?php echo count($riwayat_keluar); ?>)
        </div>
        <div class="nav-tab" onclick="switchTab('riwayat-masuk', this)">
            Riwayat Diterima (<?php echo count($riwayat_masuk); ?>)
        </div>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Tab Serah Terima -->
        <div class="tab-pane active" id="serah-terima">
            <?php if (!empty($transaksi_available)): ?>
            <form action="" method="POST" id="serahTerimaForm">
                <!-- Step 1: Transaksi yang Dapat Diserahterimakan -->
                <div class="step-container">
                    <div class="step-header">
                        <div class="step-number">1</div>
                        <h3 class="step-title">Pilih Transaksi yang Diserahterimakan</h3>
                    </div>
                    <div class="step-content">
                        <!-- PERBAIKAN: Alert untuk logika penggabungan -->
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Logika Penggabungan Transaksi (DIPERBAIKI):</strong>
                            <ul style="margin: 8px 0 0 20px;">
                                <li>Jika ada transaksi reguler dan pemasukan closing dengan kode transaksi yang sama, keduanya akan digabung menjadi satu item serah terima</li>
                                <li><strong>PERBAIKAN:</strong> Pemasukan closing akan <strong>DIKURANGI</strong> dari setoran reguler (bukan ditambahkan) karena closing adalah pengambilan uang</li>
                                <li>Formula: <strong>Total Gabungan = Setoran Reguler - Pemasukan Closing</strong></li>
                                <li>Transaksi yang hanya reguler atau hanya closing akan ditampilkan terpisah</li>
                                <li>Total setoran dapat menjadi negatif jika pengambilan closing lebih besar dari setoran reguler</li>
                                <li>Total serah terima otomatis menghitung gabungan dari reguler - pemasukan closing</li>
                                <li>Kasir penerima akan menerima nilai gabungan dan dapat melakukan setoran ke keuangan</li>
                            </ul>
                        </div>
                        
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th width="50">
                                            <input type="checkbox" id="selectAllSerahTerima" class="form-check-input">
                                        </th>
                                        <th>Kode Transaksi</th>
                                        <th>Tanggal</th>
                                        <th>Jenis</th>
                                        <th>Jumlah Setoran</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transaksi_available as $trans): ?>
                                        <tr>
                                            <td>
                                                <input 
                                                    type="checkbox" 
                                                    name="kode_transaksi[]" 
                                                    value="<?php echo htmlspecialchars($trans['kode_transaksi']); ?>" 
                                                    class="form-check-input transaksi-checkbox">
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($trans['kode_transaksi_asli']); ?></code>
                                                <?php if ($trans['is_combined']): ?>
                                                    <div class="gabungan-details">
                                                        <i class="fas fa-layer-group"></i>
                                                        Gabungan: Reguler (<?php echo formatRupiah($trans['detail_reguler']); ?>) - 
                                                        Pemasukan (<?php echo formatRupiah($trans['detail_closing']); ?>)
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($trans['tanggal_transaksi'])); ?></td>
                                            <td><?php echo getJenisTransaksiBadge($trans['jenis_transaksi']); ?></td>
                                            <td class="<?php echo $trans['setoran_real'] < 0 ? 'amount-negative' : 'amount-positive'; ?>"><?php echo formatRupiah($trans['setoran_real']); ?></td>
                                            <td>
                                                <span class="status-badge status-success">End Proses</span>
                                                <?php if ($trans['is_combined']): ?>
                                                    <div style="font-size: 10px; margin-top: 4px;">
                                                        <span class="status-badge status-info" style="font-size: 9px;">GABUNGAN</span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4" style="text-align: right;"><strong>Total:</strong></td>
                                        <td class="<?php echo $total_available < 0 ? 'amount-negative' : 'amount-positive'; ?>"><strong><?php echo formatRupiah($total_available); ?></strong></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Kasir Penerima -->
                <div class="step-container">
                    <div class="step-header">
                        <div class="step-number">2</div>
                        <h3 class="step-title">Pilih Kasir Penerima</h3>
                    </div>
                    <div class="step-content">
                        <div class="form-group">
                            <label for="kode_karyawan_penerima" class="form-label">Kasir/Admin/Super Admin Penerima <span class="required">*</span></label>
                            
                            <!-- Hidden input for actual value -->
                            <input type="hidden" name="kode_karyawan_penerima" id="kode_karyawan_penerima" required>
                            
                            <!-- Search dropdown -->
                            <div class="search-dropdown">
                                <input 
                                    type="text" 
                                    id="searchPenerima" 
                                    class="search-input" 
                                    placeholder="Ketik untuk mencari kasir/admin/super admin..." 
                                    autocomplete="off">
                                <div class="dropdown-list" id="dropdownList">
                                    <?php foreach ($kasir_list as $kasir): ?>
                                        <div class="dropdown-item" 
                                             data-value="<?php echo htmlspecialchars($kasir['kode_karyawan']); ?>"
                                             data-name="<?php echo htmlspecialchars($kasir['nama_karyawan']); ?>"
                                             data-role="<?php echo htmlspecialchars($kasir['role']); ?>"
                                             data-cabang="<?php echo htmlspecialchars($kasir['nama_cabang'] ?? ''); ?>">
                                            <div class="user-info">
                                                <div>
                                                    <div class="user-name"><?php echo htmlspecialchars($kasir['nama_karyawan']); ?></div>
                                                    <div class="user-details"><?php echo htmlspecialchars($kasir['kode_karyawan']); ?></div>
                                                    <?php if (!empty($kasir['nama_cabang'])): ?>
                                                        <div class="cabang-info"><?php echo htmlspecialchars($kasir['nama_cabang']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="user-role role-<?php echo str_replace('_', '-', $kasir['role']); ?>-small">
                                                    <?php echo ucfirst(str_replace('_', ' ', $kasir['role'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($kasir_list)): ?>
                                        <div class="dropdown-item" style="color: var(--text-muted); cursor: default;">
                                            Tidak ada kasir/admin/super admin lain ditemukan
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="small-text">
                                Pilih kasir, admin, atau super admin dari semua cabang yang akan menerima transaksi
                                <br><strong>Total tersedia: <?php echo count($kasir_list); ?> orang</strong>
                                <?php if (!empty($kasir_list)): ?>
                                    <br><em>Cabang tersedia: 
                                    <?php 
                                    $cabang_list = array_filter(array_unique(array_column($kasir_list, 'nama_cabang')));
                                    echo implode(', ', array_slice($cabang_list, 0, 3));
                                    if (count($cabang_list) > 3) echo ' dan ' . (count($cabang_list) - 3) . ' cabang lainnya';
                                    ?>
                                    </em>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Catatan Serah Terima -->
                <div class="step-container">
                    <div class="step-header">
                        <div class="step-number">3</div>
                        <h3 class="step-title">Catatan Serah Terima</h3>
                    </div>
                    <div class="step-content">
                        <div class="form-group">
                            <label for="catatan_serah_terima" class="form-label">Catatan Serah Terima</label>
                            <textarea name="catatan_serah_terima" id="catatan_serah_terima" class="form-control" rows="3" placeholder="Catatan tambahan untuk serah terima (opsional)"></textarea>
                            <div class="small-text">Tuliskan catatan atau keterangan tambahan jika diperlukan</div>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Konfirmasi -->
                <div class="step-container">
                    <div class="step-header">
                        <div class="step-number">4</div>
                        <h3 class="step-title">Konfirmasi dan Kirim</h3>
                    </div>
                    <div class="step-content">
                        <div class="btn-group">
                            <button type="submit" name="submit_serah_terima" id="btnKirimPermintaan" class="btn btn-primary" disabled>
                                <i class="fas fa-paper-plane"></i> Kirim Permintaan
                            </button>
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                        </div>
                        <div class="small-text">Pastikan kasir penerima dan transaksi sudah dipilih sebelum mengirim permintaan</div>
                    </div>
                </div>
            </form>
            <?php elseif (empty($kasir_list)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> Tidak ada kasir, admin, atau super admin lain yang dapat menerima serah terima.
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Tidak ada transaksi yang dapat diserahterimakan saat ini. Pastikan transaksi sudah dalam status "End Proses".
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab Permintaan Masuk -->
        <div class="tab-pane" id="permintaan-masuk">
            <h5 style="margin-bottom: 16px; color: var(--text-dark);">Permintaan Serah Terima yang Masuk</h5>
            <?php if (!empty($permintaan_masuk)): ?>
            <?php foreach ($permintaan_masuk as $permintaan): ?>
            <div class="summary-card" style="margin-bottom: 20px; border-left: 4px solid var(--warning-color);">
                <div class="row" style="margin-bottom: 15px;">
                    <div>
                        <p><strong>Kode Serah Terima:</strong> <code><?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?></code></p>
                        <p><strong>Dari Kasir:</strong> <?php echo htmlspecialchars($permintaan['nama_pemberi']); ?></p>
                    </div>
                    <div>
                        <p><strong>Tanggal Permintaan:</strong> <?php echo date('d/m/Y H:i', strtotime($permintaan['tanggal_serah_terima'])); ?></p>
                        <p><strong>Jumlah Item:</strong> <?php echo $permintaan['jumlah_transaksi']; ?> item</p>
                    </div>
                    <div>
                        <p><strong>Total Nilai Serah:</strong> <?php echo formatRupiah($permintaan['total_nilai']); ?></p>
                        <p><strong>Status:</strong> <?php echo getStatusBadge($permintaan['status']); ?></p>
                    </div>
                </div>
                
                <?php if (!empty($permintaan['catatan'])): ?>
                <div style="margin-bottom: 15px;">
                    <p><strong>Catatan:</strong></p>
                    <div style="background: var(--background-light); padding: 10px; border-radius: 8px; font-style: italic;">
                        <?php echo nl2br(htmlspecialchars($permintaan['catatan'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div style="margin-bottom: 15px;">
                    <p><strong>Daftar Transaksi:</strong></p>
                    <div style="background: var(--background-light); padding: 10px; border-radius: 8px;">
                        <code><?php echo htmlspecialchars($permintaan['daftar_transaksi']); ?></code>
                    </div>
                </div>
                
                <!-- Form Terima/Tolak dengan Nominal Diterima -->
                <div class="table-container" style="margin-bottom: 15px;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Nominal Serah</th>
                                <th>Nominal Diterima</th>
                                <th>Selisih</th>
                                <th>Status Tombol</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><?php echo formatRupiah($permintaan['total_nilai']); ?></td>
                                <td>
                                    <input 
                                        type="number" 
                                        id="nominal_diterima_<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>" 
                                        class="nominal-input" 
                                        value="<?php echo $permintaan['total_nilai']; ?>" 
                                        min="0" 
                                        step="1"
                                        onchange="updateSelisih('<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>', <?php echo $permintaan['total_nilai']; ?>)"
                                        oninput="updateSelisih('<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>', <?php echo $permintaan['total_nilai']; ?>)">
                                </td>
                                <td>
                                    <span id="selisih_<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>" class="selisih-zero">
                                        Rp 0
                                    </span>
                                </td>
                                <td>
                                    <span id="status_tombol_<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>" class="status-badge status-success">
                                        Terima & Tolak Aktif
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="btn-group">
                    <!-- Form Terima -->
                    <form action="" method="POST" style="display: inline-block; margin-right: 10px;" id="form_terima_<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>">
                        <input type="hidden" name="kode_serah_terima" value="<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>">
                        <input type="hidden" name="nominal_diterima" id="hidden_nominal_<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>" value="<?php echo $permintaan['total_nilai']; ?>">
                        <input type="text" 
                               name="catatan_penerima" 
                               id="catatan_penerima_<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>"
                               placeholder="Catatan penerimaan (opsional)" 
                               style="margin-right: 10px; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; width: 200px;">
                        <button type="submit" 
                                name="terima_serah_terima" 
                                id="btn_terima_<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>"
                                class="btn btn-primary" 
                                onclick="return confirmTerima('<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>')">
                            <i class="fas fa-check"></i> Terima
                        </button>
                    </form>
                    
                    <!-- Form Tolak -->
                    <form action="" method="POST" style="display: inline-block;">
                        <input type="hidden" name="kode_serah_terima" value="<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>">
                        <input type="text" 
                               name="alasan_penolakan" 
                               id="alasan_penolakan_<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>"
                               placeholder="Alasan penolakan (wajib)" 
                               style="margin-right: 10px; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; width: 200px;" 
                               required>
                        <button type="submit" 
                                name="tolak_serah_terima" 
                                id="btn_tolak_<?php echo htmlspecialchars($permintaan['kode_serah_terima']); ?>"
                                class="btn btn-danger" 
                                onclick="return confirm('Yakin ingin menolak serah terima ini?')">
                            <i class="fas fa-times"></i> Tolak
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Tidak ada permintaan serah terima yang masuk.
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab Riwayat Keluar -->
        <div class="tab-pane" id="riwayat-keluar">
            <h5 style="margin-bottom: 16px; color: var(--text-dark);">Riwayat Transaksi yang Saya Serahkan</h5>
            <?php if (!empty($riwayat_keluar)): ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kode Serah Terima</th>
                            <th>Tanggal</th>
                            <th>Penerima</th>
                            <th>Jumlah Item</th>
                            <th>Total Nilai</th>
                            <th>Status</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($riwayat_keluar as $riwayat): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($riwayat['kode_serah_terima']); ?></code></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($riwayat['tanggal_serah_terima'])); ?></td>
                                <td><?php echo htmlspecialchars($riwayat['nama_penerima']); ?></td>
                                <td><?php echo $riwayat['jumlah_transaksi']; ?> item</td>
                                <td><?php echo formatRupiah($riwayat['total_nilai']); ?></td>
                                <td><?php echo getStatusBadge($riwayat['status']); ?></td>
                                <td>
                                    <?php if (!empty($riwayat['catatan'])): ?>
                                        <button type="button" 
                                                class="btn btn-secondary" 
                                                style="padding: 6px 12px; font-size: 12px;" 
                                                data-kode="<?php echo htmlspecialchars($riwayat['kode_serah_terima']); ?>"
                                                data-catatan="<?php echo htmlspecialchars($riwayat['catatan']); ?>"
                                                data-status="<?php echo htmlspecialchars($riwayat['status']); ?>"
                                                data-transaksi="<?php echo htmlspecialchars($riwayat['daftar_transaksi']); ?>"
                                                data-jumlah="<?php echo htmlspecialchars($riwayat['jumlah_transaksi']); ?>"
                                                onclick="showDetailFromData(this)">
                                            <i class="fas fa-eye"></i> Lihat
                                        </button>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 12px;">Tidak ada catatan</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> 
                <strong>Penting:</strong> Transaksi yang sudah diserahterimakan dan diterima tidak dapat lagi Anda setorkan ke staff keuangan. 
                Hanya kasir penerima yang dapat melakukan setoran untuk transaksi tersebut.
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Belum ada transaksi yang diserahterimakan.
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab Riwayat Masuk -->
        <div class="tab-pane" id="riwayat-masuk">
            <h5 style="margin-bottom: 16px; color: var(--text-dark);">Riwayat Transaksi yang Saya Terima</h5>
            
            <?php if (!empty($riwayat_masuk)): ?>            
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kode Serah Terima</th>
                            <th>Tanggal</th>
                            <th>Pemberi</th>
                            <th>Jumlah Item</th>
                            <th>Total Nilai</th>
                            <th>Status</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($riwayat_masuk as $riwayat): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($riwayat['kode_serah_terima']); ?></code></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($riwayat['tanggal_serah_terima'])); ?></td>
                                <td><?php echo htmlspecialchars($riwayat['nama_pemberi']); ?></td>
                                <td><?php echo $riwayat['jumlah_transaksi']; ?> item</td>
                                <td><?php echo formatRupiah($riwayat['total_nilai']); ?></td>
                                <td><?php echo getStatusBadge($riwayat['status']); ?></td>
                                <td>
                                    <?php if (!empty($riwayat['catatan'])): ?>
                                        <button type="button" 
                                                class="btn btn-secondary" 
                                                style="padding: 6px 12px; font-size: 12px;" 
                                                data-kode="<?php echo htmlspecialchars($riwayat['kode_serah_terima']); ?>"
                                                data-catatan="<?php echo htmlspecialchars($riwayat['catatan']); ?>"
                                                data-status="<?php echo htmlspecialchars($riwayat['status']); ?>"
                                                data-transaksi="<?php echo htmlspecialchars($riwayat['daftar_transaksi']); ?>"
                                                data-jumlah="<?php echo htmlspecialchars($riwayat['jumlah_transaksi']); ?>"
                                                onclick="showDetailFromData(this)">
                                            <i class="fas fa-eye"></i> Lihat
                                        </button>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 12px;">Tidak ada catatan</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> 
                <strong>Info:</strong> Transaksi yang Anda terima (status Selesai) dari kasir lain dapat Anda setorkan ke staff keuangan melalui halaman 
                <a href="setoran_keuangan_cs.php" style="color: var(--primary-color);">Setoran Keuangan CS</a>.
            </div>
            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Belum ada transaksi yang diterima dari kasir lain.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal untuk menampilkan detail catatan -->
<div id="detailModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; padding: 24px; max-width: 700px; width: 90%; max-height: 80%; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0; color: var(--text-dark);">
                <i class="fas fa-file-alt"></i> Detail Serah Terima
            </h3>
            <button type="button" onclick="closeDetailModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted);">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div style="margin-bottom: 16px;">
            <strong>Kode Serah Terima:</strong> <code id="modalKodeSerahTerima"></code>
        </div>
        
        <div style="margin-bottom: 16px;">
            <strong>Status:</strong> <span id="modalStatus"></span>
        </div>
        
        <div style="margin-bottom: 16px;">
            <strong>Jumlah Item:</strong> <span id="modalJumlahTransaksi"></span>
        </div>
        
        <div style="margin-bottom: 16px;">
            <strong>Daftar Kode Transaksi:</strong>
            <div id="modalDaftarTransaksi" style="background: var(--background-light); padding: 16px; border-radius: 8px; margin-top: 8px; font-family: monospace; font-size: 14px; max-height: 150px; overflow-y: auto;"></div>
        </div>
        
        <div>
            <strong>Catatan:</strong>
            <div id="modalCatatan" style="background: var(--background-light); padding: 16px; border-radius: 8px; margin-top: 8px; white-space: pre-line; font-family: monospace; font-size: 14px; max-height: 200px; overflow-y: auto;"></div>
        </div>
        
        <div style="margin-top: 20px; text-align: right;">
            <button type="button" class="btn btn-secondary" onclick="closeDetailModal()">
                <i class="fas fa-times"></i> Tutup
            </button>
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

    // Function to check if form is valid and enable/disable submit button
    function checkFormValidity() {
        const kasirPenerima = document.getElementById('kode_karyawan_penerima');
        const checkedBoxes = document.querySelectorAll('.transaksi-checkbox:checked');
        const submitBtn = document.getElementById('btnKirimPermintaan');
        
        // Enable submit button only if kasir penerima is selected and at least one transaction is checked
        if (kasirPenerima && kasirPenerima.value && checkedBoxes.length > 0) {
            submitBtn.disabled = false;
        } else {
            submitBtn.disabled = true;
        }
    }

    // Search dropdown functionality
    const searchInput = document.getElementById('searchPenerima');
    const dropdownList = document.getElementById('dropdownList');
    const hiddenInput = document.getElementById('kode_karyawan_penerima');
    const dropdownItems = document.querySelectorAll('.dropdown-item');

    // Show dropdown when clicking search input
    searchInput?.addEventListener('focus', function() {
        dropdownList.style.display = 'block';
        this.style.borderRadius = '8px 8px 0 0';
    });

    // Hide dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-dropdown')) {
            dropdownList.style.display = 'none';
            searchInput.style.borderRadius = '8px';
        }
    });

    // Filter dropdown items based on search
    searchInput?.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        let hasVisibleItems = false;

        dropdownItems.forEach(item => {
            if (item.textContent.toLowerCase().includes(searchTerm)) {
                item.style.display = 'block';
                hasVisibleItems = true;
            } else {
                item.style.display = 'none';
            }
        });

        dropdownList.style.display = hasVisibleItems ? 'block' : 'none';
    });

    // Handle item selection
    dropdownItems.forEach(item => {
        item.addEventListener('click', function() {
            const value = this.getAttribute('data-value');
            const name = this.getAttribute('data-name');
            const role = this.getAttribute('data-role');
            const cabang = this.getAttribute('data-cabang');
            
            if (value && name) {
                hiddenInput.value = value;
                const roleFormatted = role ? role.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) : 'User';
                
                // Format tanpa "null" atau "- null"
                let displayText = `${name} (${value}) - ${roleFormatted}`;
                if (cabang && cabang.trim() !== '' && cabang !== 'null') {
                    displayText += ` - ${cabang}`;
                }
                
                searchInput.value = displayText;
                dropdownList.style.display = 'none';
                searchInput.style.borderRadius = '8px';
                
                // Visual feedback
                searchInput.style.borderColor = 'var(--success-color)';
                searchInput.style.backgroundColor = 'rgba(40, 167, 69, 0.05)';
                
                checkFormValidity();
            }
        });
    });

    // Function untuk update selisih nominal
    function updateSelisih(kodeSerahTerima, nominalSerah) {
        const nominalInput = document.getElementById(`nominal_diterima_${kodeSerahTerima}`);
        const selisihSpan = document.getElementById(`selisih_${kodeSerahTerima}`);
        const statusSpan = document.getElementById(`status_tombol_${kodeSerahTerima}`);
        const btnTerima = document.getElementById(`btn_terima_${kodeSerahTerima}`);
        const btnTolak = document.getElementById(`btn_tolak_${kodeSerahTerima}`);
        const hiddenNominal = document.getElementById(`hidden_nominal_${kodeSerahTerima}`);
        
        const nominalDiterima = parseFloat(nominalInput.value) || 0;
        const selisih = nominalDiterima - nominalSerah;
        
        // Update hidden input
        hiddenNominal.value = nominalDiterima;
        
        // Format selisih
        const selisihFormatted = new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0
        }).format(Math.abs(selisih));
        
        // Update tampilan selisih dan catatan otomatis
        if (selisih > 0) {
            selisihSpan.textContent = `+${selisihFormatted}`;
            selisihSpan.className = 'selisih-positive';
            statusSpan.textContent = 'Hanya Tolak Aktif';
            statusSpan.className = 'status-badge status-danger';
            btnTerima.disabled = true;
            btnTolak.disabled = false;
        } else if (selisih === 0) {
            selisihSpan.textContent = selisihFormatted.replace(/[+-]/, '');
            selisihSpan.className = 'selisih-zero';
            statusSpan.textContent = 'Terima & Tolak Aktif';
            statusSpan.className = 'status-badge status-success';
            btnTerima.disabled = false;
            btnTolak.disabled = false;
        } else {
            // Selisih negatif (kurang) - nonaktifkan tombol terima
            selisihSpan.textContent = `-${selisihFormatted}`;
            selisihSpan.className = 'selisih-negative';
            statusSpan.textContent = 'Hanya Tolak Aktif';
            statusSpan.className = 'status-badge status-danger';
            btnTerima.disabled = true;
            btnTolak.disabled = false;
        }
        
        // Update catatan input otomatis jika ada selisih
        const catatanInput = document.getElementById(`catatan_penerima_${kodeSerahTerima}`);
        const alasanPenolakanInput = document.getElementById(`alasan_penolakan_${kodeSerahTerima}`);
        
        if (selisih !== 0) {
            const selisihText = selisih > 0 
                ? `Nominal diterima lebih besar Rp ${Math.abs(selisih).toLocaleString('id-ID')} dari nominal serah`
                : `Nominal diterima kurang Rp ${Math.abs(selisih).toLocaleString('id-ID')} dari nominal serah`;
            
            // Update input catatan penerima
            if (catatanInput) {
                catatanInput.value = selisihText;
            }
            
            // Update input alasan penolakan
            if (alasanPenolakanInput) {
                alasanPenolakanInput.value = selisihText;
            }
        } else {
            // Clear both inputs when no difference
            if (catatanInput) {
                catatanInput.value = '';
            }
            
            if (alasanPenolakanInput) {
                alasanPenolakanInput.value = '';
            }
        }
    }

    // Function untuk konfirmasi terima
    function confirmTerima(kodeSerahTerima) {
        const nominalInput = document.getElementById(`nominal_diterima_${kodeSerahTerima}`);
        const nominalDiterima = parseFloat(nominalInput.value) || 0;
        
        return confirm(`Yakin ingin menerima serah terima ini?\n\nNominal yang akan diterima: ${new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0
        }).format(nominalDiterima)}`);
    }

    // Make functions global
    window.updateSelisih = updateSelisih;
    window.confirmTerima = confirmTerima;

    // Function untuk menampilkan detail dari data attributes
    function showDetailFromData(button) {
        const kodeSerahTerima = button.getAttribute('data-kode');
        const catatan = button.getAttribute('data-catatan');
        const status = button.getAttribute('data-status');
        const daftarTransaksi = button.getAttribute('data-transaksi');
        const jumlahTransaksi = button.getAttribute('data-jumlah');
        
        showDetail(kodeSerahTerima, catatan, status, daftarTransaksi, jumlahTransaksi);
    }

    // Function untuk menampilkan detail catatan
    function showDetail(kodeSerahTerima, catatan, status, daftarTransaksi = '', jumlahTransaksi = 0) {
        try {
            document.getElementById('modalKodeSerahTerima').textContent = kodeSerahTerima || 'N/A';
            document.getElementById('modalCatatan').textContent = catatan || 'Tidak ada catatan';
            
            // Set jumlah transaksi
            document.getElementById('modalJumlahTransaksi').textContent = (jumlahTransaksi || 0) + ' item';
            
            // Format dan tampilkan daftar transaksi
            const daftarTransaksiElement = document.getElementById('modalDaftarTransaksi');
            if (daftarTransaksi && daftarTransaksi.trim() !== '') {
                const transaksiArray = daftarTransaksi.split(', ');
                if (transaksiArray.length > 1) {
                    // Jika lebih dari 1 transaksi, tampilkan dalam format list
                    let transaksiList = '<ul style="margin: 0; padding-left: 20px;">';
                    transaksiArray.forEach((transaksi, index) => {
                        transaksiList += `<li style="margin-bottom: 4px;"><code>${transaksi.trim()}</code></li>`;
                    });
                    transaksiList += '</ul>';
                    daftarTransaksiElement.innerHTML = transaksiList;
                } else {
                    // Jika hanya 1 transaksi
                    daftarTransaksiElement.innerHTML = `<code>${daftarTransaksi}</code>`;
                }
            } else {
                daftarTransaksiElement.innerHTML = '<em style="color: var(--text-muted);">Tidak ada data transaksi</em>';
            }
            
            // Set status badge
            const modalStatus = document.getElementById('modalStatus');
            switch(status) {
                case 'completed':
                    modalStatus.innerHTML = '<span class="status-badge status-success">Selesai</span>';
                    break;
                case 'pending':
                    modalStatus.innerHTML = '<span class="status-badge status-warning">Menunggu Konfirmasi</span>';
                    break;
                case 'cancelled':
                    modalStatus.innerHTML = '<span class="status-badge status-danger">Ditolak</span>';
                    break;
                default:
                    modalStatus.innerHTML = '<span class="status-badge status-secondary">Unknown</span>';
            }
            
            document.getElementById('detailModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
            
        } catch (error) {
            console.error('Error in showDetail:', error);
            alert('Terjadi kesalahan saat menampilkan detail: ' + error.message);
        }
    }

    // Function untuk menutup modal
    function closeDetailModal() {
        document.getElementById('detailModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    // Close modal when clicking outside
    document.getElementById('detailModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeDetailModal();
        }
    });

    // Close modal with ESC key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDetailModal();
        }
    });

    // Make functions global
    window.showDetail = showDetail;
    window.closeDetailModal = closeDetailModal;
    window.showDetailFromData = showDetailFromData;

    // Clear selection when search input is cleared
    searchInput?.addEventListener('input', function() {
        if (this.value === '') {
            hiddenInput.value = '';
            this.style.borderColor = 'var(--border-color)';
            this.style.backgroundColor = 'white';
            checkFormValidity();
        }
    });

    // Event listeners
    document.getElementById('kode_karyawan_penerima')?.addEventListener('change', checkFormValidity);

    document.getElementById('selectAllSerahTerima')?.addEventListener('change', function() {
        document.querySelectorAll('.transaksi-checkbox').forEach(checkbox => {
            checkbox.checked = this.checked;
        });
        checkFormValidity();
    });

    // Add event listeners to individual checkboxes
    document.querySelectorAll('.transaksi-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', checkFormValidity);
    });

    // Prevent form submission if conditions not met
    document.getElementById('serahTerimaForm')?.addEventListener('submit', function(e) {
        const kasirPenerima = document.getElementById('kode_karyawan_penerima');
        const searchInput = document.getElementById('searchPenerima');
        const checkedBoxes = document.querySelectorAll('.transaksi-checkbox:checked');
        
        if (!kasirPenerima.value) {
            e.preventDefault();
            alert('Pilih kasir penerima terlebih dahulu!');
            searchInput?.focus();
            return false;
        }
        
        if (checkedBoxes.length === 0) {
            e.preventDefault();
            alert('Pilih setidaknya satu transaksi untuk diserahterimakan!');
            return false;
        }
        
        // Confirm before submitting
        const kasirName = searchInput.value;
        const confirmMsg = `Yakin ingin mengirim permintaan serah terima?\n\nPenerima: ${kasirName}\nJumlah Item: ${checkedBoxes.length} item\n\nSetelah dikirim, status akan menjadi "Menunggu Konfirmasi".`;
        
        if (!confirm(confirmMsg)) {
            e.preventDefault();
            return false;
        }
    });

    // Reset form handler
    document.querySelector('button[type="reset"]')?.addEventListener('click', function() {
        // Reset search input and hidden input
        document.getElementById('searchPenerima').value = '';
        document.getElementById('kode_karyawan_penerima').value = '';
        document.getElementById('searchPenerima').style.borderColor = 'var(--border-color)';
        document.getElementById('searchPenerima').style.backgroundColor = 'white';
        
        setTimeout(() => {
            checkFormValidity();
        }, 100);
    });

    // Adjust sidebar width based on content
    function adjustSidebarWidth() {
        const sidebar = document.getElementById('sidebar');
        const links = sidebar.getElementsByTagName('a');
        let maxWidth = 0;

        for (let link of links) {
            link.style.whiteSpace = 'nowrap';
            const width = link.getBoundingClientRect().width;
            if (width > maxWidth) {
                maxWidth = width;
            }
        }

        const minWidth = 200;
        sidebar.style.width = maxWidth > minWidth ? `${maxWidth + 20}px` : `${minWidth}px`;
        document.querySelector('.main-content').style.marginLeft = sidebar.style.width;
    }

    // Initialize
    window.addEventListener('load', function() {
        adjustSidebarWidth();
        checkFormValidity();
        
        // Debug: Log jumlah kasir yang ditemukan
        console.log('Total kasir/admin/super admin tersedia:', dropdownItems.length);
    });
    window.addEventListener('resize', adjustSidebarWidth);

</script>

</body>
</html>