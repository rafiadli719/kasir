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
// Ubah bagian ini yang sudah ada
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User'; // Tambahkan ini


// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Check if user is super admin
if (!isset($_SESSION['kode_karyawan']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../../login_dashboard/login.php');
    exit();
}

$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';

// Get all branches for filter
$query_cabang = "SELECT DISTINCT nama_cabang FROM users WHERE nama_cabang IS NOT NULL AND nama_cabang != ''";
$result_cabang = $pdo->query($query_cabang);
$branches = $result_cabang->fetchAll(PDO::FETCH_ASSOC);

// Handle filter clear
if (isset($_GET['clear_filter'])) {
    unset($_SESSION['filter_tanggal_awal'], $_SESSION['filter_tanggal_akhir'], $_SESSION['filter_cabang'], $_SESSION['filter_jenis']);
}

// Set default filter dates (last 30 days) if not set
if (!isset($_SESSION['filter_tanggal_awal'])) {
    $_SESSION['filter_tanggal_awal'] = date('Y-m-d', strtotime('-30 days'));
    $_SESSION['filter_tanggal_akhir'] = date('Y-m-d');
    $_SESSION['filter_cabang'] = 'all';
    $_SESSION['filter_jenis'] = 'all';
}

// Handle filter submission
if (isset($_POST['filter'])) {
    $_SESSION['filter_tanggal_awal'] = $_POST['tanggal_awal'];
    $_SESSION['filter_tanggal_akhir'] = $_POST['tanggal_akhir'];
    $_SESSION['filter_cabang'] = $_POST['cabang'];
    $_SESSION['filter_jenis'] = $_POST['jenis'];
}

$tanggal_awal = $_SESSION['filter_tanggal_awal'];
$tanggal_akhir = $_SESSION['filter_tanggal_akhir'];
$cabang = $_SESSION['filter_cabang'];
$jenis = $_SESSION['filter_jenis'];

// Query for pemasukan
if ($jenis == 'all' || $jenis == 'pemasukan') {
    $query_pemasukan = "SELECT pp.*, u.nama_karyawan, ma.arti as nama_akun 
                       FROM pemasukan_pusat pp 
                       JOIN users u ON pp.kode_karyawan = u.kode_karyawan 
                       JOIN master_akun ma ON pp.kode_akun = ma.kode_akun 
                       WHERE pp.tanggal BETWEEN ? AND ?";
    
    $params_pemasukan = [$tanggal_awal, $tanggal_akhir];
    
    if ($cabang && $cabang !== 'all') {
        $query_pemasukan .= " AND pp.cabang = ?";
        $params_pemasukan[] = $cabang;
    }
    
    $query_pemasukan .= " ORDER BY pp.tanggal DESC, pp.waktu DESC";
    $stmt_pemasukan = $pdo->prepare($query_pemasukan);
    $stmt_pemasukan->execute($params_pemasukan);
    $result_pemasukan = $stmt_pemasukan->fetchAll(PDO::FETCH_ASSOC);
}

// Query for pengeluaran
if ($jenis == 'all' || $jenis == 'pengeluaran') {
    $query_pengeluaran = "SELECT pp.*, u.nama_karyawan, ma.arti as nama_akun 
                         FROM pengeluaran_pusat pp 
                         JOIN users u ON pp.kode_karyawan = u.kode_karyawan 
                         JOIN master_akun ma ON pp.kode_akun = ma.kode_akun 
                         WHERE pp.tanggal BETWEEN ? AND ?";
    
    $params_pengeluaran = [$tanggal_awal, $tanggal_akhir];
    
    if ($cabang && $cabang !== 'all') {
        $query_pengeluaran .= " AND pp.cabang = ?";
        $params_pengeluaran[] = $cabang;
    }
    
    $query_pengeluaran .= " ORDER BY pp.tanggal DESC, pp.waktu DESC";
    $stmt_pengeluaran = $pdo->prepare($query_pengeluaran);
    $stmt_pengeluaran->execute($params_pengeluaran);
    $result_pengeluaran = $stmt_pengeluaran->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate totals
$total_pemasukan = 0;
$total_pengeluaran = 0;

if (isset($result_pemasukan)) {
    foreach ($result_pemasukan as $row) {
        $total_pemasukan += $row['jumlah'];
    }
}

if (isset($result_pengeluaran)) {
    foreach ($result_pengeluaran as $row) {
        $total_pengeluaran += $row['jumlah'];
    }
}

$saldo = $total_pemasukan - $total_pengeluaran;

// Summary statistics (all time)
$query_summary = "
    SELECT 
        (SELECT COALESCE(SUM(jumlah), 0) FROM pemasukan_pusat) as total_pemasukan_all,
        (SELECT COALESCE(SUM(jumlah), 0) FROM pengeluaran_pusat) as total_pengeluaran_all,
        (SELECT COUNT(*) FROM pemasukan_pusat) as count_pemasukan,
        (SELECT COUNT(*) FROM pengeluaran_pusat) as count_pengeluaran
";
$summary = $pdo->query($query_summary)->fetch(PDO::FETCH_ASSOC);
$saldo_all = $summary['total_pemasukan_all'] - $summary['total_pengeluaran_all'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Keuangan Pusat - Super Admin</title>
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
            width: auto;
            background: #1e293b;
            height: 100vh;
            position: fixed;
            padding: 20px 0;
            transition: width 0.3s ease;
            overflow-y: auto;
            z-index: 1000;
        }
        .sidebar h2 {
            color: white;
            text-align: center;
            padding: 0 20px 20px 20px;
            border-bottom: 1px solid #334155;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 600;
        }
        .sidebar a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            white-space: nowrap;
            transition: all 0.3s ease;
        }
        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        .sidebar a.active {
            background: var(--primary-color);
            color: white;
        }
        .sidebar a i {
            margin-right: 10px;
            width: 18px;
            text-align: center;
        }
        .main-content {
            margin-left: 300px;
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
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }
        .stats-card {
            border-radius: 16px;
            padding: 24px;
            color: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, var(--info-color), #138496);
        }
        .stats-card.income {
            background: linear-gradient(135deg, var(--success-color), #1e7e34);
        }
        .stats-card.expense {
            background: linear-gradient(135deg, var(--danger-color), #c82333);
        }
        .stats-card.balance {
            background: linear-gradient(135deg, var(--warning-color), #e0a800);
            color: var(--text-dark);
        }
        .stats-card.balance.negative {
            background: linear-gradient(135deg, var(--danger-color), #c82333);
            color: white;
        }
        .stats-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .stats-info h4 {
            margin: 0 0 8px 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .stats-info .stats-number {
            font-size: 22px;
            font-weight: bold;
            margin: 0;
        }
        .stats-info .stats-detail {
            font-size: 12px;
            opacity: 0.8;
            margin-top: 4px;
        }
        .stats-icon {
            font-size: 36px;
            opacity: 0.8;
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
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-label {
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 14px;
        }
        .form-control {
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            background: white;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
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
        }
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }
        .btn-success {
            background-color: var(--success-color);
            color: white;
        }
        .btn-success:hover {
            background-color: #1e7e34;
        }
        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
        }
        .btn-secondary:hover {
            background-color: #545b62;
        }
        .btn-warning {
            background-color: var(--warning-color);
            color: var(--text-dark);
        }
        .btn-warning:hover {
            background-color: #e0a800;
        }
        .btn-info {
            background-color: var(--info-color);
            color: white;
        }
        .btn-info:hover {
            background-color: #138496;
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .table-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            overflow: hidden;
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
        .table-wrapper {
            max-height: 600px;
            overflow-y: auto;
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
            position: sticky;
            top: 0;
            z-index: 10;
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
        .text-end {
            text-align: right;
        }
        .text-success {
            color: var(--success-color) !important;
        }
        .text-danger {
            color: var(--danger-color) !important;
        }
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .no-data i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
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
        .alert-info {
            background: rgba(23,162,184,0.1);
            color: var(--info-color);
            border-color: rgba(23,162,184,0.2);
        }
        .print-info {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-top: 30px;
            text-align: center;
            color: var(--text-muted);
            font-size: 12px;
        }
        @media print {
            body { background: white; }
            .sidebar, .no-print { display: none !important; }
            .main-content { margin-left: 0; width: 100%; padding: 20px; }
            .stats-card { break-inside: avoid; }
            .table-card { break-inside: avoid; }
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
                padding: 20px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .filter-grid {
                grid-template-columns: 1fr;
            }
            .filter-actions {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
            .info-tags {
                flex-direction: column;
                gap: 10px;
            }
            .stats-content {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
<div class="sidebar no-print" id="sidebar">
    <h2><i class="fas fa-user-shield"></i> Dashboard Admin</h2>
    <a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="master_akun.php"><i class="fas fa-users-cog"></i> Master Akun</a>
    <a href="keping.php"><i class="fas fa-coins"></i> Master Nominal</a>
    <a href="detail_pemasukan.php"><i class="fas fa-file-invoice-dollar"></i> Detail Pemasukan</a>
    <a href="detail_pengeluaran.php"><i class="fas fa-file-invoice-dollar"></i> Detail Pengeluaran</a>
    <?php if ($is_admin || $is_super_admin): ?>
        <a href="index_kasir.php"><i class="fas fa-cash-register"></i> Dashboard Kasir</a>
    <?php endif; ?>
    <?php if ($is_super_admin): ?>
        <a href="users.php"><i class="fas fa-user-friends"></i> Master User</a>
        <a href="masterkey.php"><i class="fas fa-id-card"></i> Master Karyawan</a>
        <a href="cabang.php"><i class="fas fa-building"></i> Master Cabang</a>
        <a href="setoran_keuangan.php"><i class="fas fa-hand-holding-usd"></i> Manajemen Setoran</a>
        <a href="laporan_setoran.php"><i class="fas fa-chart-line"></i> Laporan Setoran</a>
        <a href="keuangan_pusat.php"><i class="fas fa-wallet"></i> Keuangan Pusat</a>
        <a href="laporan_keuangan_pusat.php" class="active"><i class="fas fa-file-alt"></i> Laporan Keuangan</a>
    <?php endif; ?>
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Kembali ke Dashboard</a>
</div>

<div class="main-content">
    <div class="user-profile no-print">
        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
        <div>
            <strong><?php echo htmlspecialchars($username); ?></strong>
            <p style="color: var(--text-muted); font-size: 12px;">Super Admin</p>
        </div>
    </div>

    <div class="welcome-card">
        <h1><i class="fas fa-file-alt"></i> Laporan Keuangan Pusat</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Analisis komprehensif pemasukan dan pengeluaran dari seluruh cabang</p>
        <div class="info-tags">
            <div class="info-tag"><i class="fas fa-user"></i> User: <?php echo htmlspecialchars($username); ?></div>
            <div class="info-tag"><i class="fas fa-shield-alt"></i> Role: Super Admin</div>
            <div class="info-tag"><i class="fas fa-calendar-day"></i> Tanggal: <?php echo date('d M Y H:i:s'); ?></div>
        </div>
    </div>


    <!-- Filter Form -->
    <div class="form-card no-print">
        <h3><i class="fas fa-filter"></i> Filter Laporan</h3>
        
        <form method="POST" action="">
            <div class="filter-grid">
                <div class="form-group">
                    <label for="tanggal_awal" class="form-label">
                        <i class="fas fa-calendar-alt"></i> Tanggal Awal
                    </label>
                    <input type="date" name="tanggal_awal" id="tanggal_awal" class="form-control" required 
                           value="<?php echo $tanggal_awal; ?>">
                </div>
                
                <div class="form-group">
                    <label for="tanggal_akhir" class="form-label">
                        <i class="fas fa-calendar-check"></i> Tanggal Akhir
                    </label>
                    <input type="date" name="tanggal_akhir" id="tanggal_akhir" class="form-control" required 
                           value="<?php echo $tanggal_akhir; ?>">
                </div>
                
                <div class="form-group">
                    <label for="cabang" class="form-label">
                        <i class="fas fa-building"></i> Cabang
                    </label>
                    <select name="cabang" id="cabang" class="form-control">
                        <option value="all" <?php echo ($cabang == 'all') ? 'selected' : ''; ?>>Semua Cabang</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo htmlspecialchars($branch['nama_cabang']); ?>" 
                                    <?php echo ($cabang == $branch['nama_cabang']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($branch['nama_cabang'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="jenis" class="form-label">
                        <i class="fas fa-tags"></i> Jenis Transaksi
                    </label>
                    <select name="jenis" id="jenis" class="form-control">
                        <option value="all" <?php echo ($jenis == 'all') ? 'selected' : ''; ?>>Semua Jenis</option>
                        <option value="pemasukan" <?php echo ($jenis == 'pemasukan') ? 'selected' : ''; ?>>Pemasukan</option>
                        <option value="pengeluaran" <?php echo ($jenis == 'pengeluaran') ? 'selected' : ''; ?>>Pengeluaran</option>
                    </select>
                </div>
            </div>
            
            <div class="filter-actions">
                <button type="submit" name="filter" class="btn btn-primary">
                    <i class="fas fa-search"></i> Filter
                </button>
                <a href="?clear_filter=1" class="btn btn-secondary">
                    <i class="fas fa-refresh"></i> Reset
                </a>
<button type="button" onclick="window.location.href='export_keuangan_excel.php'" class="btn btn-success">
    <i class="fas fa-file-excel"></i> Export Excel
</button>
                <a href="keuangan_pusat.php" class="btn btn-info">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
            </div>
        </form>
    </div>

    <!-- Filter Results Summary -->
    <div class="form-card">
        <h3><i class="fas fa-chart-bar"></i> Hasil Filter: 
            <?php echo date('d/m/Y', strtotime($tanggal_awal)); ?> - 
            <?php echo date('d/m/Y', strtotime($tanggal_akhir)); ?>
            <?php if ($cabang && $cabang !== 'all'): ?>
                | Cabang: <?php echo ucfirst($cabang); ?>
            <?php endif; ?>
            <?php if ($jenis && $jenis !== 'all'): ?>
                | Jenis: <?php echo ucfirst($jenis); ?>
            <?php endif; ?>
        </h3>
        
        <div class="stats-grid">
            <?php if ($jenis == 'all' || $jenis == 'pemasukan'): ?>
            <div class="stats-card income">
                <div class="stats-content">
                    <div class="stats-info">
                        <h4>Total Pemasukan</h4>
                        <p class="stats-number">Rp <?php echo number_format($total_pemasukan, 0, ',', '.'); ?></p>
                        <p class="stats-detail"><?php echo isset($result_pemasukan) ? count($result_pemasukan) : 0; ?> transaksi</p>
                    </div>
                    <div class="stats-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($jenis == 'all' || $jenis == 'pengeluaran'): ?>
            <div class="stats-card expense">
                <div class="stats-content">
                    <div class="stats-info">
                        <h4>Total Pengeluaran</h4>
                        <p class="stats-number">Rp <?php echo number_format($total_pengeluaran, 0, ',', '.'); ?></p>
                        <p class="stats-detail"><?php echo isset($result_pengeluaran) ? count($result_pengeluaran) : 0; ?> transaksi</p>
                    </div>
                    <div class="stats-icon">
                        <i class="fas fa-minus-circle"></i>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($jenis == 'all'): ?>
            <div class="stats-card balance <?php echo $saldo < 0 ? 'negative' : ''; ?>">
                <div class="stats-content">
                    <div class="stats-info">
                        <h4>Saldo Periode</h4>
                        <p class="stats-number">Rp <?php echo number_format($saldo, 0, ',', '.'); ?></p>
                        <p class="stats-detail"><?php echo $saldo >= 0 ? 'Surplus' : 'Defisit'; ?></p>
                    </div>
                    <div class="stats-icon">
                        <i class="fas fa-balance-scale"></i>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pemasukan Table -->
    <?php if (($jenis == 'all' || $jenis == 'pemasukan') && isset($result_pemasukan) && count($result_pemasukan) > 0): ?>
    <div class="table-card">
        <div class="table-header">
            <h4><i class="fas fa-arrow-up text-success"></i> Data Pemasukan (<?php echo count($result_pemasukan); ?> transaksi)</h4>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Cabang</th>
                        <th>Kode Akun</th>
                        <th>Nama Akun</th>
                        <th>Jumlah</th>
                        <th>Keterangan</th>
                        <th>Input Oleh</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    foreach ($result_pemasukan as $row): 
                    ?>
                    <tr>
                        <td><?php echo $no++; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($row['tanggal'])); ?></td>
                        <td><?php echo $row['waktu']; ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($row['cabang'])); ?></td>
                        <td><?php echo htmlspecialchars($row['kode_akun']); ?></td>
                        <td><?php echo htmlspecialchars($row['nama_akun']); ?></td>
                        <td class="text-end"><strong>Rp <?php echo number_format($row['jumlah'], 0, ',', '.'); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['keterangan']); ?></td>
                        <td><?php echo htmlspecialchars($row['nama_karyawan']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pengeluaran Table -->
    <?php if (($jenis == 'all' || $jenis == 'pengeluaran') && isset($result_pengeluaran) && count($result_pengeluaran) > 0): ?>
    <div class="table-card">
        <div class="table-header">
            <h4><i class="fas fa-arrow-down text-danger"></i> Data Pengeluaran (<?php echo count($result_pengeluaran); ?> transaksi)</h4>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Cabang</th>
                        <th>Kode Akun</th>
                        <th>Nama Akun</th>
                        <th>Kategori</th>
                        <th>Jumlah</th>
                        <th>Keterangan</th>
                        <th>Umur Pakai</th>
                        <th>Input Oleh</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    foreach ($result_pengeluaran as $row): 
                    ?>
                    <tr>
                        <td><?php echo $no++; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($row['tanggal'])); ?></td>
                        <td><?php echo $row['waktu']; ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($row['cabang'])); ?></td>
                        <td><?php echo htmlspecialchars($row['kode_akun']); ?></td>
                        <td><?php echo htmlspecialchars($row['nama_akun']); ?></td>
                        <td><?php echo htmlspecialchars($row['kategori']); ?></td>
                        <td class="text-end"><strong>Rp <?php echo number_format($row['jumlah'], 0, ',', '.'); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['keterangan']); ?></td>
                        <td><?php echo $row['umur_pakai']; ?> bulan</td>
                        <td><?php echo htmlspecialchars($row['nama_karyawan']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- No Data Message -->
    <?php if (((!isset($result_pemasukan) || count($result_pemasukan) == 0) && 
               (!isset($result_pengeluaran) || count($result_pengeluaran) == 0)) ||
               (($jenis == 'pemasukan' && (!isset($result_pemasukan) || count($result_pemasukan) == 0)) ||
                ($jenis == 'pengeluaran' && (!isset($result_pengeluaran) || count($result_pengeluaran) == 0)))): ?>
    <div class="table-card">
        <div class="no-data">
            <i class="fas fa-search"></i>
            <h5>Tidak ada data ditemukan</h5>
            <p>Tidak ada transaksi yang sesuai dengan kriteria filter yang Anda pilih.</p>
            <p>Silakan ubah filter atau periode tanggal untuk melihat data lainnya.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Information Alert -->
    <div class="alert alert-info no-print">
        <i class="fas fa-info-circle"></i>
        <div>
            <strong>Tips:</strong> Gunakan filter tanggal untuk melihat data periode tertentu. 
            Filter cabang untuk melihat transaksi per cabang. Klik tombol Export Excel untuk mengunduh laporan dalam format Excel.
        </div>
    </div>

    <!-- Print Information -->
    <div class="print-info">
        <p>
            <strong>Laporan Keuangan Pusat</strong><br>
            Periode: <?php echo date('d/m/Y', strtotime($tanggal_awal)); ?> - <?php echo date('d/m/Y', strtotime($tanggal_akhir)); ?><br>
            <?php if ($cabang && $cabang !== 'all'): ?>
                Cabang: <?php echo ucfirst($cabang); ?><br>
            <?php endif; ?>
            <?php if ($jenis && $jenis !== 'all'): ?>
                Jenis: <?php echo ucfirst($jenis); ?><br>
            <?php endif; ?>
            Dibuat pada: <?php echo date('d/m/Y H:i:s'); ?> oleh <?php echo htmlspecialchars($username); ?> (Super Admin)
        </p>
    </div>
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

        const minWidth = 280;
        sidebar.style.width = maxWidth > minWidth ? `${maxWidth + 40}px` : `${minWidth}px`;
        document.querySelector('.main-content').style.marginLeft = sidebar.style.width;
    }

    // Run on page load and window resize
    window.addEventListener('load', adjustSidebarWidth);
    window.addEventListener('resize', adjustSidebarWidth);

    // Auto-set end date when start date changes
    document.addEventListener('DOMContentLoaded', function() {
        const tanggalAwal = document.getElementById('tanggal_awal');
        const tanggalAkhir = document.getElementById('tanggal_akhir');
        
        tanggalAwal.addEventListener('change', function() {
            if (this.value && (!tanggalAkhir.value || tanggalAkhir.value < this.value)) {
                tanggalAkhir.value = this.value;
            }
        });
        
        // Validate date range
        tanggalAkhir.addEventListener('change', function() {
            if (this.value && tanggalAwal.value && this.value < tanggalAwal.value) {
                alert('Tanggal akhir tidak boleh lebih kecil dari tanggal awal');
                this.value = tanggalAwal.value;
            }
        });
    });

    // Enhanced print functionality
    function printReport() {
        // Hide no-print elements
        const noPrintElements = document.querySelectorAll('.no-print');
        noPrintElements.forEach(el => el.style.display = 'none');
        
        // Print
        window.print();
        
        // Restore no-print elements
        setTimeout(() => {
            noPrintElements.forEach(el => el.style.display = '');
        }, 1000);
    }

    // Add print shortcut (Ctrl+P)
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            printReport();
        }
    });
</script>

</body>
</html>