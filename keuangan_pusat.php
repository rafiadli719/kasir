<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'config.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    die("Akses ditolak. Hanya untuk admin dan super_admin.");
}

$is_super_admin = $_SESSION['role'] === 'super_admin';
$is_admin = $_SESSION['role'] === 'admin';
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User';

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Check if user is super admin
if (!isset($_SESSION['kode_karyawan']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../../login_dashboard/login.php');
    exit();
}

$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';

// Get all branches for dropdown
$query_cabang = "SELECT DISTINCT nama_cabang FROM users WHERE nama_cabang IS NOT NULL AND nama_cabang != ''";
$result_cabang = $pdo->query($query_cabang);
$branches = $result_cabang->fetchAll(PDO::FETCH_ASSOC);

// Get master akun for pemasukan
$query_pemasukan = "SELECT * FROM master_akun WHERE jenis_akun = 'pemasukan'";
$result_pemasukan = $pdo->query($query_pemasukan);
$master_pemasukan = $result_pemasukan->fetchAll(PDO::FETCH_ASSOC);

// Get master akun for pengeluaran
$query_pengeluaran = "SELECT *, require_umur_pakai, min_umur_pakai FROM master_akun WHERE jenis_akun = 'pengeluaran'";
$result_pengeluaran = $pdo->query($query_pengeluaran);
$master_pengeluaran = $result_pengeluaran->fetchAll(PDO::FETCH_ASSOC);

// Retrieve master nama transaksi for pemasukan
$query_nama_transaksi_pemasukan = "SELECT mnt.*, ma.arti 
                        FROM master_nama_transaksi mnt 
                        JOIN master_akun ma ON mnt.kode_akun = ma.kode_akun 
                        WHERE mnt.status = 'active' AND ma.jenis_akun = 'pemasukan'
                        ORDER BY mnt.nama_transaksi";
$result_nama_transaksi_pemasukan = $pdo->query($query_nama_transaksi_pemasukan);
$master_nama_transaksi_pemasukan = $result_nama_transaksi_pemasukan->fetchAll(PDO::FETCH_ASSOC);

// Retrieve master nama transaksi for pengeluaran
$query_nama_transaksi_pengeluaran = "SELECT mnt.*, ma.arti 
                        FROM master_nama_transaksi mnt 
                        JOIN master_akun ma ON mnt.kode_akun = ma.kode_akun 
                        WHERE mnt.status = 'active' AND ma.jenis_akun = 'pengeluaran'
                        ORDER BY mnt.nama_transaksi";
$result_nama_transaksi_pengeluaran = $pdo->query($query_nama_transaksi_pengeluaran);
$master_nama_transaksi_pengeluaran = $result_nama_transaksi_pengeluaran->fetchAll(PDO::FETCH_ASSOC);

// Function to generate transaction code
function generateTransactionCode($pdo, $kode_karyawan, $jenis) {
    // Get kode_user from users table
    $query_user = "SELECT kode_user FROM users WHERE kode_karyawan = ?";
    $stmt_user = $pdo->prepare($query_user);
    $stmt_user->execute([$kode_karyawan]);
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_data || !$user_data['kode_user']) {
        throw new Exception("Kode user tidak ditemukan untuk karyawan: " . $kode_karyawan);
    }
    
    $kode_user = $user_data['kode_user'];
    $date = date('Ymd');
    
    // Different prefix for pemasukan and pengeluaran
    if ($jenis === 'pemasukan') {
        $prefix = "PMK-{$date}-{$kode_user}";
        $table = 'pemasukan_pusat';
    } else {
        $prefix = "PST-{$date}-{$kode_user}";
        $table = 'pengeluaran_pusat';
    }
    
    // Get the latest running number for today and this user
    if ($table === 'pemasukan_pusat') {
        // Use id for pemasukan_pusat since kode_transaksi doesn't exist
        $query = "SELECT COUNT(*) as count FROM {$table} 
                  WHERE DATE(tanggal) = CURDATE()";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
    } else {
        // Use kode_transaksi for pengeluaran_pusat
        $query = "SELECT COUNT(*) as count FROM {$table} 
                  WHERE kode_transaksi LIKE ? AND DATE(tanggal) = CURDATE()";
        $stmt = $pdo->prepare($query);
        $stmt->execute(["{$prefix}%"]);
    }
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $running_number = str_pad($result['count'] + 1, 3, '0', STR_PAD_LEFT);
    return "{$prefix}{$running_number}";
}

// Generate transaction code for display
$kode_transaksi_pemasukan = generateTransactionCode($pdo, $kode_karyawan, 'pemasukan');
$kode_transaksi_pengeluaran = generateTransactionCode($pdo, $kode_karyawan, 'pengeluaran');

// Get nama karyawan and nama cabang
$sql_nama_karyawan = "SELECT nama_karyawan, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan";
$stmt_nama_karyawan = $pdo->prepare($sql_nama_karyawan);
$stmt_nama_karyawan->bindParam(':kode_karyawan', $kode_karyawan);
$stmt_nama_karyawan->execute();
$nama_karyawan_data = $stmt_nama_karyawan->fetch(PDO::FETCH_ASSOC);

$nama_karyawan = $nama_karyawan_data['nama_karyawan'] ?? 'Tidak diketahui';
$nama_cabang = $nama_karyawan_data['nama_cabang'] ?? 'Tidak diketahui';
$karyawan_info = $kode_karyawan . ' - ' . $nama_karyawan;

// Handle form submissions
$success_message = '';
$error_message = '';

// Handle filter for history tab
$filter_tanggal_awal = $_GET['filter_tanggal_awal'] ?? null;
$filter_tanggal_akhir = $_GET['filter_tanggal_akhir'] ?? null;
$filter_cabang = $_GET['filter_cabang'] ?? null;
$filter_kategori = $_GET['filter_kategori'] ?? null;
$filter_jenis = $_GET['filter_jenis'] ?? null;
$current_tab = $_GET['tab'] ?? 'input';
$current_sub_tab = $_GET['sub_tab'] ?? 'pemasukan';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Use user-selected date or current date as fallback
    $tanggal = !empty($_POST['tanggal']) ? $_POST['tanggal'] : date('Y-m-d');
    $waktu = date('H:i:s');
    $cabang = $_POST['cabang'];
    $kode_akun = $_POST['kode_akun'];
    $jumlah = $_POST['jumlah'];
    $keterangan = $_POST['keterangan'];
    
    // Handle umur_pakai dengan default value
    $umur_pakai = isset($_POST['umur_pakai']) && !empty($_POST['umur_pakai']) ? intval($_POST['umur_pakai']) : 0;
    
    // Validate inputs
    if (!is_numeric($jumlah) || $jumlah <= 0) {
        $error_message = "Jumlah harus berupa angka positif!";
    } elseif (empty($tanggal)) {
        $error_message = "Tanggal harus diisi!";
    } elseif (empty(trim($keterangan))) {
        $error_message = "Keterangan transaksi wajib diisi!";
    } else {
        // Validate date format
        $date_check = DateTime::createFromFormat('Y-m-d', $tanggal);
        if (!$date_check || $date_check->format('Y-m-d') !== $tanggal) {
            $error_message = "Format tanggal tidak valid!";
        } else {
            if (isset($_POST['submit_pemasukan'])) {
                // Generate transaction code for pemasukan
                $kode_transaksi = generateTransactionCode($pdo, $kode_karyawan, 'pemasukan');
                
                // Insert pemasukan pusat
                $query = "INSERT INTO pemasukan_pusat (kode_transaksi, kode_karyawan, cabang, kode_akun, jumlah, keterangan, tanggal, waktu) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($query);
                
                if ($stmt->execute([$kode_transaksi, $kode_karyawan, $cabang, $kode_akun, $jumlah, $keterangan, $tanggal, $waktu])) {
                    $success_message = "Pemasukan berhasil ditambahkan ke cabang " . htmlspecialchars($cabang) . " pada tanggal " . date('d/m/Y', strtotime($tanggal)) . " dengan kode transaksi: " . $kode_transaksi;
                } else {
                    $error_message = "Gagal menambahkan pemasukan: " . implode(", ", $stmt->errorInfo());
                }
                
            } elseif (isset($_POST['submit_pengeluaran'])) {
                // Validasi umur pakai untuk kode akun yang memerlukan
                $query_validasi_umur = "SELECT require_umur_pakai, min_umur_pakai, kategori FROM master_akun WHERE kode_akun = ?";
                $stmt_validasi_umur = $pdo->prepare($query_validasi_umur);
                $stmt_validasi_umur->execute([$kode_akun]);
                $validasi_umur_data = $stmt_validasi_umur->fetch(PDO::FETCH_ASSOC);
                
                if ($validasi_umur_data && $validasi_umur_data['require_umur_pakai'] == 1) {
                    if ($umur_pakai < $validasi_umur_data['min_umur_pakai']) {
                        $error_message = "Umur pakai minimal " . $validasi_umur_data['min_umur_pakai'] . " bulan untuk kode akun ini!";
                    } else {
                        // Generate transaction code
                        $kode_transaksi = generateTransactionCode($pdo, $kode_karyawan, 'pengeluaran');
                        
                        $kategori = $validasi_umur_data['kategori'];
                        
                        // Insert pengeluaran pusat with transaction code
                        $query = "INSERT INTO pengeluaran_pusat (kode_transaksi, kode_karyawan, cabang, kode_akun, jumlah, keterangan, umur_pakai, kategori, tanggal, waktu) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $pdo->prepare($query);
                        
                        if ($stmt->execute([$kode_transaksi, $kode_karyawan, $cabang, $kode_akun, $jumlah, $keterangan, $umur_pakai, $kategori, $tanggal, $waktu])) {
                            $success_message = "Pengeluaran berhasil ditambahkan ke cabang " . htmlspecialchars($cabang) . " pada tanggal " . date('d/m/Y', strtotime($tanggal)) . " dengan kode transaksi: " . $kode_transaksi;
                        } else {
                            $error_message = "Gagal menambahkan pengeluaran: " . implode(", ", $stmt->errorInfo());
                        }
                    }
                } else {
                    // Generate transaction code
                    $kode_transaksi = generateTransactionCode($pdo, $kode_karyawan, 'pengeluaran');
                    
                    $kategori = $validasi_umur_data['kategori'] ?? '';
                    
                    // Insert pengeluaran pusat with transaction code
                    $query = "INSERT INTO pengeluaran_pusat (kode_transaksi, kode_karyawan, cabang, kode_akun, jumlah, keterangan, umur_pakai, kategori, tanggal, waktu) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($query);
                    
                    if ($stmt->execute([$kode_transaksi, $kode_karyawan, $cabang, $kode_akun, $jumlah, $keterangan, $umur_pakai, $kategori, $tanggal, $waktu])) {
                        $success_message = "Pengeluaran berhasil ditambahkan ke cabang " . htmlspecialchars($cabang) . " pada tanggal " . date('d/m/Y', strtotime($tanggal)) . " dengan kode transaksi: " . $kode_transaksi;
                    } else {
                        $error_message = "Gagal menambahkan pengeluaran: " . implode(", ", $stmt->errorInfo());
                    }
                }
            }
        }
    }
}

// Get recent transactions for display with filters
if ($current_tab === 'riwayat') {
    $query_recent = "";
    $params = [];
    
    if ($filter_jenis === 'pemasukan' || !$filter_jenis) {
        $query_pemasukan = "SELECT 
                              pp.id,
                              pp.kode_transaksi,
                              'pemasukan' as jenis,
                              pp.kode_karyawan,
                              u.nama_karyawan, 
                              pp.cabang,
                              pp.kode_akun,
                              ma.arti as nama_akun,
                              pp.jumlah,
                              pp.keterangan,
                              NULL as umur_pakai,
                              NULL as kategori,
                              pp.tanggal,
                              pp.waktu
                            FROM pemasukan_pusat pp 
                            JOIN users u ON pp.kode_karyawan = u.kode_karyawan 
                            LEFT JOIN master_akun ma ON pp.kode_akun = ma.kode_akun 
                            WHERE 1=1";
        
        if ($filter_tanggal_awal && $filter_tanggal_akhir) {
            $query_pemasukan .= " AND pp.tanggal BETWEEN ? AND ?";
            $params = array_merge($params, [$filter_tanggal_awal, $filter_tanggal_akhir]);
        }
        if ($filter_cabang) {
            $query_pemasukan .= " AND pp.cabang = ?";
            $params[] = $filter_cabang;
        }
    }
    
    if ($filter_jenis === 'pengeluaran' || !$filter_jenis) {
        $query_pengeluaran = "SELECT 
                                pp.id,
                                pp.kode_transaksi,
                                'pengeluaran' as jenis,
                                pp.kode_karyawan,
                                u.nama_karyawan, 
                                pp.cabang,
                                pp.kode_akun,
                                ma.arti as nama_akun,
                                pp.jumlah,
                                pp.keterangan,
                                pp.umur_pakai,
                                pp.kategori,
                                pp.tanggal,
                                pp.waktu
                              FROM pengeluaran_pusat pp 
                              JOIN users u ON pp.kode_karyawan = u.kode_karyawan 
                              LEFT JOIN master_akun ma ON pp.kode_akun = ma.kode_akun 
                              WHERE 1=1";
        
        if ($filter_tanggal_awal && $filter_tanggal_akhir) {
            $query_pengeluaran .= " AND pp.tanggal BETWEEN ? AND ?";
            if (!isset($query_pemasukan)) $params = array_merge($params, [$filter_tanggal_awal, $filter_tanggal_akhir]);
        }
        if ($filter_cabang) {
            $query_pengeluaran .= " AND pp.cabang = ?";
            if (!isset($query_pemasukan)) $params[] = $filter_cabang;
        }
        if ($filter_kategori) {
            $query_pengeluaran .= " AND pp.kategori = ?";
            $params[] = $filter_kategori;
        }
    }
    
    // Combine queries
    if ($filter_jenis === 'pemasukan') {
        $query_recent = $query_pemasukan;
    } elseif ($filter_jenis === 'pengeluaran') {
        $query_recent = $query_pengeluaran;
    } else {
        $query_recent = "({$query_pemasukan}) UNION ALL ({$query_pengeluaran})";
        // Duplicate params for both queries in UNION
        $params = array_merge($params, $params);
    }
    
    $query_recent .= " ORDER BY tanggal DESC, waktu DESC";
    
    // Limit results if no filters applied (for performance)
    if (!$filter_tanggal_awal && !$filter_tanggal_akhir && !$filter_cabang && !$filter_kategori && !$filter_jenis) {
        $query_recent .= " LIMIT 50";
    }
    
    $stmt_recent = $pdo->prepare($query_recent);
    $stmt_recent->execute($params);
    $recent_transactions = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate statistics for filtered data
    $total_records = count($recent_transactions);
    $total_pemasukan = 0;
    $total_pengeluaran = 0;
    
    foreach ($recent_transactions as $transaction) {
        if ($transaction['jenis'] === 'pemasukan') {
            $total_pemasukan += $transaction['jumlah'];
        } else {
            $total_pengeluaran += $transaction['jumlah'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keuangan Pusat - Super Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="includes/sidebar.css" rel="stylesheet">
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
        .welcome-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .welcome-card h1 {
            font-size: 24px;
            margin-bottom: 15px;
            color: var(--text-dark);
        }
        .info-tags {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        .info-tag {
            background: var(--background-light);
            padding: 8px 12px;
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-dark);
        }
        .form-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .form-card h3 {
            margin-bottom: 20px;
            color: var(--text-dark);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 24px;
        }
        .form-label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-left: 10px;
            font-style: italic;
        }
        .form-control, .form-select {
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        .form-control[readonly] {
            background: var(--background-light);
            color: var(--text-muted);
        }
        .form-control:disabled {
            background: var(--background-light);
            color: var(--text-muted);
            cursor: not-allowed;
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
            border: 1px solid transparent;
            justify-content: center;
        }
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }
        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #20c997);
            color: white;
            box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
        }
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }
        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #c82333);
            color: white;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
        }
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }
        .btn-info {
            background-color: var(--info-color);
            color: white;
        }
        .btn-info:hover {
            background-color: #138496;
        }
        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #e0a800);
            color: white;
        }
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.3);
        }
        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
        }
        .btn-secondary:hover {
            background-color: #545b62;
        }
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid transparent;
        }
        .alert-success {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
            border-color: rgba(40,167,69,0.2);
        }
        .alert-danger {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
            border-color: rgba(220,53,69,0.2);
        }
        .alert-info {
            background: rgba(23,162,184,0.1);
            color: var(--info-color);
            border-color: rgba(23,162,184,0.2);
        }
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .table-header {
            background: var(--background-light);
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
        }
        .table-header h4 {
            margin: 0;
            color: var(--text-dark);
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th {
            background: var(--background-light);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 14px;
            border-bottom: 1px solid var(--border-color);
        }
        .table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        .table tbody tr:hover {
            background: var(--background-light);
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .action-buttons .btn {
            padding: 6px 10px;
            font-size: 12px;
        }
        .text-required {
            color: var(--danger-color);
        }
        .form-text {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .search-container {
            position: relative;
        }
        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .search-result-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s ease;
        }
        .search-result-item:hover {
            background: var(--background-light);
        }
        .search-result-item:last-child {
            border-bottom: none;
        }
        .search-result-item .nama {
            font-weight: 600;
            color: var(--text-dark);
        }
        .search-result-item .kode {
            font-size: 12px;
            color: var(--text-muted);
        }
        .search-no-results {
            padding: 12px 16px;
            text-align: center;
            color: var(--text-muted);
            font-style: italic;
        }
        .search-input-wrapper {
            position: relative;
        }
        .search-input-wrapper .form-control {
            padding-right: 40px;
        }
        .search-clear-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        .search-clear-btn:hover {
            background: var(--background-light);
            color: var(--text-dark);
        }
        .search-input-locked {
            background: rgba(0,123,255,0.05) !important;
            border-color: var(--primary-color) !important;
        }
        .search-input-invalid {
            background: rgba(220,53,69,0.05) !important;
            border-color: var(--danger-color) !important;
            color: var(--danger-color) !important;
        }
        
        /* Validation icon styles - same as cashier pages */
        .form-control.valid {
            border-color: var(--success-color);
            background-color: rgba(40, 167, 69, 0.05);
        }
        .form-control.invalid {
            border-color: var(--danger-color);
            background-color: rgba(220, 53, 69, 0.05);
        }
        .form-control.empty {
            border-color: var(--border-color);
            background-color: white;
        }
        .validation-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            display: none;
        }
        .validation-icon.valid {
            color: var(--success-color);
            display: block;
        }
        .validation-icon.invalid {
            color: var(--danger-color);
            display: block;
        }
        .validation-icon.empty {
            display: none;
        }
        .input-group {
            position: relative;
        }
        .search-result-item.highlight {
            background: var(--primary-color);
            color: white;
        }
        .search-result-item.highlight .nama,
        .search-result-item.highlight .kode {
            color: white;
        }
        .no-results {
            padding: 16px;
            text-align: center;
            color: var(--text-muted);
            font-style: italic;
        }
        .sub-tab-navigation {
            display: flex;
            gap: 10px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .sub-tab-navigation .btn {
            margin-right: 0;
        }
        .jenis-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .jenis-pemasukan {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
        }
        .jenis-pengeluaran {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
        }
        .kategori-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .kategori-biaya {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
        }
        .kategori-non_biaya {
            background: rgba(23,162,184,0.1);
            color: var(--info-color);
        }
        .amount-cell {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            text-align: right;
        }
        .amount-pemasukan {
            color: var(--success-color);
        }
        .amount-pengeluaran {
            color: var(--danger-color);
        }
        .transaction-code {
            background: var(--background-light);
            padding: 8px 16px;
            border-radius: 12px;
            display: inline-block;
            margin-top: 12px;
            font-weight: 600;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .info-card .label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 4px;
            text-transform: uppercase;
        }
        .info-card .value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-dark);
        }
        .required {
            color: var(--danger-color);
        }
        
        /* Style untuk keterangan default */
        .keterangan-default {
            background: rgba(0,123,255,0.05);
            border: 1px solid rgba(0,123,255,0.2);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 8px;
            font-size: 13px;
            color: var(--primary-color);
            display: none;
        }
        .keterangan-default.show {
            display: block;
        }
        .keterangan-default strong {
            color: var(--text-dark);
        }
        
        @media (max-width: 768px) {
            .sidebar.active {
                transform: translateX(0);
            }
            .form-grid, .form-row, .form-row-3 {
                grid-template-columns: 1fr;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
            .info-tags {
                flex-direction: column;
                gap: 10px;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
            .action-buttons {
                flex-direction: column;
            }
            .table {
                font-size: 12px;
            }
            .table th,
            .table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="user-profile">
        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
        <div>
            <strong><?php echo htmlspecialchars($username); ?></strong>
            <p style="color: var(--text-muted); font-size: 12px;">Super Admin</p>
        </div>
    </div>

    <div class="welcome-card">
        <h1><i class="fas fa-wallet"></i> Manajemen Keuangan Pusat</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Kelola pemasukan dan pengeluaran dari berbagai cabang secara terpusat</p>
        <div class="info-tags">
            <div class="info-tag"><i class="fas fa-user"></i> User: <?php echo htmlspecialchars($username); ?></div>
            <div class="info-tag"><i class="fas fa-shield-alt"></i> Role: Super Admin</div>
            <div class="info-tag"><i class="fas fa-calendar-day"></i> Tanggal: <?php echo date('d M Y H:i:s'); ?></div>
        </div>
    </div>

    <!-- Info Cards -->
    <div class="info-grid">
        <div class="info-card">
            <div class="label">Karyawan</div>
            <div class="value"><?php echo htmlspecialchars($karyawan_info); ?></div>
        </div>
        <div class="info-card">
            <div class="label">Cabang</div>
            <div class="value"><?php echo htmlspecialchars($nama_cabang); ?></div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> 
        <strong>Info:</strong> Ketik untuk mencari nama transaksi dengan cepat. Hanya nama transaksi yang tersedia di sistem yang dapat dipilih. Input akan berubah warna biru saat transaksi valid dipilih. Sistem akan otomatis menggenerate kode transaksi dengan format PMK-yyyymmdd-kode_user+running_number untuk pemasukan dan PST-yyyymmdd-kode_user+running_number untuk pengeluaran.
    </div>

    <!-- Tab Navigation -->
    <div style="margin-bottom: 24px;">
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="?tab=input" class="btn <?php echo $current_tab === 'input' ? 'btn-primary' : 'btn-secondary'; ?>">
                <i class="fas fa-plus-circle"></i> Input Transaksi
            </a>
            <a href="?tab=riwayat" class="btn <?php echo $current_tab === 'riwayat' ? 'btn-primary' : 'btn-secondary'; ?>">
                <i class="fas fa-history"></i> Riwayat Transaksi
            </a>
        </div>
    </div>

    <!-- Input Tab Content -->
    <?php if ($current_tab === 'input'): ?>

    <!-- Sub Tab Navigation for Input -->
    <div class="sub-tab-navigation">
        <a href="?tab=input&sub_tab=pemasukan" class="btn <?php echo $current_sub_tab === 'pemasukan' ? 'btn-success' : 'btn-secondary'; ?>">
            <i class="fas fa-arrow-up"></i> Input Pemasukan
        </a>
        <a href="?tab=input&sub_tab=pengeluaran" class="btn <?php echo $current_sub_tab === 'pengeluaran' ? 'btn-danger' : 'btn-secondary'; ?>">
            <i class="fas fa-arrow-down"></i> Input Pengeluaran
        </a>
    </div>

    <!-- Pemasukan Form -->
    <?php if ($current_sub_tab === 'pemasukan'): ?>
    <div class="form-section">
        <h3 style="margin-bottom: 20px; color: var(--text-dark);"><i class="fas fa-edit"></i> Form Input Pemasukan</h3>
        <div class="transaction-code">
            <i class="fas fa-receipt"></i> <?php echo htmlspecialchars($kode_transaksi_pemasukan); ?>
        </div>
        <form method="POST" action="" id="pemasukanForm">
            <!-- Nama Transaksi dengan Type to Find untuk Pemasukan - Full Width -->
            <div class="form-group">
                <label for="nama_transaksi_pemasukan" class="form-label">
                    <i class="fas fa-search"></i> Nama Transaksi <span class="required">*</span>
                    <span class="form-hint">Ketik untuk mencari - hanya bisa memilih dari daftar yang tersedia</span>
                </label>
                <div class="search-container">
                    <div class="search-input-wrapper">
                        <div class="input-group">
                            <input type="text" id="nama_transaksi_pemasukan" name="nama_transaksi" class="form-control empty" 
                                   placeholder="Klik untuk mencari transaksi pemasukan..." 
                                   autocomplete="off" readonly required>
                            <div class="validation-icon empty" id="validation_icon_pemasukan">
                                <i class="fas fa-question-circle"></i>
                            </div>
                        </div>
                        <button type="button" class="search-clear-btn" id="clear_pemasukan" title="Hapus pencarian">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="search-results" id="search_results_pemasukan">
                        <!-- Results will be populated by JavaScript -->
                    </div>
                </div>
            </div>

            <!-- Kode Akun dan Cabang Bersebelahan -->
            <div class="form-row">
                <div class="form-group">
                    <label for="kode_akun_pemasukan" class="form-label">
                        <i class="fas fa-code"></i> Kode Akun <span class="text-required">*</span>
                        <span class="form-hint">Otomatis terisi sesuai dengan nama transaksi yang dipilih.</span>
                    </label>
                    <select name="kode_akun" id="kode_akun_pemasukan" class="form-select" readonly style="pointer-events: none; background: var(--background-light); cursor: not-allowed;" required>
                        <option value="">-- Otomatis terisi berdasarkan nama transaksi --</option>
                        <?php foreach ($master_pemasukan as $akun): ?>
                            <option value="<?php echo $akun['kode_akun']; ?>">
                                <?php echo $akun['kode_akun'] . ' - ' . $akun['arti']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="cabang_pemasukan" class="form-label">
                        <i class="fas fa-building"></i> Pilih Cabang <span class="text-required">*</span>
                    </label>
                    <select name="cabang" id="cabang_pemasukan" class="form-select" required>
                        <option value="">-- Pilih Cabang --</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo htmlspecialchars($branch['nama_cabang']); ?>">
                                <?php echo htmlspecialchars(ucfirst($branch['nama_cabang'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Keterangan Transaksi -->
            <div class="form-group">
                <label for="keterangan_pemasukan" class="form-label">
                    <i class="fas fa-sticky-note"></i> Keterangan Transaksi <span class="text-required">*</span>
                </label>
                <!-- Tampilkan keterangan default di atas input tanpa auto-fill -->
                <div class="keterangan-default" id="keterangan_default_pemasukan">
                    <strong>Keterangan Default:</strong> <span id="default_text_pemasukan">-</span>
                </div>
                <input type="text" name="keterangan" id="keterangan_pemasukan" class="form-control" 
                       oninput="this.value = this.value.toUpperCase()" required placeholder="Masukkan keterangan">
            </div>

            <!-- Jumlah dan Tanggal Bersebelahan -->
            <div class="form-row">
                <div class="form-group">
                    <label for="jumlah_pemasukan" class="form-label">
                        <i class="fas fa-money-bill-wave"></i> Jumlah (Rp) <span class="text-required">*</span>
                    </label>
                    <input type="number" name="jumlah" id="jumlah_pemasukan" class="form-control" 
                           step="1" min="1" required placeholder="Masukkan jumlah">
                </div>

                <div class="form-group">
                    <label for="tanggal_pemasukan" class="form-label">
                        <i class="fas fa-calendar"></i> Tanggal Transaksi <span class="text-required">*</span>
                    </label>
                    <input type="date" name="tanggal" id="tanggal_pemasukan" class="form-control" 
                           value="<?php echo date('Y-m-d'); ?>" required>
                    <div class="form-text">Default: hari ini. Dapat diubah sesuai kebutuhan.</div>
                </div>
            </div>

            <button type="submit" name="submit_pemasukan" class="btn btn-success" style="width: 100%;">
                <i class="fas fa-save"></i> Tambah Pemasukan
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Pengeluaran Form -->
    <?php if ($current_sub_tab === 'pengeluaran'): ?>
    <div class="form-section">
        <h3 style="margin-bottom: 20px; color: var(--text-dark);"><i class="fas fa-edit"></i> Form Input Pengeluaran</h3>
        <div class="transaction-code">
            <i class="fas fa-receipt"></i> <?php echo htmlspecialchars($kode_transaksi_pengeluaran); ?>
        </div>
        <form method="POST" action="" id="pengeluaranForm">
            <!-- Nama Transaksi dengan Type to Find untuk Pengeluaran - Full Width -->
            <div class="form-group">
                <label for="nama_transaksi_pengeluaran" class="form-label">
                    <i class="fas fa-search"></i> Nama Transaksi <span class="required">*</span>
                    <span class="form-hint">Ketik untuk mencari - hanya bisa memilih dari daftar yang tersedia</span>
                </label>
                <div class="search-container">
                    <div class="search-input-wrapper">
                        <div class="input-group">
                            <input type="text" id="nama_transaksi_pengeluaran" name="nama_transaksi" class="form-control empty" 
                                   placeholder="Klik untuk mencari transaksi pengeluaran..." 
                                   autocomplete="off" readonly required>
                            <div class="validation-icon empty" id="validation_icon_pengeluaran">
                                <i class="fas fa-question-circle"></i>
                            </div>
                        </div>
                        <button type="button" class="search-clear-btn" id="clear_pengeluaran" title="Hapus pencarian">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="search-results" id="search_results_pengeluaran">
                        <!-- Results will be populated by JavaScript -->
                    </div>
                </div>
            </div>

            <!-- Kode Akun dan Kategori Akun Bersebelahan -->
            <div class="form-row">
                <div class="form-group">
                    <label for="kode_akun_pengeluaran" class="form-label">
                        <i class="fas fa-code"></i> Kode Akun <span class="text-required">*</span>
                        <span class="form-hint">Otomatis terisi sesuai dengan nama transaksi yang dipilih.</span>
                    </label>
                    <select name="kode_akun" id="kode_akun_pengeluaran" class="form-select" readonly style="pointer-events: none; background: var(--background-light); cursor: not-allowed;" required>
                        <option value="">-- Otomatis terisi berdasarkan nama transaksi --</option>
                        <?php foreach ($master_pengeluaran as $akun): ?>
                            <option value="<?php echo $akun['kode_akun']; ?>" 
                                    data-kategori="<?php echo htmlspecialchars($akun['kategori'] ?? ''); ?>"
                                    data-require-umur="<?php echo $akun['require_umur_pakai'] ?? 0; ?>"
                                    data-min-umur="<?php echo $akun['min_umur_pakai'] ?? 0; ?>">
                                <?php echo $akun['kode_akun'] . ' - ' . $akun['arti']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="kategori_pengeluaran" class="form-label">
                        <i class="fas fa-tags"></i> Kategori Akun
                        <span class="form-hint">Otomatis terisi berdasarkan kode akun yang dipilih.</span>
                    </label>
                    <input type="text" name="kategori" id="kategori_pengeluaran" class="form-control" readonly>
                </div>
            </div>

            <!-- Keterangan Transaksi -->
            <div class="form-group">
                <label for="keterangan_pengeluaran" class="form-label">
                    <i class="fas fa-sticky-note"></i> Keterangan Transaksi <span class="text-required">*</span>
                </label>
                <!-- Tampilkan keterangan default di atas input tanpa auto-fill -->
                <div class="keterangan-default" id="keterangan_default_pengeluaran">
                    <strong>Keterangan Default:</strong> <span id="default_text_pengeluaran">-</span>
                </div>
                <input type="text" name="keterangan" id="keterangan_pengeluaran" class="form-control" 
                       oninput="this.value = this.value.toUpperCase()" required placeholder="Masukkan keterangan">
            </div>

            <!-- Jumlah, Umur Pakai, dan Tanggal Bersebelahan -->
            <div class="form-row-3">
                <div class="form-group">
                    <label for="jumlah_pengeluaran" class="form-label">
                        <i class="fas fa-money-bill-wave"></i> Jumlah (Rp) <span class="text-required">*</span>
                    </label>
                    <input type="number" name="jumlah" id="jumlah_pengeluaran" class="form-control" 
                           step="1" min="1" required placeholder="Masukkan jumlah">
                </div>

                <div class="form-group">
                    <label for="umur_pakai" class="form-label">
                        <i class="fas fa-calendar-alt"></i> Umur Pakai (Bulan)
                        <span class="form-hint">Akan aktif jika kode akun memerlukan umur pakai.</span>
                    </label>
                    <input type="number" name="umur_pakai" id="umur_pakai" class="form-control" 
                           min="0" value="0" placeholder="Masukkan umur pakai" disabled>
                </div>

                <div class="form-group">
                    <label for="tanggal_pengeluaran" class="form-label">
                        <i class="fas fa-calendar"></i> Tanggal Transaksi <span class="text-required">*</span>
                    </label>
                    <input type="date" name="tanggal" id="tanggal_pengeluaran" class="form-control" 
                           value="<?php echo date('Y-m-d'); ?>" required>
                    <div class="form-text">Default: hari ini. Dapat diubah sesuai kebutuhan.</div>
                </div>
            </div>

            <!-- Cabang -->
            <div class="form-group">
                <label for="cabang_pengeluaran" class="form-label">
                    <i class="fas fa-building"></i> Pilih Cabang <span class="text-required">*</span>
                </label>
                <select name="cabang" id="cabang_pengeluaran" class="form-select" required>
                    <option value="">-- Pilih Cabang --</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo htmlspecialchars($branch['nama_cabang']); ?>">
                            <?php echo htmlspecialchars(ucfirst($branch['nama_cabang'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" name="submit_pengeluaran" class="btn btn-danger" style="width: 100%;">
                <i class="fas fa-save"></i> Tambah Pengeluaran
            </button>
        </form>
    </div>
    <?php endif; ?>

    <?php endif; ?>

    <!-- Riwayat Tab Content -->
    <?php if ($current_tab === 'riwayat'): ?>
    
    <!-- Filter Card for History -->
    <div class="form-card">
        <h3><i class="fas fa-filter"></i> Filter Pencarian Riwayat</h3>
        <form method="GET" action="">
            <input type="hidden" name="tab" value="riwayat">
            <div class="form-grid">
                <div class="form-group">
                    <label for="filter_tanggal_awal" class="form-label">
                        <i class="fas fa-calendar-alt"></i> Tanggal Awal
                    </label>
                    <input type="date" 
                           name="filter_tanggal_awal" 
                           id="filter_tanggal_awal" 
                           value="<?php echo htmlspecialchars($filter_tanggal_awal ?? '', ENT_QUOTES); ?>" 
                           class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="filter_tanggal_akhir" class="form-label">
                        <i class="fas fa-calendar-alt"></i> Tanggal Akhir
                    </label>
                    <input type="date" 
                           name="filter_tanggal_akhir" 
                           id="filter_tanggal_akhir" 
                           value="<?php echo htmlspecialchars($filter_tanggal_akhir ?? '', ENT_QUOTES); ?>" 
                           class="form-control">
                </div>
                
                <div class="form-group">
                    <label for="filter_cabang" class="form-label">
                        <i class="fas fa-building"></i> Cabang
                    </label>
                    <select name="filter_cabang" id="filter_cabang" class="form-control">
                        <option value="">-- Semua Cabang --</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo htmlspecialchars($branch['nama_cabang']); ?>" 
                                <?php echo $filter_cabang === $branch['nama_cabang'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($branch['nama_cabang'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="filter_jenis" class="form-label">
                        <i class="fas fa-exchange-alt"></i> Jenis Transaksi
                    </label>
                    <select name="filter_jenis" id="filter_jenis" class="form-control">
                        <option value="">-- Semua Jenis --</option>
                        <option value="pemasukan" <?php echo $filter_jenis === 'pemasukan' ? 'selected' : ''; ?>>Pemasukan</option>
                        <option value="pengeluaran" <?php echo $filter_jenis === 'pengeluaran' ? 'selected' : ''; ?>>Pengeluaran</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="filter_kategori" class="form-label">
                        <i class="fas fa-tags"></i> Kategori (untuk Pengeluaran)
                    </label>
                    <select name="filter_kategori" id="filter_kategori" class="form-control">
                        <option value="">-- Semua Kategori --</option>
                        <option value="biaya" <?php echo $filter_kategori === 'biaya' ? 'selected' : ''; ?>>Biaya</option>
                        <option value="non_biaya" <?php echo $filter_kategori === 'non_biaya' ? 'selected' : ''; ?>>Non-Biaya</option>
                    </select>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Cari Data
                </button>
                <a href="?tab=riwayat" class="btn btn-secondary">
                    <i class="fas fa-refresh"></i> Reset Filter
                </a>
                <?php if (isset($recent_transactions) && count($recent_transactions) > 0): ?>
                    <a href="export_excel.php?jenis_data=pusat&<?php echo http_build_query(array_filter(['filter_tanggal_awal' => $filter_tanggal_awal, 'filter_tanggal_akhir' => $filter_tanggal_akhir, 'filter_cabang' => $filter_cabang, 'filter_kategori' => $filter_kategori, 'filter_jenis' => $filter_jenis])); ?>" 
                       class="btn btn-success">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Statistics Cards -->
    <?php if (isset($recent_transactions) && ($filter_tanggal_awal || $filter_tanggal_akhir || $filter_cabang || $filter_kategori || $filter_jenis)): ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 24px;">
        <div style="background: white; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--border-color); border-left: 4px solid var(--primary-color);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h4 style="margin: 0 0 5px 0; font-size: 14px; color: var(--text-muted); font-weight: 500;">Total Record</h4>
                    <p style="font-size: 20px; font-weight: bold; margin: 0; color: var(--text-dark);"><?php echo number_format($total_records); ?></p>
                </div>
                <div style="font-size: 28px; opacity: 0.7; color: var(--primary-color);">
                    <i class="fas fa-list"></i>
                </div>
            </div>
        </div>
        
        <div style="background: white; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--border-color); border-left: 4px solid var(--success-color);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h4 style="margin: 0 0 5px 0; font-size: 14px; color: var(--text-muted); font-weight: 500;">Total Pemasukan</h4>
                    <p style="font-size: 20px; font-weight: bold; margin: 0; color: var(--text-dark);">Rp <?php echo number_format($total_pemasukan, 0, ',', '.'); ?></p>
                </div>
                <div style="font-size: 28px; opacity: 0.7; color: var(--success-color);">
                    <i class="fas fa-arrow-up"></i>
                </div>
            </div>
        </div>
        
        <div style="background: white; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--border-color); border-left: 4px solid var(--danger-color);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h4 style="margin: 0 0 5px 0; font-size: 14px; color: var(--text-muted); font-weight: 500;">Total Pengeluaran</h4>
                    <p style="font-size: 20px; font-weight: bold; margin: 0; color: var(--text-dark);">Rp <?php echo number_format($total_pengeluaran, 0, ',', '.'); ?></p>
                </div>
                <div style="font-size: 28px; opacity: 0.7; color: var(--danger-color);">
                    <i class="fas fa-arrow-down"></i>
                </div>
            </div>
        </div>
        
        <div style="background: white; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--border-color); border-left: 4px solid var(--info-color);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h4 style="margin: 0 0 5px 0; font-size: 14px; color: var(--text-muted); font-weight: 500;">Selisih</h4>
                    <p style="font-size: 20px; font-weight: bold; margin: 0; color: var(--text-dark);">Rp <?php echo number_format($total_pemasukan - $total_pengeluaran, 0, ',', '.'); ?></p>
                </div>
                <div style="font-size: 28px; opacity: 0.7; color: var(--info-color);">
                    <i class="fas fa-calculator"></i>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Riwayat Transaksi Table -->
    <div class="table-container">
        <div class="table-header">
            <h4><i class="fas fa-history text-primary"></i> Riwayat Transaksi Pusat</h4>
            <?php if (isset($recent_transactions) && count($recent_transactions) > 0): ?>
                <div style="font-size: 14px; color: var(--text-muted);">
                    Menampilkan <?php echo number_format($total_records); ?> data
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (isset($recent_transactions) && count($recent_transactions) > 0): ?>
        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode Transaksi</th>
                        <th>Jenis</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Cabang</th>
                        <th>Akun</th>
                        <th>Kategori</th>
                        <th>Jumlah</th>
                        <th>Keterangan</th>
                        <th>Umur Pakai</th>
                        <th>Input By</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_transactions as $index => $row): ?>
                    <tr>
                        <td><strong><?php echo $index + 1; ?></strong></td>
                        <td>
                            <code style="font-size: 12px;"><?php echo htmlspecialchars($row['kode_transaksi']); ?></code>
                        </td>
                        <td>
                            <span class="jenis-badge jenis-<?php echo htmlspecialchars($row['jenis']); ?>">
                                <?php echo htmlspecialchars(ucfirst($row['jenis'])); ?>
                            </span>
                        </td>
                        <td><?php echo date('d/m/Y', strtotime($row['tanggal'])); ?></td>
                        <td><?php echo htmlspecialchars($row['waktu']); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($row['cabang'])); ?></td>
                        <td>
                            <div style="font-size: 12px;">
                                <strong><?php echo htmlspecialchars($row['kode_akun']); ?></strong><br>
                                <span style="color: var(--text-muted);"><?php echo htmlspecialchars($row['nama_akun'] ?? '-'); ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if ($row['kategori']): ?>
                                <span class="kategori-badge kategori-<?php echo htmlspecialchars($row['kategori']); ?>">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $row['kategori']))); ?>
                                </span>
                            <?php else: ?>
                                <span style="color: var(--text-muted);">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="amount-cell amount-<?php echo $row['jenis']; ?>">
                            Rp <?php echo number_format($row['jumlah'], 0, ',', '.'); ?>
                        </td>
                        <td><?php echo htmlspecialchars($row['keterangan']); ?></td>
                        <td>
                            <?php if ($row['umur_pakai']): ?>
                                <?php echo $row['umur_pakai']; ?> bulan
                            <?php else: ?>
                                <span style="color: var(--text-muted);">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 12px; color: var(--text-muted);">
                            <?php echo htmlspecialchars($row['nama_karyawan']); ?>
                        </td>
                        <td class="action-buttons">
                            <a href="edit_keuangan_pusat.php?id=<?php echo $row['id']; ?>&jenis=<?php echo $row['jenis']; ?>" 
                               class="btn btn-warning" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="hapus_keuangan_pusat.php?id=<?php echo $row['id']; ?>&jenis=<?php echo $row['jenis']; ?>" 
                               class="btn btn-danger" title="Hapus"
                               onclick="return confirm('Yakin ingin menghapus transaksi ini?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <i class="fas fa-search" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i><br>
            <strong>Tidak ada data transaksi</strong><br>
            <?php if (isset($filter_tanggal_awal) && ($filter_tanggal_awal || $filter_tanggal_akhir || $filter_cabang || $filter_kategori || $filter_jenis)): ?>
                untuk filter yang dipilih
            <?php else: ?>
                Belum ada transaksi yang tercatat
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick Links -->
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="detail_pemasukan.php?jenis_data=pusat" class="btn btn-success">
            <i class="fas fa-chart-line"></i> Lihat Detail Pemasukan Lengkap
        </a>
        <a href="detail_pengeluaran.php?jenis_data=pusat" class="btn btn-info">
            <i class="fas fa-chart-line"></i> Lihat Detail Pengeluaran Lengkap
        </a>
    </div>
    <?php endif; ?>
</div>

<script>
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

        const minWidth = 250;
        sidebar.style.width = maxWidth > minWidth ? `${maxWidth + 30}px` : `${minWidth}px`;
        document.querySelector('.main-content').style.marginLeft = sidebar.style.width;
    }

    function setKategoriAkuntype() {
        var kodeAkun = document.getElementById("kode_akun_pengeluaran");
        var kategoriInput = document.getElementById("kategori_pengeluaran");
        var umurPakaiInput = document.getElementById("umur_pakai");
        var selectedOption = kodeAkun.options[kodeAkun.selectedIndex];
        
        // Set kategori
        kategoriInput.value = selectedOption.getAttribute("data-kategori") || '';
        
        // Set umur pakai behavior
        var requireUmur = selectedOption.getAttribute("data-require-umur");
        var minUmur = selectedOption.getAttribute("data-min-umur");
        
        if (requireUmur === "1") {
            umurPakaiInput.disabled = false;
            umurPakaiInput.value = minUmur || 0;
            umurPakaiInput.min = minUmur || 0;
            umurPakaiInput.required = true;
        } else {
            umurPakaiInput.disabled = true;
            umurPakaiInput.value = 0;
            umurPakaiInput.required = false;
        }
    }

    // Master data transaksi untuk auto search
    const masterNamaTransaksiPemasukan = <?php echo json_encode($master_nama_transaksi_pemasukan); ?>;
    const masterNamaTransaksiPengeluaran = <?php echo json_encode($master_nama_transaksi_pengeluaran); ?>;

    let selectedTransaksiPemasukan = null;
    let selectedTransaksiPengeluaran = null;
    
    // Utility function for highlighting text
    function highlightText(text, query) {
        if (!query) return text;
        
        const regex = new RegExp(`(${escapeRegExp(query)})`, 'gi');
        return text.replace(regex, '<mark style="background: yellow; padding: 0;">$1</mark>');
    }
    
    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    // Enhanced search function for Pemasukan with rigid validation like cashier pages
    function setupPemasukanSearch() {
        const input = document.getElementById('nama_transaksi_pemasukan');
        const resultsContainer = document.getElementById('search_results_pemasukan');
        const validationIcon = document.getElementById('validation_icon_pemasukan');
        const clearBtn = document.getElementById('clear_pemasukan');
        
        if (!input || !resultsContainer || !validationIcon) return;
        
        let selectedTransaksi = null;
        let currentHighlightIndex = -1;
        let filteredResults = [];
        let isValidSelection = false;
        
        // RIGID MODE: Click handler untuk aktivasi input (readonly by default)
        input.addEventListener('click', function() {
            if (this.readOnly) {
                this.readOnly = false;
                this.focus();
                this.value = '';
                this.placeholder = "Ketik untuk mencari transaksi pemasukan...";
                updateValidationState('empty');
            }
        });
        
        // Blur handler - kembali ke readonly mode dengan validasi
        input.addEventListener('blur', function(e) {
            setTimeout(() => {
                if (!resultsContainer.matches(':hover')) {
                    validateCurrentInput();
                }
            }, 200);
        });
        
        // Input event listener for real-time search
        input.addEventListener('input', function() {
            const query = this.value.trim();
            currentHighlightIndex = -1;
            
            if (query.length >= 1) {
                filteredResults = masterNamaTransaksiPemasukan.filter(item => 
                    item.nama_transaksi.toLowerCase().includes(query.toLowerCase()) ||
                    item.kode_akun.toLowerCase().includes(query.toLowerCase()) ||
                    item.arti.toLowerCase().includes(query.toLowerCase())
                );
                populateDropdownPemasukan(filteredResults);
                resultsContainer.style.display = 'block';
                
                // Check if current input exactly matches any result
                const exactMatch = masterNamaTransaksiPemasukan.find(item => 
                    item.nama_transaksi.toLowerCase() === query.toLowerCase()
                );
                updateValidationState(exactMatch ? 'valid' : 'invalid');
            } else {
                resultsContainer.style.display = 'none';
                filteredResults = [];
                updateValidationState('empty');
                resetPemasukanSelection();
            }
        });
        
        // Keyboard navigation
        input.addEventListener('keydown', function(e) {
            const items = resultsContainer.querySelectorAll('.search-result-item:not(.no-results)');
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                currentHighlightIndex = Math.min(currentHighlightIndex + 1, items.length - 1);
                updateHighlight(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                currentHighlightIndex = Math.max(currentHighlightIndex - 1, 0);
                updateHighlight(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (currentHighlightIndex >= 0 && items[currentHighlightIndex]) {
                    const index = parseInt(items[currentHighlightIndex].dataset.index);
                    selectTransaksiPemasukan(filteredResults[index]);
                }
            } else if (e.key === 'Escape') {
                resultsContainer.style.display = 'none';
                currentHighlightIndex = -1;
                validateCurrentInput();
            }
        });
        
        function updateHighlight(items) {
            items.forEach((item, index) => {
                if (index === currentHighlightIndex) {
                    item.classList.add('highlight');
                } else {
                    item.classList.remove('highlight');
                }
            });
        }
        
        function validateCurrentInput() {
            const currentValue = input.value.trim();
            
            if (currentValue === '') {
                input.readOnly = true;
                input.placeholder = "Klik untuk mencari transaksi pemasukan...";
                updateValidationState('empty');
                resetPemasukanSelection();
                resultsContainer.style.display = 'none';
                return;
            }
            
            const isValid = masterNamaTransaksiPemasukan.some(item => 
                item.nama_transaksi.toLowerCase() === currentValue.toLowerCase()
            );
            
            if (isValid) {
                const matchedItem = masterNamaTransaksiPemasukan.find(item => 
                    item.nama_transaksi.toLowerCase() === currentValue.toLowerCase()
                );
                selectTransaksiPemasukan(matchedItem, false);
            } else {
                // Reset ke nilai kosong jika tidak valid
                input.value = '';
                updateValidationState('empty');
                resetPemasukanSelection();
            }
            
            // Set kembali ke readonly mode
            input.readOnly = true;
            input.placeholder = "Klik untuk mencari transaksi pemasukan...";
            resultsContainer.style.display = 'none';
        }
        
        function updateValidationState(state) {
            const icon = validationIcon;
            
            // Remove all validation classes
            input.classList.remove('valid', 'invalid', 'empty');
            icon.classList.remove('valid', 'invalid', 'empty');
            
            switch(state) {
                case 'valid':
                    isValidSelection = true;
                    input.classList.add('valid');
                    icon.classList.add('valid');
                    icon.innerHTML = '<i class="fas fa-check-circle"></i>';
                    break;
                case 'invalid':
                    isValidSelection = false;
                    input.classList.add('invalid');
                    icon.classList.add('invalid');
                    icon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
                    break;
                case 'empty':
                default:
                    isValidSelection = false;
                    input.classList.add('empty');
                    icon.classList.add('empty');
                    icon.innerHTML = '<i class="fas fa-question-circle"></i>';
                    break;
            }
        }
        
        // Initialize with empty state
        updateValidationState('empty');
    }

    // Enhanced search function for Pengeluaran with rigid validation like cashier pages
    function setupPengeluaranSearch() {
        const input = document.getElementById('nama_transaksi_pengeluaran');
        const resultsContainer = document.getElementById('search_results_pengeluaran');
        const validationIcon = document.getElementById('validation_icon_pengeluaran');
        const clearBtn = document.getElementById('clear_pengeluaran');
        
        if (!input || !resultsContainer || !validationIcon) return;
        
        let selectedTransaksi = null;
        let currentHighlightIndex = -1;
        let filteredResults = [];
        let isValidSelection = false;
        
        // RIGID MODE: Click handler untuk aktivasi input (readonly by default)
        input.addEventListener('click', function() {
            if (this.readOnly) {
                this.readOnly = false;
                this.focus();
                this.value = '';
                this.placeholder = "Ketik untuk mencari transaksi pengeluaran...";
                updateValidationState('empty');
            }
        });
        
        // Blur handler - kembali ke readonly mode dengan validasi
        input.addEventListener('blur', function(e) {
            setTimeout(() => {
                if (!resultsContainer.matches(':hover')) {
                    validateCurrentInput();
                }
            }, 200);
        });
        
        // Input event listener for real-time search
        input.addEventListener('input', function() {
            const query = this.value.trim();
            currentHighlightIndex = -1;
            
            if (query.length >= 1) {
                filteredResults = masterNamaTransaksiPengeluaran.filter(item => 
                    item.nama_transaksi.toLowerCase().includes(query.toLowerCase()) ||
                    item.kode_akun.toLowerCase().includes(query.toLowerCase()) ||
                    item.arti.toLowerCase().includes(query.toLowerCase())
                );
                populateDropdownPengeluaran(filteredResults);
                resultsContainer.style.display = 'block';
                
                // Check if current input exactly matches any result
                const exactMatch = masterNamaTransaksiPengeluaran.find(item => 
                    item.nama_transaksi.toLowerCase() === query.toLowerCase()
                );
                updateValidationState(exactMatch ? 'valid' : 'invalid');
            } else {
                resultsContainer.style.display = 'none';
                filteredResults = [];
                updateValidationState('empty');
                resetPengeluaranSelection();
            }
        });
        
        // Keyboard navigation
        input.addEventListener('keydown', function(e) {
            const items = resultsContainer.querySelectorAll('.search-result-item:not(.no-results)');
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                currentHighlightIndex = Math.min(currentHighlightIndex + 1, items.length - 1);
                updateHighlight(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                currentHighlightIndex = Math.max(currentHighlightIndex - 1, 0);
                updateHighlight(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (currentHighlightIndex >= 0 && items[currentHighlightIndex]) {
                    const index = parseInt(items[currentHighlightIndex].dataset.index);
                    selectTransaksiPengeluaran(filteredResults[index]);
                }
            } else if (e.key === 'Escape') {
                resultsContainer.style.display = 'none';
                currentHighlightIndex = -1;
                validateCurrentInput();
            }
        });
        
        function updateHighlight(items) {
            items.forEach((item, index) => {
                if (index === currentHighlightIndex) {
                    item.classList.add('highlight');
                } else {
                    item.classList.remove('highlight');
                }
            });
        }
        
        function validateCurrentInput() {
            const currentValue = input.value.trim();
            
            if (currentValue === '') {
                input.readOnly = true;
                input.placeholder = "Klik untuk mencari transaksi pengeluaran...";
                updateValidationState('empty');
                resetPengeluaranSelection();
                resultsContainer.style.display = 'none';
                return;
            }
            
            const isValid = masterNamaTransaksiPengeluaran.some(item => 
                item.nama_transaksi.toLowerCase() === currentValue.toLowerCase()
            );
            
            if (isValid) {
                const matchedItem = masterNamaTransaksiPengeluaran.find(item => 
                    item.nama_transaksi.toLowerCase() === currentValue.toLowerCase()
                );
                selectTransaksiPengeluaran(matchedItem, false);
            } else {
                // Reset ke nilai kosong jika tidak valid
                input.value = '';
                updateValidationState('empty');
                resetPengeluaranSelection();
            }
            
            // Set kembali ke readonly mode
            input.readOnly = true;
            input.placeholder = "Klik untuk mencari transaksi pengeluaran...";
            resultsContainer.style.display = 'none';
        }
        
        function updateValidationState(state) {
            const icon = validationIcon;
            
            // Remove all validation classes
            input.classList.remove('valid', 'invalid', 'empty');
            icon.classList.remove('valid', 'invalid', 'empty');
            
            switch(state) {
                case 'valid':
                    isValidSelection = true;
                    input.classList.add('valid');
                    icon.classList.add('valid');
                    icon.innerHTML = '<i class="fas fa-check-circle"></i>';
                    break;
                case 'invalid':
                    isValidSelection = false;
                    input.classList.add('invalid');
                    icon.classList.add('invalid');
                    icon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
                    break;
                case 'empty':
                default:
                    isValidSelection = false;
                    input.classList.add('empty');
                    icon.classList.add('empty');
                    icon.innerHTML = '<i class="fas fa-question-circle"></i>';
                    break;
            }
        }
        
        // Initialize with empty state
        updateValidationState('empty');
    }

    // Populate dropdown with filtered results for Pemasukan
    function populateDropdownPemasukan(results) {
        const resultsContainer = document.getElementById('search_results_pemasukan');
        const input = document.getElementById('nama_transaksi_pemasukan');
        
        if (!resultsContainer) return;
        
        resultsContainer.innerHTML = '';
        
        if (results.length === 0) {
            const noResults = document.createElement('div');
            noResults.className = 'no-results';
            noResults.innerHTML = '<i class="fas fa-search"></i> Tidak ada transaksi yang ditemukan';
            resultsContainer.appendChild(noResults);
            return;
        }
        
        results.forEach((item, index) => {
            const div = document.createElement('div');
            div.className = 'search-result-item';
            div.dataset.index = index;
            
            // Highlight matching text
            const query = input.value.toLowerCase();
            const namaHighlighted = highlightText(item.nama_transaksi, query);
            const kodeHighlighted = highlightText(`${item.kode_akun} - ${item.arti}`, query);
            
            div.innerHTML = `
                <div class="nama">${namaHighlighted}</div>
                <div class="kode">${kodeHighlighted}</div>
            `;
            
            div.addEventListener('click', function() {
                selectTransaksiPemasukan(item);
            });
            
            div.addEventListener('mouseenter', function() {
                // Update highlight index when hovering
                const items = resultsContainer.querySelectorAll('.search-result-item:not(.no-results)');
                items.forEach((item, idx) => {
                    if (idx === index) {
                        item.classList.add('highlight');
                    } else {
                        item.classList.remove('highlight');
                    }
                });
            });
            
            resultsContainer.appendChild(div);
        });
    }

    // Populate dropdown with filtered results for Pengeluaran
    function populateDropdownPengeluaran(results) {
        const resultsContainer = document.getElementById('search_results_pengeluaran');
        const input = document.getElementById('nama_transaksi_pengeluaran');
        
        if (!resultsContainer) return;
        
        resultsContainer.innerHTML = '';
        
        if (results.length === 0) {
            const noResults = document.createElement('div');
            noResults.className = 'no-results';
            noResults.innerHTML = '<i class="fas fa-search"></i> Tidak ada transaksi yang ditemukan';
            resultsContainer.appendChild(noResults);
            return;
        }
        
        results.forEach((item, index) => {
            const div = document.createElement('div');
            div.className = 'search-result-item';
            div.dataset.index = index;
            
            // Highlight matching text
            const query = input.value.toLowerCase();
            const namaHighlighted = highlightText(item.nama_transaksi, query);
            const kodeHighlighted = highlightText(`${item.kode_akun} - ${item.arti}`, query);
            
            div.innerHTML = `
                <div class="nama">${namaHighlighted}</div>
                <div class="kode">${kodeHighlighted}</div>
            `;
            
            div.addEventListener('click', function() {
                selectTransaksiPengeluaran(item);
            });
            
            div.addEventListener('mouseenter', function() {
                // Update highlight index when hovering
                const items = resultsContainer.querySelectorAll('.search-result-item:not(.no-results)');
                items.forEach((item, idx) => {
                    if (idx === index) {
                        item.classList.add('highlight');
                    } else {
                        item.classList.remove('highlight');
                    }
                });
            });
            
            resultsContainer.appendChild(div);
        });
    }

    function updatePemasukanFields(item) {
        // Set kode akun
        document.getElementById('kode_akun_pemasukan').value = item.kode_akun;
        
        // Tampilkan keterangan default di atas input, tidak auto-fill ke input
        const keteranganDefault = document.getElementById('keterangan_default_pemasukan');
        const defaultText = document.getElementById('default_text_pemasukan');
        
        if (item.keterangan_default) {
            defaultText.textContent = item.keterangan_default;
            keteranganDefault.classList.add('show');
        } else {
            keteranganDefault.classList.remove('show');
        }
        
        // Kosongkan input keterangan - jangan auto-fill
        document.getElementById('keterangan_pemasukan').value = '';
    }

    function updatePengeluaranFields(item) {
        // Set kode akun
        document.getElementById('kode_akun_pengeluaran').value = item.kode_akun;
        
        // Tampilkan keterangan default di atas input, tidak auto-fill ke input
        const keteranganDefault = document.getElementById('keterangan_default_pengeluaran');
        const defaultText = document.getElementById('default_text_pengeluaran');
        
        if (item.keterangan_default) {
            defaultText.textContent = item.keterangan_default;
            keteranganDefault.classList.add('show');
        } else {
            keteranganDefault.classList.remove('show');
        }
        
        // Kosongkan input keterangan - jangan auto-fill
        document.getElementById('keterangan_pengeluaran').value = '';
        
        // Trigger change event untuk set kategori dan umur pakai
        setKategoriAkuntype();
    }

    function selectTransaksiPemasukan(item, fromDropdown = true) {
        const input = document.getElementById('nama_transaksi_pemasukan');
        const validationIcon = document.getElementById('validation_icon_pemasukan');
        
        selectedTransaksiPemasukan = item;
        
        // Set nama transaksi
        input.value = item.nama_transaksi;
        
        // Update validation state
        input.classList.remove('valid', 'invalid', 'empty');
        input.classList.add('valid');
        validationIcon.classList.remove('valid', 'invalid', 'empty');
        validationIcon.classList.add('valid');
        validationIcon.innerHTML = '<i class="fas fa-check-circle"></i>';
        
        // Update all related fields
        updatePemasukanFields(item);
        
        if (fromDropdown) {
            // Set ke readonly mode setelah selection
            input.readOnly = true;
            input.placeholder = "Klik untuk mengubah nama transaksi";
            
            // Hide search results
            document.getElementById('search_results_pemasukan').style.display = 'none';
        }
    }

    function selectTransaksiPengeluaran(item, fromDropdown = true) {
        const input = document.getElementById('nama_transaksi_pengeluaran');
        const validationIcon = document.getElementById('validation_icon_pengeluaran');
        
        selectedTransaksiPengeluaran = item;
        
        // Set nama transaksi
        input.value = item.nama_transaksi;
        
        // Update validation state
        input.classList.remove('valid', 'invalid', 'empty');
        input.classList.add('valid');
        validationIcon.classList.remove('valid', 'invalid', 'empty');
        validationIcon.classList.add('valid');
        validationIcon.innerHTML = '<i class="fas fa-check-circle"></i>';
        
        // Update all related fields
        updatePengeluaranFields(item);
        
        if (fromDropdown) {
            // Set ke readonly mode setelah selection
            input.readOnly = true;
            input.placeholder = "Klik untuk mengubah nama transaksi";
            
            // Hide search results
            document.getElementById('search_results_pengeluaran').style.display = 'none';
        }
    }

    function resetPemasukanSelection() {
        selectedTransaksiPemasukan = null;
        const input = document.getElementById('nama_transaksi_pemasukan');
        const validationIcon = document.getElementById('validation_icon_pemasukan');
        
        if (input) {
            input.classList.remove('valid', 'invalid', 'empty');
            input.classList.add('empty');
        }
        if (validationIcon) {
            validationIcon.classList.remove('valid', 'invalid', 'empty');
            validationIcon.classList.add('empty');
            validationIcon.innerHTML = '<i class="fas fa-question-circle"></i>';
        }
        
        document.getElementById('kode_akun_pemasukan').value = '';
        const keteranganDefault = document.getElementById('keterangan_default_pemasukan');
        if (keteranganDefault) keteranganDefault.classList.remove('show');
    }

    function resetPengeluaranSelection() {
        selectedTransaksiPengeluaran = null;
        const input = document.getElementById('nama_transaksi_pengeluaran');
        const validationIcon = document.getElementById('validation_icon_pengeluaran');
        
        if (input) {
            input.classList.remove('valid', 'invalid', 'empty');
            input.classList.add('empty');
        }
        if (validationIcon) {
            validationIcon.classList.remove('valid', 'invalid', 'empty');
            validationIcon.classList.add('empty');
            validationIcon.innerHTML = '<i class="fas fa-question-circle"></i>';
        }
        
        document.getElementById('kode_akun_pengeluaran').value = '';
        document.getElementById('kategori_pengeluaran').value = '';
        const keteranganDefault = document.getElementById('keterangan_default_pengeluaran');
        if (keteranganDefault) keteranganDefault.classList.remove('show');
        
        // Reset umur pakai
        const umurPakaiInput = document.getElementById('umur_pakai');
        if (umurPakaiInput) {
            umurPakaiInput.disabled = true;
            umurPakaiInput.value = 0;
            umurPakaiInput.required = false;
        }
    }

    // Form validation for Pemasukan with rigid validation
    if (document.getElementById('pemasukanForm')) {
        document.getElementById('pemasukanForm').addEventListener('submit', function(e) {
            const namaTransaksiInput = document.getElementById('nama_transaksi_pemasukan');
            
            if (!selectedTransaksiPemasukan) {
                e.preventDefault();
                alert('Pilih nama transaksi yang valid terlebih dahulu!');
                namaTransaksiInput.focus();
                return false;
            }
            
            // Validate nama transaksi ada dalam master data (RIGID CHECK)
            const currentValue = namaTransaksiInput.value.trim();
            const isValidTransaksi = masterNamaTransaksiPemasukan.some(item => 
                item.nama_transaksi.toLowerCase() === currentValue.toLowerCase()
            );
            
            if (!isValidTransaksi) {
                e.preventDefault();
                alert('Nama transaksi tidak valid! Silakan pilih dari daftar yang tersedia.');
                namaTransaksiInput.focus();
                return false;
            }
            
            // Validate keterangan is filled
            const keterangan = document.getElementById('keterangan_pemasukan').value.trim();
            if (!keterangan) {
                e.preventDefault();
                alert('Keterangan transaksi wajib diisi!');
                document.getElementById('keterangan_pemasukan').focus();
                return false;
            }
            
            return true;
        });
    }

    // Form validation for Pengeluaran with rigid validation
    if (document.getElementById('pengeluaranForm')) {
        document.getElementById('pengeluaranForm').addEventListener('submit', function(e) {
            const namaTransaksiInput = document.getElementById('nama_transaksi_pengeluaran');
            
            if (!selectedTransaksiPengeluaran) {
                e.preventDefault();
                alert('Pilih nama transaksi yang valid terlebih dahulu!');
                namaTransaksiInput.focus();
                return false;
            }
            
            // Validate nama transaksi ada dalam master data (RIGID CHECK)
            const currentValue = namaTransaksiInput.value.trim();
            const isValidTransaksi = masterNamaTransaksiPengeluaran.some(item => 
                item.nama_transaksi.toLowerCase() === currentValue.toLowerCase()
            );
            
            if (!isValidTransaksi) {
                e.preventDefault();
                alert('Nama transaksi tidak valid! Silakan pilih dari daftar yang tersedia.');
                namaTransaksiInput.focus();
                return false;
            }
            
            // Validate keterangan is filled
            const keterangan = document.getElementById('keterangan_pengeluaran').value.trim();
            if (!keterangan) {
                e.preventDefault();
                alert('Keterangan transaksi wajib diisi!');
                document.getElementById('keterangan_pengeluaran').focus();
                return false;
            }
            
            return true;
        });
    }

    // Hide search results when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-container')) {
            const pemasukanResults = document.getElementById('search_results_pemasukan');
            const pengeluaranResults = document.getElementById('search_results_pengeluaran');
            if (pemasukanResults) pemasukanResults.style.display = 'none';
            if (pengeluaranResults) pengeluaranResults.style.display = 'none';
        }
    });

    // Initialize search functionality on page load
    document.addEventListener('DOMContentLoaded', function() {
        setupPemasukanSearch();
        setupPengeluaranSearch();
        
        // Auto-format number inputs
        const numberInputs = document.querySelectorAll('input[type="number"]');
        numberInputs.forEach(input => {
            if (input.name === 'jumlah') {
                input.addEventListener('input', function() {
                    // Remove any non-numeric characters except decimal point
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            }
        });
        
        // Form validation for history filters
        const filterForm = document.querySelector('form[method="GET"]');
        if (filterForm) {
            filterForm.addEventListener('submit', function(e) {
                const tanggalAwal = document.getElementById('filter_tanggal_awal');
                const tanggalAkhir = document.getElementById('filter_tanggal_akhir');
                
                if (tanggalAwal && tanggalAkhir && tanggalAwal.value && tanggalAkhir.value) {
                    if (new Date(tanggalAwal.value) > new Date(tanggalAkhir.value)) {
                        e.preventDefault();
                        alert('Tanggal awal tidak boleh lebih besar dari tanggal akhir!');
                        return false;
                    }
                }
            });
        }
        
        // Add mark styling for search highlights
        const style = document.createElement('style');
        style.textContent = `
            mark {
                background-color: #ffeb3b;
                color: #000;
                padding: 1px 2px;
                border-radius: 2px;
                font-weight: 600;
            }
        `;
        document.head.appendChild(style);
    });

    // Run on page load and window resize
    window.addEventListener('load', adjustSidebarWidth);
    window.addEventListener('resize', adjustSidebarWidth);
</script>
</body>
</html>