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
        case 'Dikembalikan ke CS':
            return '<span class="status-badge status-warning-alt"><i class="fas fa-undo"></i> Dikembalikan ke CS</span>';
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
        case 'DARI_CLOSING':
            return '<span class="jenis-badge jenis-closing">DARI CLOSING</span>';
        case 'GABUNGAN':
            return '<span class="jenis-badge jenis-gabungan">Setoran Rill - Pemasukan</span>';
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
        case 'Dikembalikan ke CS':
            return '<span class="status-badge status-warning-alt"><i class="fas fa-undo"></i> Dikembalikan ke CS</span>';
        case 'Sudah Disetor ke Bank':
            return '<span class="status-badge status-success">Sudah Disetor ke Bank</span>';
        default:
            return '<span class="status-badge status-secondary">Status Tidak Dikenal</span>';
    }
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

            // Calculate total deposit amount
            $total_setoran = 0;
            $transaksi_to_update = [];
            
            // PERBAIKAN: Handle gabungan transactions dengan MINUS closing
            foreach ($selected_transaksi as $kode_transaksi) {
                if (strpos($kode_transaksi, 'GABUNGAN_') === 0) {
                    // This is a combined transaction, get both regular and closing transactions
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
                    $transaksi_to_update[] = $base_code;
                    $total_setoran += $total_amount;
                    
                } else {
                    // Individual transaction (either regular or closing only)
                    $sql_check = "
                        SELECT 
                            kt.setoran_real,
                            CASE 
                                WHEN EXISTS (
                                    SELECT 1 FROM pemasukan_kasir pk 
                                    WHERE pk.nomor_transaksi_closing = :kode_transaksi 
                                    AND pk.kode_karyawan = :kode_karyawan
                                ) THEN 'CLOSING'
                                ELSE 'REGULAR'
                            END as jenis,
                            COALESCE((
                                SELECT pk.jumlah FROM pemasukan_kasir pk 
                                WHERE pk.nomor_transaksi_closing = :kode_transaksi2 
                                AND pk.kode_karyawan = :kode_karyawan2
                            ), 0) as closing_amount
                        FROM kasir_transactions kt 
                        WHERE kt.kode_transaksi = :kode_transaksi3";
                    
                    $stmt_check = $pdo->prepare($sql_check);
                    $stmt_check->execute([
                        ':kode_transaksi' => $kode_transaksi,
                        ':kode_karyawan' => $kode_karyawan,
                        ':kode_transaksi2' => $kode_transaksi,
                        ':kode_karyawan2' => $kode_karyawan,
                        ':kode_transaksi3' => $kode_transaksi
                    ]);
                    $check_result = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($check_result['jenis'] === 'CLOSING') {
                        // PERBAIKAN: Untuk closing yang standalone, minus dari 0 (pengambilan)
                        $total_setoran += (0 - $check_result['closing_amount']);
                    } else {
                        $total_setoran += $check_result['setoran_real'];
                    }
                    
                    $transaksi_to_update[] = $kode_transaksi;
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
            
            $message = "Setoran berhasil disubmit dengan kode: $kode_setoran\\n\\nTotal Setoran: " . formatRupiah($total_setoran) . "\\n\\nStatus: Sedang Dibawa Kurir ke Staff Keuangan";
            if ($total_setoran < 0) {
                $message .= "\\n\\nCATATAN: Total setoran negatif karena pengambilan closing lebih besar dari setoran reguler.";
            }
            
            echo "<script>alert('$message'); window.location.href = 'setoran_keuangan_cs.php';</script>";
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location.href = 'setoran_keuangan_cs.php';</script>";
        }
    }
}

// Handler untuk mengirim ulang transaksi yang dikembalikan ke CS
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['kirim_ulang_transaksi'])) {
    $selected_dikembalikan = $_POST['kode_transaksi_dikembalikan'] ?? [];
    // PERBAIKAN: Tidak perlu nama pengantar untuk kembalikan ke CS
    $nama_pengantar = $username; // Gunakan nama user yang login sebagai pengantar
    
    if (empty($selected_dikembalikan)) {
        echo "<script>alert('Pilih setidaknya satu transaksi untuk dikirim ulang.');</script>";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Generate unique deposit code
            $kode_setoran = 'SETORAN-ULANG-' . date('Ymd') . '-' . substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 6);
            
            $total_setoran = 0;
            $success_count = 0;
            
            // Process each returned transaction
            foreach ($selected_dikembalikan as $kode_transaksi) {
                // Get transaction details
                $sql_check = "SELECT setoran_real FROM kasir_transactions 
                             WHERE kode_transaksi = :kode_transaksi 
                             AND kode_karyawan = :kode_karyawan 
                             AND deposit_status = 'Dikembalikan ke CS'";
                $stmt_check = $pdo->prepare($sql_check);
                $stmt_check->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
                $stmt_check->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
                $stmt_check->execute();
                $setoran_real = $stmt_check->fetchColumn();
                
                if ($setoran_real) {
                    // Update transaction status back to "Sedang Dibawa Kurir"
                    $sql_update = "UPDATE kasir_transactions SET 
                                  deposit_status = 'Sedang Dibawa Kurir',
                                  kode_setoran = :kode_setoran,
                                  catatan_validasi = CONCAT(COALESCE(catatan_validasi, ''), '\n--- KIRIM ULANG ---\nDikirim ulang pada: ', NOW(), ' oleh: ', :kode_karyawan)
                                  WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan";
                    $stmt_update = $pdo->prepare($sql_update);
                    $stmt_update->bindParam(':kode_setoran', $kode_setoran, PDO::PARAM_STR);
                    $stmt_update->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
                    $stmt_update->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
                    
                    if ($stmt_update->execute()) {
                        $total_setoran += $setoran_real;
                        $success_count++;
                    }
                }
            }
            
            // Insert into setoran_keuangan table
            if ($success_count > 0) {
                $sql_insert_setoran = "INSERT INTO setoran_keuangan 
                                      (kode_setoran, kode_karyawan, kode_cabang, nama_cabang, tanggal_setoran, jumlah_setoran, nama_pengantar, status, created_by, created_at) 
                                      VALUES (:kode_setoran, :kode_karyawan, :kode_cabang, :nama_cabang, NOW(), :jumlah_setoran, :nama_pengantar, 'Sedang Dibawa Kurir', :created_by, NOW())";
                $stmt_insert_setoran = $pdo->prepare($sql_insert_setoran);
                $stmt_insert_setoran->execute([
                    ':kode_setoran' => $kode_setoran,
                    ':kode_karyawan' => $kode_karyawan,
                    ':kode_cabang' => $kode_cabang,
                    ':nama_cabang' => $nama_cabang,
                    ':jumlah_setoran' => $total_setoran,
                    ':nama_pengantar' => $nama_pengantar,
                    ':created_by' => $kode_karyawan
                ]);
            }
            
            $pdo->commit();
            
            $message = "Berhasil mengirim ulang $success_count transaksi dengan kode: $kode_setoran\\n\\nTotal Setoran: " . formatRupiah($total_setoran) . "\\n\\nStatus: Sedang Dibawa Kurir ke Staff Keuangan";
            echo "<script>alert('$message'); window.location.href = 'setoran_keuangan_cs.php';</script>";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error mengirim ulang: " . addslashes($e->getMessage()) . "'); window.location.href = 'setoran_keuangan_cs.php';</script>";
        }
    }
}

// Handler untuk permintaan konfirmasi membuka transaksi kembali
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['minta_konfirmasi_buka'])) {
    $selected_dikembalikan = $_POST['kode_transaksi_dikembalikan'] ?? [];
    
    if (empty($selected_dikembalikan)) {
        echo "<script>alert('Pilih setidaknya satu transaksi untuk diminta konfirmasi pembukaan.');</script>";
    } else {
        try {
            $pdo->beginTransaction();
            
            $success_count = 0;
            $failed_requests = [];
            
            foreach ($selected_dikembalikan as $kode_transaksi) {
                // Cek apakah sudah ada permintaan pending untuk transaksi ini
                $sql_check_existing = "SELECT id FROM konfirmasi_buka_transaksi 
                                      WHERE kode_transaksi = :kode_transaksi 
                                      AND status = 'pending'";
                $stmt_check = $pdo->prepare($sql_check_existing);
                $stmt_check->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
                $stmt_check->execute();
                
                if ($stmt_check->fetch()) {
                    $failed_requests[] = $kode_transaksi . " (sudah ada permintaan pending)";
                    continue;
                }
                
                // Insert permintaan konfirmasi baru
                $sql_insert = "INSERT INTO konfirmasi_buka_transaksi 
                              (kode_transaksi, kode_karyawan_peminta, nama_cabang, tanggal_permintaan, status, alasan_permintaan) 
                              VALUES (:kode_transaksi, :kode_karyawan, :nama_cabang, NOW(), 'pending', :alasan)";
                
                $alasan = "Permintaan dari CS untuk membuka kembali transaksi yang dikembalikan";
                
                $stmt_insert = $pdo->prepare($sql_insert);
                $stmt_insert->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
                $stmt_insert->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
                $stmt_insert->bindParam(':nama_cabang', $nama_cabang, PDO::PARAM_STR);
                $stmt_insert->bindParam(':alasan', $alasan, PDO::PARAM_STR);
                
                if ($stmt_insert->execute()) {
                    $success_count++;
                }
            }
            
            $pdo->commit();
            
            $message = "Berhasil mengirim $success_count permintaan konfirmasi ke Super Admin.";
            if (!empty($failed_requests)) {
                $message .= "\\n\\nGagal: " . implode(', ', $failed_requests);
            }
            $message .= "\\n\\nSilakan tunggu konfirmasi dari Super Admin.";
            
            echo "<script>alert('$message'); window.location.href = 'setoran_keuangan_cs.php';</script>";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error mengirim permintaan: " . addslashes($e->getMessage()) . "'); window.location.href = 'setoran_keuangan_cs.php';</script>";
        }
    }
}

// PERBAIKAN: Query untuk menggabungkan transaksi reguler dan closing menjadi satu item jika keduanya ada dengan MINUS closing
$sql_transaksi = "
    SELECT DISTINCT
        kt.kode_transaksi,
        kt.tanggal_transaksi,
        kt.setoran_real,
        kt.deposit_status,
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
    AND (
        (kt.deposit_status IS NULL OR kt.deposit_status = '' OR kt.deposit_status = 'Belum Disetor')
        OR 
        (kt.deposit_status = 'Diserahterimakan')
    )
    AND kt.deposit_status != 'Dikembalikan ke CS'
    AND kt.kode_transaksi NOT IN (
        SELECT DISTINCT kode_transaksi_asal 
        FROM serah_terima_kasir 
        WHERE (kode_karyawan_pemberi = :kode_karyawan_pemberi AND status IN ('pending', 'completed'))
        AND kode_transaksi_asal IS NOT NULL
        AND kode_karyawan_pemberi != kode_karyawan_penerima
    )
    -- PERBAIKAN: Sembunyikan transaksi closing yang diambil oleh transaksi lain yang masih 'on proses'
    AND kt.kode_transaksi NOT IN (
        SELECT DISTINCT pk.nomor_transaksi_closing
        FROM pemasukan_kasir pk
        INNER JOIN kasir_transactions kt_taking ON pk.kode_transaksi = kt_taking.kode_transaksi
        WHERE pk.nomor_transaksi_closing IS NOT NULL
        AND kt_taking.status = 'on proses'
        AND pk.nomor_transaksi_closing = kt.kode_transaksi
    )
    ORDER BY kt.tanggal_transaksi DESC";

$stmt_transaksi = $pdo->prepare($sql_transaksi);
$stmt_transaksi->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi->bindParam(':kode_karyawan_check', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi->bindParam(':kode_karyawan_closing', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi->bindParam(':kode_karyawan_total', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi->bindParam(':kode_karyawan_pemberi', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi->execute();
$transaksi_raw = $stmt_transaksi->fetchAll(PDO::FETCH_ASSOC);

// Proses data untuk menggabungkan transaksi yang memiliki reguler dan closing
$transaksi_list = [];
foreach ($transaksi_raw as $trans) {
    if ($trans['has_closing_transaction'] === 'HAS_CLOSING' && $trans['closing_amount'] != 0) {
        // Gabungkan transaksi reguler dan closing menjadi satu item
        $gabungan_item = [
            'kode_transaksi' => 'GABUNGAN_' . $trans['kode_transaksi'],
            'kode_transaksi_asli' => $trans['kode_transaksi'],
            'tanggal_transaksi' => $trans['tanggal_transaksi'],
            'jumlah_setoran' => $trans['total_gabungan'],
            'deposit_status' => $trans['deposit_status'],
            'jenis_transaksi' => 'GABUNGAN',
            'detail_reguler' => $trans['setoran_real'],
            'detail_closing' => $trans['closing_amount'],
            'is_combined' => true
        ];
        $transaksi_list[] = $gabungan_item;
    } else {
        // Transaksi reguler biasa
        $regular_item = [
            'kode_transaksi' => $trans['kode_transaksi'],
            'kode_transaksi_asli' => $trans['kode_transaksi'],
            'tanggal_transaksi' => $trans['tanggal_transaksi'],
            'jumlah_setoran' => $trans['setoran_real'],
            'deposit_status' => $trans['deposit_status'],
            'jenis_transaksi' => 'REGULER',
            'is_combined' => false
        ];
        $transaksi_list[] = $regular_item;
    }
}

// Get transactions that have already been deposited for separate display
$sql_transaksi_disetor = "
    SELECT 
        kt.kode_transaksi, 
        kt.tanggal_transaksi, 
        kt.setoran_real as jumlah_setoran,
        kt.deposit_status, 
        kt.kode_setoran,
        sk.status as status_setoran, 
        sk.tanggal_setoran,
        'REGULER' as jenis_transaksi
    FROM kasir_transactions kt
    LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
    WHERE kt.kode_karyawan = :kode_karyawan
    AND kt.status = 'end proses'
    AND kt.deposit_status IN ('Sedang Dibawa Kurir', 'Diterima Staff Keuangan', 'Validasi Keuangan OK', 'Validasi Keuangan SELISIH', 'Sudah Disetor ke Bank')
    ORDER BY kt.tanggal_transaksi DESC
    LIMIT 50";

$stmt_transaksi_disetor = $pdo->prepare($sql_transaksi_disetor);
$stmt_transaksi_disetor->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi_disetor->execute();
$transaksi_disetor_list = $stmt_transaksi_disetor->fetchAll(PDO::FETCH_ASSOC);

// Get transactions that have been returned by finance staff
$sql_transaksi_dikembalikan = "
    SELECT 
        kt.kode_transaksi, 
        kt.tanggal_transaksi, 
        kt.setoran_real as jumlah_setoran,
        kt.deposit_status,
        kt.catatan_validasi,
        kt.validasi_at,
        kt.kode_setoran,
        sk.status as status_setoran,
        CASE 
            WHEN EXISTS (
                SELECT 1 FROM pemasukan_kasir pk 
                WHERE pk.nomor_transaksi_closing = kt.kode_transaksi 
                AND pk.kode_karyawan = :kode_karyawan_closing
            ) THEN 'CLOSING'
            ELSE 'REGULER'
        END as jenis_transaksi
    FROM kasir_transactions kt
    LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
    WHERE (
        kt.kode_karyawan = :kode_karyawan
        OR kt.kode_transaksi IN (
            SELECT nomor_transaksi_closing 
            FROM pemasukan_kasir 
            WHERE kode_karyawan = :kode_karyawan_pemasukan
        )
    )
    AND kt.status = 'end proses'
    AND kt.deposit_status = 'Dikembalikan ke CS'
    ORDER BY kt.validasi_at DESC";

$stmt_transaksi_dikembalikan = $pdo->prepare($sql_transaksi_dikembalikan);
$stmt_transaksi_dikembalikan->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi_dikembalikan->bindParam(':kode_karyawan_closing', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi_dikembalikan->bindParam(':kode_karyawan_pemasukan', $kode_karyawan, PDO::PARAM_STR);
$stmt_transaksi_dikembalikan->execute();
$transaksi_dikembalikan_list = $stmt_transaksi_dikembalikan->fetchAll(PDO::FETCH_ASSOC);

// Get transactions that have been handed over
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
        st.catatan
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

// Calculate total safely
$total_siap_setor = array_sum(array_column($transaksi_list, 'jumlah_setoran'));
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
            min-width: 1000px;
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
            border: 2px solid transparent;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        .btn-primary:hover {
            background-color: #0056b3;
            border-color: #0056b3;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,123,255,0.3);
        }
        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
            border-color: var(--secondary-color);
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #5a6268;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(108,117,125,0.3);
        }
        .btn-danger {
            background-color: var(--danger-color);
            color: white;
            border-color: var(--danger-color);
        }
        .btn-danger:hover {
            background-color: #c82333;
            border-color: #c82333;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(220,53,69,0.3);
        }
        .btn-warning {
            background-color: var(--warning-color);
            color: #212529;
            border-color: var(--warning-color);
        }
        .btn-warning:hover {
            background-color: #e0a800;
            border-color: #e0a800;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(255,193,7,0.3);
        }
        .btn-info {
            background-color: var(--info-color);
            color: white;
            border-color: var(--info-color);
        }
        .btn-info:hover {
            background-color: #138496;
            border-color: #138496;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(23,162,184,0.3);
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
        .status-warning-alt { background: rgba(255,193,7,0.15); color: #e0a800; border: 1px solid rgba(255,193,7,0.3); }
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
        .jenis-gabungan { 
            background: linear-gradient(45deg, rgba(40,167,69,0.1), rgba(220,53,69,0.1));
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
        
        /* PERBAIKAN: Style untuk nominal negatif */
        .closing-amount {
            font-weight: 600;
        }
        .closing-amount.negative {
            color: var(--danger-color);
            font-weight: 600;
        }
        .closing-amount.positive {
            color: var(--success-color);
            font-weight: 600;
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
                <p><strong>Transaksi Siap Setor:</strong> <?php echo count($transaksi_list); ?> item</p>
                <p><strong>Total Bisa Disetor:</strong> 
                    <span class="<?php echo $total_siap_setor < 0 ? 'closing-amount negative' : ($total_siap_setor > 0 ? 'closing-amount positive' : 'closing-amount'); ?>">
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
        <div class="nav-tab" onclick="switchTab('dikembalikan', this)" style="<?php echo count($transaksi_dikembalikan_list) > 0 ? 'border-left: 4px solid var(--warning-color);' : ''; ?>">
            <i class="fas fa-undo"></i> Dikembalikan ke CS (<?php echo count($transaksi_dikembalikan_list); ?>)
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
                
                <!-- PERBAIKAN: Alert untuk logika penggabungan dengan MINUS closing -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Logika Penggabungan Transaksi (DIPERBAIKI):</strong>
                    <ul style="margin: 8px 0 0 20px;">
                        <li>Jika ada transaksi reguler dan pemasukan closing dengan kode transaksi yang sama, keduanya akan digabung menjadi satu item setoran</li>
                        <li><strong>PERBAIKAN:</strong> Closing amount akan <strong>DIKURANGI</strong> dari setoran reguler (bukan ditambahkan) karena closing adalah pengambilan uang</li>
                        <li>Formula: <strong>Total Gabungan = Setoran Reguler - Pemasukan</strong></li>
                        <li>Transaksi yang hanya reguler atau hanya closing akan ditampilkan terpisah</li>
                        <li>Total setoran dapat menjadi negatif jika pengambilan closing lebih besar dari setoran reguler</li>
                        <li>Penggabungan mempermudah proses setoran dan mengurangi kompleksitas administrasi</li>
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
                                            <code><?php echo htmlspecialchars($trans['kode_transaksi_asli']); ?></code>
                                            <?php if ($trans['is_combined']): ?>
                                                <div class="gabungan-details">
                                                    <i class="fas fa-calculator"></i>
                                                    <strong>Gabungan:</strong> Reguler (<?php echo formatRupiah($trans['detail_reguler']); ?>) - 
                                                    Pemasukan (<?php echo formatRupiah($trans['detail_closing']); ?>) = 
                                                    <strong><?php echo formatRupiah($trans['jumlah_setoran']); ?></strong>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('d/m/Y', strtotime($trans['tanggal_transaksi'])); ?></td>
                                        <td><?php echo getJenisTransaksiBadge($trans['jenis_transaksi']); ?></td>
                                        <td class="<?php echo $trans['jumlah_setoran'] < 0 ? 'closing-amount negative' : ($trans['jumlah_setoran'] > 0 ? 'closing-amount positive' : 'closing-amount'); ?>">
                                            <?php echo formatRupiah($trans['jumlah_setoran']); ?>
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
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" class="text-right"><strong>Total Setoran:</strong></td>
                                    <td class="<?php echo $total_siap_setor < 0 ? 'closing-amount negative' : ($total_siap_setor > 0 ? 'closing-amount positive' : 'closing-amount'); ?>">
                                        <strong><?php echo formatRupiah($total_siap_setor); ?></strong>
                                    </td>
                                    <td></td>
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
                <i class="fas fa-info-circle"></i> Tidak ada transaksi yang bisa disetor saat ini. Pastikan transaksi sudah dalam status "End Proses".
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab Dikembalikan ke CS -->
        <div class="tab-pane" id="dikembalikan">
            <h5 style="margin-bottom: 16px; color: var(--text-dark);">
                <i class="fas fa-undo" style="color: var(--warning-color);"></i> 
                Transaksi yang Dikembalikan Staff Keuangan
            </h5>
            
            <?php if (!empty($transaksi_dikembalikan_list)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Perhatian:</strong> Transaksi berikut telah dikembalikan oleh staff keuangan karena ada masalah. 
                Silakan periksa catatan dan perbaiki sebelum mengirim ulang.
            </div>
            
            <form action="" method="POST" id="kirimUlangForm">
                <div class="btn-group" style="margin-bottom: 20px;">
                    <button type="submit" name="kirim_ulang_transaksi" class="btn btn-warning" 
                            onclick="return confirm('Yakin ingin mengirim ulang transaksi yang dipilih?')">
                        <i class="fas fa-paper-plane"></i> Kirim Ulang Transaksi Terpilih
                    </button>
                    
                    <button type="submit" name="minta_konfirmasi_buka" class="btn btn-info" 
                            onclick="return confirm('Yakin ingin meminta konfirmasi untuk membuka transaksi ini menjadi On Proses kembali? Permintaan akan dikirim ke Super Admin.')">
                        <i class="fas fa-unlock"></i> Minta Konfirmasi Buka Transaksi
                    </button>
                </div>
                
                <div class="table-container">
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th width="50">
                                        <input type="checkbox" id="selectAllDikembalikan" onclick="toggleAllDikembalikan()">
                                    </th>
                                    <th>Kode Transaksi</th>
                                    <th>Tanggal Transaksi</th>
                                    <th>Jumlah Setoran</th>
                                    <th>Dikembalikan Pada</th>
                                    <th>Alasan/Catatan</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transaksi_dikembalikan_list as $transaksi): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="kode_transaksi_dikembalikan[]" 
                                               value="<?php echo htmlspecialchars($transaksi['kode_transaksi']); ?>" 
                                               class="transaksi-checkbox-dikembalikan">
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($transaksi['kode_transaksi']); ?></strong>
                                        <?php echo getJenisTransaksiBadge($transaksi['jenis_transaksi']); ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($transaksi['tanggal_transaksi'])); ?></td>
                                    <td><?php echo formatRupiah($transaksi['jumlah_setoran']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($transaksi['validasi_at'])); ?></td>
                                    <td>
                                        <div style="max-width: 250px; word-wrap: break-word;">
                                            <?php 
                                            // Extract alasan from catatan_validasi
                                            $catatan = $transaksi['catatan_validasi'];
                                            if (strpos($catatan, 'DIKEMBALIKAN KE CS - Alasan: ') !== false) {
                                                $alasan = str_replace('DIKEMBALIKAN KE CS - Alasan: ', '', $catatan);
                                                echo '<span style="color: var(--danger-color); font-weight: 600;">' . htmlspecialchars($alasan) . '</span>';
                                            } else {
                                                echo htmlspecialchars($catatan ?: 'Tidak ada catatan');
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    <td><?php echo getStatusBadge($transaksi['deposit_status']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
            
            <?php else: ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> 
                Tidak ada transaksi yang dikembalikan saat ini. Semua transaksi sudah diproses dengan baik.
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
                                    <td class="<?php echo $trans['jumlah_setoran'] < 0 ? 'closing-amount negative' : ($trans['jumlah_setoran'] > 0 ? 'closing-amount positive' : 'closing-amount'); ?>">
                                        <?php echo formatRupiah($trans['jumlah_setoran']); ?>
                                    </td>
                                    <td>
                                        <?php if ($trans['status_setoran']): ?>
                                            <?php echo getStatusSetoranBadge($trans['status_setoran']); ?>
                                        <?php else: ?>
                                            <?php echo getStatusBadge($trans['deposit_status']); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php if ($total_pages > 1): ?>
            <!-- Pagination -->
            <div style="margin-top: 20px; text-align: center;">
                <div class="pagination-container">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo ($page - 1); ?>" class="btn btn-secondary" style="margin-right: 5px;">
                            <i class="fas fa-chevron-left"></i> Sebelumnya
                        </a>
                    <?php endif; ?>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    
                    if ($start > 1): ?>
                        <a href="?page=1" class="btn btn-secondary" style="margin: 0 2px;">1</a>
                        <?php if ($start > 2): ?>
                            <span style="margin: 0 10px;">...</span>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a href="?page=<?php echo $i; ?>" class="btn <?php echo $i == $page ? 'btn-primary' : 'btn-secondary'; ?>" style="margin: 0 2px;">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($end < $total_pages): ?>
                        <?php if ($end < $total_pages - 1): ?>
                            <span style="margin: 0 10px;">...</span>
                        <?php endif; ?>
                        <a href="?page=<?php echo $total_pages; ?>" class="btn btn-secondary" style="margin: 0 2px;"><?php echo $total_pages; ?></a>
                    <?php endif; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo ($page + 1); ?>" class="btn btn-secondary" style="margin-left: 5px;">
                            Selanjutnya <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <div style="margin-top: 10px; color: var(--text-muted); font-size: 12px;">
                    Menampilkan <?php echo min(($page - 1) * $per_page + 1, $total_disetor); ?> - 
                    <?php echo min($page * $per_page, $total_disetor); ?> dari <?php echo $total_disetor; ?> transaksi
                </div>
            </div>
            <?php endif; ?>
            
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
            <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Tidak ada transaksi yang diserahterimakan ke kasir lain.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Legend Section -->
    <div class="mt-4">
        <h6>Keterangan Status dan Jenis Transaksi:</h6>
        <div class="row">
            <div class="col-md-12">
                <small>
                    <strong>Jenis Transaksi</strong><br>
                    • <span class="jenis-badge jenis-gabungan">Setoran Rill - Pemasukan</span> - Transaksi reguler DIKURANGI pemasukan closing<br><br>
                    
                    <strong>Alur Status Setoran:</strong><br>
                    • <span class="status-badge status-info">Sedang Dibawa Kurir</span> - Setoran sedang dalam perjalanan ke keuangan pusat<br>
                    • <span class="status-badge status-warning">Diterima Staff Keuangan</span> - Staff keuangan sudah menerima setoran<br>
                    • <span class="status-badge status-primary">Validasi Keuangan OK</span> - Validasi fisik selesai tanpa selisih<br>
                    • <span class="status-badge status-danger">Validasi Keuangan SELISIH</span> - Validasi fisik ada selisih<br>
                    • <span class="status-badge status-warning-alt"><i class="fas fa-undo"></i> Dikembalikan ke CS</span> - <strong>BARU:</strong> Setoran dikembalikan untuk diperbaiki, bisa dikirim ulang<br>
                    • <span class="status-badge status-success">Sudah Disetor ke Bank</span> - Sudah disetor ke bank<br>
                    • <span class="status-badge status-secondary">Diserahterimakan</span> - Diserahkan ke kasir lain di cabang yang sama<br><br>
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

    // Function to toggle all dikembalikan checkboxes
    function toggleAllDikembalikan() {
        const masterCheckbox = document.getElementById('selectAllDikembalikan');
        const checkboxes = document.querySelectorAll('.transaksi-checkbox-dikembalikan');
        
        checkboxes.forEach(checkbox => {
            checkbox.checked = masterCheckbox.checked;
        });
    }

    // Prevent form submission if no transactions selected
    document.getElementById('setoranForm')?.addEventListener('submit', function(e) {
        const checkedBoxes = document.querySelectorAll('.transaksi-checkbox:checked');
        if (checkedBoxes.length === 0) {
            e.preventDefault();
            alert('Pilih setidaknya satu transaksi untuk disetor!');
        }
    });

    // Prevent kirim ulang form submission if no transactions selected
    document.getElementById('kirimUlangForm')?.addEventListener('submit', function(e) {
        const checkedBoxes = document.querySelectorAll('.transaksi-checkbox-dikembalikan:checked');
        if (checkedBoxes.length === 0) {
            e.preventDefault();
            alert('Pilih setidaknya satu transaksi untuk dikirim ulang!');
        }
    });
</script>
</body>
</html>