<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'config.php'; // Include your database connection

// Check if the user is logged in and has the 'kasir' role
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$kode_karyawan = $_SESSION['kode_karyawan'];

// Retrieve the transaction code from the session or URL
if (isset($_GET['kode_transaksi'])) {
    $kode_transaksi = $_GET['kode_transaksi'];
} else {
    die("Kode transaksi tidak ditemukan.");
}

// Check if Kas Akhir has been input
$sql_check_kas_akhir = "SELECT total_nilai FROM kas_akhir WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan";
$stmt_check_kas_akhir = $pdo->prepare($sql_check_kas_akhir);
$stmt_check_kas_akhir->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_check_kas_akhir->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_check_kas_akhir->execute();
$kas_akhir_exists = $stmt_check_kas_akhir->fetchColumn();

if (!$kas_akhir_exists) {
    die("Kas Akhir belum diinput. Anda tidak bisa mengedit pengeluaran sebelum Kas Akhir diinput.");
}

// Fetch the existing Pengeluaran for this transaction including "kategori"
$sql_pengeluaran = "SELECT pk.*, ma.kategori, ma.require_umur_pakai, ma.min_umur_pakai 
                    FROM pengeluaran_kasir pk 
                    LEFT JOIN master_akun ma ON pk.kode_akun = ma.kode_akun 
                    WHERE pk.kode_transaksi = :kode_transaksi AND pk.kode_karyawan = :kode_karyawan
                    ORDER BY pk.tanggal DESC, pk.waktu DESC";
$stmt_pengeluaran = $pdo->prepare($sql_pengeluaran);
$stmt_pengeluaran->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pengeluaran->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_pengeluaran->execute();
$pengeluaran_data = $stmt_pengeluaran->fetchAll(PDO::FETCH_ASSOC);

// Fetch master akun for the dropdown (only accounts of type 'pengeluaran')
$sql_master_akun = "SELECT kode_akun, arti, kategori, require_umur_pakai, min_umur_pakai FROM master_akun WHERE jenis_akun = 'pengeluaran'";
$stmt_master_akun = $pdo->query($sql_master_akun);
$master_akun = $stmt_master_akun->fetchAll(PDO::FETCH_ASSOC);

// Retrieve master nama transaksi untuk pengeluaran
$sql_nama_transaksi = "SELECT mnt.*, ma.arti 
                       FROM master_nama_transaksi mnt 
                       JOIN master_akun ma ON mnt.kode_akun = ma.kode_akun 
                       WHERE mnt.status = 'active' AND ma.jenis_akun = 'pengeluaran'
                       ORDER BY mnt.nama_transaksi";
$stmt_nama_transaksi = $pdo->query($sql_nama_transaksi);
$master_nama_transaksi = $stmt_nama_transaksi->fetchAll(PDO::FETCH_ASSOC);

// If the form is submitted, process the input or update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $kode_akun = isset($_POST['kode_akun']) ? $_POST['kode_akun'] : '';
    $jumlah_pengeluaran = isset($_POST['jumlah_pengeluaran']) ? $_POST['jumlah_pengeluaran'] : 0;
    $keterangan_transaksi = isset($_POST['keterangan_transaksi']) ? $_POST['keterangan_transaksi'] : '';
    
    // Handle umur_pakai dengan default value
    $umur_pakai = isset($_POST['umur_pakai']) && !empty($_POST['umur_pakai']) ? intval($_POST['umur_pakai']) : 0;
    $kategori = isset($_POST['kategori']) ? $_POST['kategori'] : '';

    // Automatically set tanggal and waktu in real-time
    $tanggal = date('Y-m-d');
    $waktu = date('H:i:s');

    // Validasi angka jumlah agar hanya bilangan bulat
    if (!is_numeric($jumlah_pengeluaran) || floor($jumlah_pengeluaran) != $jumlah_pengeluaran) {
        echo "<script>alert('Jumlah harus berupa bilangan bulat!');</script>";
        exit;
    }

    // Validasi keterangan wajib diisi
    if (empty(trim($keterangan_transaksi))) {
        echo "<script>alert('Keterangan transaksi wajib diisi!');</script>";
        exit;
    }

    // Validasi umur pakai untuk kode akun yang memerlukan
    $sql_validasi_umur = "SELECT require_umur_pakai, min_umur_pakai FROM master_akun WHERE kode_akun = :kode_akun";
    $stmt_validasi_umur = $pdo->prepare($sql_validasi_umur);
    $stmt_validasi_umur->bindParam(':kode_akun', $kode_akun, PDO::PARAM_STR);
    $stmt_validasi_umur->execute();
    $validasi_umur_data = $stmt_validasi_umur->fetch(PDO::FETCH_ASSOC);
    
    if ($validasi_umur_data && $validasi_umur_data['require_umur_pakai'] == 1) {
        if ($umur_pakai < $validasi_umur_data['min_umur_pakai']) {
            echo "<script>alert('Umur pakai minimal " . $validasi_umur_data['min_umur_pakai'] . " bulan untuk kode akun ini!');</script>";
            exit;
        }
    }

    // Insert Pengeluaran data in the database
    $sql_insert_pengeluaran = "INSERT INTO pengeluaran_kasir 
                             (kode_transaksi, kode_karyawan, kode_akun, jumlah, keterangan_transaksi, tanggal, waktu, umur_pakai, kategori)
                             VALUES (:kode_transaksi, :kode_karyawan, :kode_akun, :jumlah_pengeluaran, :keterangan_transaksi, :tanggal, :waktu, :umur_pakai, :kategori)";
    $stmt_insert_pengeluaran = $pdo->prepare($sql_insert_pengeluaran);
    $stmt_insert_pengeluaran->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
    $stmt_insert_pengeluaran->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
    $stmt_insert_pengeluaran->bindParam(':kode_akun', $kode_akun, PDO::PARAM_STR);
    $stmt_insert_pengeluaran->bindParam(':jumlah_pengeluaran', $jumlah_pengeluaran, PDO::PARAM_STR);
    $stmt_insert_pengeluaran->bindParam(':keterangan_transaksi', $keterangan_transaksi, PDO::PARAM_STR);
    $stmt_insert_pengeluaran->bindParam(':tanggal', $tanggal, PDO::PARAM_STR);
    $stmt_insert_pengeluaran->bindParam(':waktu', $waktu, PDO::PARAM_STR);
    $stmt_insert_pengeluaran->bindParam(':umur_pakai', $umur_pakai, PDO::PARAM_INT);
    $stmt_insert_pengeluaran->bindParam(':kategori', $kategori, PDO::PARAM_STR);

    if ($stmt_insert_pengeluaran->execute()) {
        echo "<script>alert('Data pengeluaran berhasil ditambahkan.'); window.location.href='edit_pengeluaran1.php?kode_transaksi=$kode_transaksi';</script>";
    } else {
        echo "<script>alert('Terjadi kesalahan saat menambahkan data.');</script>";
    }
}

// Ambil nama karyawan dan nama cabang berdasarkan kode_karyawan dari tabel users
$sql_nama_karyawan = "SELECT nama_karyawan, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan";
$stmt_nama_karyawan = $pdo->prepare($sql_nama_karyawan);
$stmt_nama_karyawan->bindParam(':kode_karyawan', $kode_karyawan);
$stmt_nama_karyawan->execute();
$nama_karyawan_data = $stmt_nama_karyawan->fetch(PDO::FETCH_ASSOC);

$nama_karyawan = $nama_karyawan_data['nama_karyawan'] ?? 'Tidak diketahui';
$nama_cabang = $nama_karyawan_data['nama_cabang'] ?? 'Tidak diketahui';
// Gabungkan kode_karyawan dan nama_karyawan
$karyawan_info = $kode_karyawan . ' - ' . ($nama_karyawan ?? 'Tidak diketahui');

$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$cabang_user = $_SESSION['nama_cabang'] ?? 'Unknown Cabang';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah/Edit Pengeluaran</title>
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
            --secondary-color: #6c757d;
            --warning-color: #ffc107;
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
            width: 250px;
            background: #1e293b;
            height: 100vh;
            position: fixed;
            padding: 20px 0;
            transition: width 0.3s ease;
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
        }
        .logout-btn:hover {
            background: #c82333;
        }
        .main-content {
            margin-left: 250px;
            padding: 30px;
            flex: 1;
            transition: margin-left 0.3s ease;
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
        .page-header {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .page-header h1 {
            font-size: 28px;
            margin-bottom: 8px;
            color: var(--text-dark);
        }
        .page-header .subtitle {
            color: var(--text-muted);
            font-size: 16px;
        }
        .transaction-code {
            background: var(--background-light);
            padding: 8px 16px;
            border-radius: 12px;
            display: inline-block;
            margin-top: 12px;
            font-weight: 600;
        }
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            color: var(--text-muted);
        }
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
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
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            border: 1px solid;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }
        .form-group {
            margin-bottom: 24px;
        }
        .form-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-dark);
            font-size: 14px;
        }
        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-left: 10px;
            font-style: italic;
        }
        .form-control, .form-select {
            width: 100%;
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
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }
        .form-control[readonly] {
            background: var(--background-light);
            color: var(--text-muted);
            cursor: not-allowed;
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
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            justify-content: center;
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
        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #e0a800);
            color: white;
            box-shadow: 0 2px 4px rgba(255, 193, 7, 0.2);
        }
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.3);
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-top: 32px;
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
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-dark);
            margin: 32px 0 16px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--border-color);
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
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
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
        .search-results.show {
            display: block;
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
        .search-hint {
            background: rgba(0,123,255,0.05);
            border: 1px solid rgba(0,123,255,0.2);
            border-radius: 6px;
            padding: 8px 12px;
            margin-top: 4px;
            font-size: 12px;
            color: var(--primary-color);
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

        /* PERBAIKAN: Style untuk validasi input nama transaksi */
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
        
        /* RIGID MODE STYLING */
        .search-input-locked {
            background: rgba(0,123,255,0.05) !important;
            border-color: var(--primary-color) !important;
            font-weight: 600;
        }
        .search-input-invalid {
            background: rgba(220, 53, 69, 0.05) !important;
            border-color: var(--danger-color) !important;
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
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
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
    <div class="sidebar" id="sidebar">
        <a href="index_kasir.php"><i class="fas fa-tachometer-alt"></i> Dashboard Kasir</a>
        <a href="serah_terima_kasir.php"><i class="fas fa-handshake"></i> Serah Terima Kasir</a>
        <a href="setoran_keuangan_cs.php"><i class="fas fa-money-bill"></i> Setoran Keuangan CS</a>
        <button class="logout-btn" onclick="window.location.href='logout.php';"><i class="fas fa-sign-out-alt"></i> Logout</button>
    </div>

    <div class="main-content">
        <div class="user-profile">
            <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
            <div>
                <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($cabang_user); ?>)
                <p style="color: var(--text-muted); font-size: 12px;">Kasir</p>
            </div>
        </div>

        <div class="breadcrumb">
            <a href="index_kasir.php"><i class="fas fa-home"></i> Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <span>Tambah/Edit Pengeluaran</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-edit"></i> Tambah/Edit Pengeluaran</h1>
            <p class="subtitle">Kelola data pengeluaran setelah kas akhir diinput</p>
            <div class="transaction-code">
                <i class="fas fa-receipt"></i> <?php echo htmlspecialchars($kode_transaksi); ?>
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
            <div class="info-card">
                <div class="label">Status Kas Akhir</div>
                <div class="value" style="color: var(--success-color);">✓ Sudah Diinput</div>
            </div>
        </div>

        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            Kas akhir sudah diinput. Anda dapat menambah atau mengedit data pengeluaran pada halaman ini.
        </div>

        <!-- Form Section -->
        <div class="form-section">
            <h3 style="margin-bottom: 20px; color: var(--text-dark);"><i class="fas fa-plus-circle"></i> Tambah Pengeluaran Baru</h3>
            <form method="POST" action="" id="pengeluaranForm">
                <!-- Nama Transaksi dengan Auto Search - PERBAIKAN -->
                <div class="form-group">
                    <label for="nama_transaksi" class="form-label">
                        <i class="fas fa-search"></i> Nama Transaksi <span class="required">*</span>
                        <span class="form-hint">Pilih dari daftar transaksi yang tersedia. Tidak bisa membuat nama transaksi sendiri.</span>
                    </label>
                    <div class="search-container">
                        <div class="input-group">
                            <input type="text" id="nama_transaksi" name="nama_transaksi" class="form-control empty" 
                                   placeholder="Klik untuk mencari transaksi pengeluaran..." 
                                   autocomplete="off" readonly required>
                            <div class="validation-icon empty" id="validation_icon">
                                <i class="fas fa-question-circle"></i>
                            </div>
                        </div>
                        <div class="search-hint">
                            <i class="fas fa-keyboard"></i> Klik untuk mencari transaksi, gunakan ↑↓ untuk navigasi, Enter untuk memilih
                        </div>
                        <div class="search-results" id="search_results">
                            <!-- Results will be populated by JavaScript -->
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="kode_akun" class="form-label">
                        <i class="fas fa-code"></i> Kode Akun
                        <span class="form-hint">Otomatis terisi sesuai dengan nama transaksi yang dipilih.</span>
                    </label>
                    <select name="kode_akun" id="kode_akun" class="form-select" readonly style="pointer-events: none; background: var(--background-light); cursor: not-allowed;" required>
                        <option value="">-- Otomatis terisi berdasarkan nama transaksi --</option>
                        <?php foreach ($master_akun as $akun): ?>
                            <option value="<?php echo htmlspecialchars($akun['kode_akun']); ?>">
                                <?php echo htmlspecialchars($akun['kode_akun']) . ' - ' . htmlspecialchars($akun['arti']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="kategori" class="form-label">
                        <i class="fas fa-tags"></i> Kategori Akun
                        <span class="form-hint">Otomatis terisi berdasarkan kode akun yang dipilih.</span>
                    </label>
                    <input type="text" name="kategori" id="kategori" class="form-control" readonly>
                </div>

                <div class="form-group">
                    <label for="keterangan_transaksi" class="form-label">
                        <i class="fas fa-sticky-note"></i> Keterangan Transaksi <span class="required">*</span>
                        <span class="form-hint">Keterangan wajib diisi untuk melengkapi data transaksi.</span>
                    </label>
                    <!-- Tampilkan keterangan default di atas input tanpa auto-fill -->
                    <div class="keterangan-default" id="keterangan_default">
                        <strong>Keterangan Default:</strong> <span id="default_text">-</span>
                    </div>
                    <input type="text" id="keterangan_transaksi" name="keterangan_transaksi" class="form-control" oninput="this.value = this.value.toUpperCase()" placeholder="Masukkan keterangan" required>
                </div>

                <!-- Jumlah dan Umur Pakai Bersebelahan -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="jumlah_pengeluaran" class="form-label">
                            <i class="fas fa-money-bill-wave"></i> Jumlah Pengeluaran (Rp) <span class="required">*</span>
                            <span class="form-hint">Masukkan jumlah dalam rupiah (bilangan bulat).</span>
                        </label>
                        <input type="number" id="jumlah_pengeluaran" name="jumlah_pengeluaran" class="form-control" step="1" placeholder="Masukkan jumlah" required>
                    </div>

                    <div class="form-group">
                        <label for="umur_pakai" class="form-label">
                            <i class="fas fa-calendar-alt"></i> Umur Pakai (Bulan)
                            <span class="form-hint">Akan aktif jika kode akun memerlukan umur pakai.</span>
                        </label>
                        <input type="number" name="umur_pakai" id="umur_pakai" class="form-control" min="0" placeholder="Masukkan umur pakai" value="0" disabled>
                    </div>
                </div>

                <button type="submit" class="btn btn-danger" style="width: 100%;" id="submit_btn">
                    <i class="fas fa-plus"></i> Tambah Pengeluaran
                </button>
            </form>
        </div>

        <!-- Data Table Section -->
        <h2 class="section-title"><i class="fas fa-table"></i> Data Pengeluaran Kasir</h2>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Akun</th>
                        <th>Kategori Akun</th>
                        <th>Jumlah (Rp)</th>
                        <th>Keterangan</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Umur Pakai (Bulan)</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pengeluaran_data): ?>
                        <?php $i = 1; foreach ($pengeluaran_data as $pengeluaran): ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td style="font-weight: 600;"><?php echo htmlspecialchars($pengeluaran['kode_akun'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pengeluaran['kategori'] ?? 'N/A'); ?></td>
                                <td style="font-weight: 600; color: var(--danger-color);">Rp <?php echo number_format($pengeluaran['jumlah'] ?? 0, 0, ',', '.'); ?></td>
                                <td><?php echo htmlspecialchars($pengeluaran['keterangan_transaksi'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pengeluaran['tanggal'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pengeluaran['waktu'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($pengeluaran['umur_pakai'] ?? '0'); ?></td>
                                <td>
                                    <a href="edit_pengeluaran.php?id=<?php echo $pengeluaran['id']; ?>" class="btn btn-warning btn-sm">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <a href="hapus_pengeluaran1.php?id=<?php echo $pengeluaran['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Apakah Anda yakin ingin menghapus data ini?')">
                                        <i class="fas fa-trash"></i> Hapus
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>Belum ada pengeluaran untuk transaksi ini.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Master data transaksi untuk auto search (PENGELUARAN)
        const masterNamaTransaksi = <?php echo json_encode($master_nama_transaksi); ?>;
        const masterAkun = <?php echo json_encode($master_akun); ?>;

        let selectedTransaksi = null;
        let currentHighlightIndex = -1;
        let filteredResults = [];
        let isValidSelection = false; // Track if current selection is valid

        // Enhanced search functionality with type to find
        const namaTransaksiInput = document.getElementById('nama_transaksi');
        const searchResults = document.getElementById('search_results');
        const validationIcon = document.getElementById('validation_icon');

        // PERBAIKAN: Event listener untuk klik pada input (mengaktifkan mode edit)
        namaTransaksiInput.addEventListener('click', function() {
            if (this.readOnly) {
                this.readOnly = false;
                this.focus();
                this.value = '';
                this.placeholder = "Ketik untuk mencari transaksi pengeluaran...";
                updateValidationState('empty');
            }
        });

        // PERBAIKAN: Event listener untuk blur (keluar dari input)
        namaTransaksiInput.addEventListener('blur', function(e) {
            // Delay untuk memungkinkan klik pada dropdown
            setTimeout(() => {
                if (!searchResults.matches(':hover')) {
                    validateCurrentInput();
                }
            }, 200);
        });

        // Input event listener for real-time search
        namaTransaksiInput.addEventListener('input', function() {
            const query = this.value.trim();
            currentHighlightIndex = -1;
            
            if (query.length >= 1) {
                filteredResults = masterNamaTransaksi.filter(item => 
                    item.nama_transaksi.toLowerCase().includes(query.toLowerCase()) ||
                    item.kode_akun.toLowerCase().includes(query.toLowerCase()) ||
                    item.arti.toLowerCase().includes(query.toLowerCase())
                );
                populateDropdown(filteredResults);
                searchResults.classList.add('show');
                
                // Check if current input exactly matches any result
                const exactMatch = masterNamaTransaksi.find(item => 
                    item.nama_transaksi.toLowerCase() === query.toLowerCase()
                );
                updateValidationState(exactMatch ? 'valid' : 'invalid');
            } else {
                searchResults.classList.remove('show');
                filteredResults = [];
                updateValidationState('empty');
                resetForm();
            }
        });

        // Keyboard navigation
        namaTransaksiInput.addEventListener('keydown', function(e) {
            const items = searchResults.querySelectorAll('.search-result-item:not(.no-results)');
            
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
                    selectTransaksi(filteredResults[index]);
                }
            } else if (e.key === 'Escape') {
                searchResults.classList.remove('show');
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

        // Populate dropdown with filtered results
        function populateDropdown(results) {
            searchResults.innerHTML = '';
            
            if (results.length === 0) {
                const noResults = document.createElement('div');
                noResults.className = 'no-results';
                noResults.innerHTML = '<i class="fas fa-search"></i> Tidak ada transaksi yang ditemukan';
                searchResults.appendChild(noResults);
                return;
            }
            
            results.forEach((item, index) => {
                const div = document.createElement('div');
                div.className = 'search-result-item';
                div.dataset.index = index;
                
                // Highlight matching text
                const query = namaTransaksiInput.value.toLowerCase();
                const namaHighlighted = highlightText(item.nama_transaksi, query);
                const kodeHighlighted = highlightText(`${item.kode_akun} - ${item.arti}`, query);
                
                div.innerHTML = `
                    <div class="nama">${namaHighlighted}</div>
                    <div class="kode">${kodeHighlighted}</div>
                `;
                
                div.addEventListener('click', function() {
                    selectTransaksi(item);
                });
                
                div.addEventListener('mouseenter', function() {
                    currentHighlightIndex = index;
                    updateHighlight(searchResults.querySelectorAll('.search-result-item:not(.no-results)'));
                });
                
                searchResults.appendChild(div);
            });
        }

        function highlightText(text, query) {
            if (!query) return text;
            
            const regex = new RegExp(`(${escapeRegExp(query)})`, 'gi');
            return text.replace(regex, '<mark style="background: yellow; padding: 0;">$1</mark>');
        }

        function escapeRegExp(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        function resetForm() {
            selectedTransaksi = null;
            document.getElementById('kode_akun').value = '';
            document.getElementById('keterangan_default').classList.remove('show');
            document.getElementById('kategori').value = '';
            document.getElementById('umur_pakai').disabled = true;
            document.getElementById('umur_pakai').value = 0;
            document.getElementById('umur_pakai').required = false;
        }

        // PERBAIKAN: Function untuk validasi input saat ini (RIGID MODE)
        function validateCurrentInput() {
            const currentValue = namaTransaksiInput.value.trim();
            
            if (currentValue === '') {
                namaTransaksiInput.readOnly = true;
                namaTransaksiInput.placeholder = "Klik untuk mencari transaksi pengeluaran...";
                namaTransaksiInput.classList.remove('search-input-invalid', 'search-input-locked');
                updateValidationState('empty');
                resetForm();
                searchResults.classList.remove('show');
                return;
            }
            
            const exactMatch = masterNamaTransaksi.find(item => 
                item.nama_transaksi.toLowerCase() === currentValue.toLowerCase()
            );
            
            if (exactMatch) {
                // Valid selection - lock it in
                selectTransaksi(exactMatch, false);
                namaTransaksiInput.classList.remove('search-input-invalid');
                namaTransaksiInput.classList.add('search-input-locked');
                updateValidationState('valid');
            } else {
                // Invalid input - force back to empty (RIGID)
                namaTransaksiInput.value = '';
                namaTransaksiInput.classList.remove('search-input-invalid', 'search-input-locked');
                updateValidationState('empty');
                resetForm();
            }
            
            // Always set readonly after validation (RIGID)
            namaTransaksiInput.readOnly = true;
            namaTransaksiInput.placeholder = "Klik untuk mencari transaksi pengeluaran...";
            searchResults.classList.remove('show');
        }

        // PERBAIKAN: Function untuk update status validasi visual
        function updateValidationState(state) {
            const input = namaTransaksiInput;
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

        function selectTransaksi(item, fromDropdown = true) {
            selectedTransaksi = item;
            
            // Set nama transaksi
            namaTransaksiInput.value = item.nama_transaksi;
            
            // Set kode akun
            document.getElementById('kode_akun').value = item.kode_akun;
            
            // Tampilkan keterangan default di atas input, tidak auto-fill ke input
            const keteranganDefault = document.getElementById('keterangan_default');
            const defaultText = document.getElementById('default_text');
            
            if (item.keterangan_default) {
                defaultText.textContent = item.keterangan_default;
                keteranganDefault.classList.add('show');
            } else {
                keteranganDefault.classList.remove('show');
            }
            
            // Kosongkan input keterangan - jangan auto-fill
            document.getElementById('keterangan_transaksi').value = '';
            
            // Set kategori dan umur pakai
            setKategoriAkuntype();
            
            // Update validation state
            updateValidationState('valid');
            
            if (fromDropdown) {
                // Set ke readonly mode setelah selection
                namaTransaksiInput.readOnly = true;
                namaTransaksiInput.placeholder = "Klik untuk mengubah nama transaksi";
                
                // Hide search results
                searchResults.classList.remove('show');
                currentHighlightIndex = -1;
            }
        }

        function setKategoriAkuntype() {
            var kodeAkun = document.getElementById("kode_akun");
            var kategoriAkunInput = document.getElementById("kategori");
            var umurPakaiInput = document.getElementById("umur_pakai");
            
            // Set kategori berdasarkan master data
            const masterItem = masterAkun.find(item => item.kode_akun === kodeAkun.value);
            if (masterItem) {
                kategoriAkunInput.value = masterItem.kategori || '';
                
                // Set umur pakai behavior
                if (masterItem.require_umur_pakai == 1) {
                    umurPakaiInput.disabled = false;
                    umurPakaiInput.value = masterItem.min_umur_pakai || 0;
                    umurPakaiInput.min = masterItem.min_umur_pakai || 0;
                    umurPakaiInput.required = true;
                } else {
                    umurPakaiInput.disabled = true;
                    umurPakaiInput.value = 0;
                    umurPakaiInput.required = false;
                }
            }
        }

        // PERBAIKAN: Form validation dengan pengecekan lebih ketat
        document.getElementById('pengeluaranForm').addEventListener('submit', function(e) {
            if (!selectedTransaksi || !isValidSelection) {
                e.preventDefault();
                alert('Pilih nama transaksi yang valid terlebih dahulu!');
                namaTransaksiInput.focus();
                return false;
            }
            
            // Validasi nama transaksi ada dalam master data
            const currentValue = namaTransaksiInput.value.trim();
            const isValidTransaksi = masterNamaTransaksi.some(item => 
                item.nama_transaksi.toLowerCase() === currentValue.toLowerCase()
            );
            
            if (!isValidTransaksi) {
                e.preventDefault();
                alert('Nama transaksi tidak valid! Silakan pilih dari daftar yang tersedia.');
                namaTransaksiInput.focus();
                return false;
            }
            
            // Validate keterangan is filled
            const keterangan = document.getElementById('keterangan_transaksi').value.trim();
            if (!keterangan) {
                e.preventDefault();
                alert('Keterangan transaksi wajib diisi!');
                document.getElementById('keterangan_transaksi').focus();
                return false;
            }
            
            // Validate jumlah is integer
            const jumlah = document.getElementById('jumlah_pengeluaran').value;
            if (!jumlah || isNaN(jumlah) || Math.floor(jumlah) != jumlah) {
                e.preventDefault();
                alert('Jumlah harus berupa bilangan bulat!');
                document.getElementById('jumlah_pengeluaran').focus();
                return false;
            }
            
            return true;
        });

        // Hide search results when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-container')) {
                searchResults.classList.remove('show');
                currentHighlightIndex = -1;
                if (!namaTransaksiInput.readOnly) {
                    validateCurrentInput();
                }
            }
        });

        // Format number input
        document.getElementById('jumlah_pengeluaran').addEventListener('input', function() {
            // Remove non-numeric characters except for the value itself
            let value = this.value.replace(/[^\d]/g, '');
            this.value = value;
        });

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Set initial validation state
            updateValidationState('empty');
        });
    </script>
</body>
</html>