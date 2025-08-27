<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$cabang_user = $_SESSION['nama_cabang'] ?? 'Unknown Cabang';

// Get kode_transaksi from URL
if (!isset($_GET['kode_transaksi'])) {
    die("Kode transaksi tidak ditemukan.");
}

$kode_transaksi = $_GET['kode_transaksi'];

// Fetch closing transaction detail
$sql_closing = "
    SELECT 
        kt.*,
        u.nama_karyawan,
        u.nama_cabang
    FROM kasir_transactions kt
    LEFT JOIN users u ON kt.kode_karyawan = u.kode_karyawan
    WHERE kt.kode_transaksi = :kode_transaksi
";

$stmt = $pdo->prepare($sql_closing);
$stmt->bindParam(':kode_transaksi', $kode_transaksi);
$stmt->execute();
$closing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$closing) {
    die("Data transaksi closing tidak ditemukan.");
}

// Get semua pemasukan yang menggunakan closing ini
$sql_pemasukan = "
    SELECT 
        pk.*,
        u.nama_karyawan as kasir_pemasukan,
        kt_pembuat.tanggal_transaksi as tanggal_transaksi_pembuat
    FROM pemasukan_kasir pk
    LEFT JOIN users u ON pk.kode_karyawan = u.kode_karyawan
    LEFT JOIN kasir_transactions kt_pembuat ON pk.kode_transaksi = kt_pembuat.kode_transaksi
    WHERE pk.nomor_transaksi_closing = :kode_transaksi
    ORDER BY pk.tanggal DESC, pk.waktu DESC
";

$stmt_pemasukan = $pdo->prepare($sql_pemasukan);
$stmt_pemasukan->bindParam(':kode_transaksi', $kode_transaksi);
$stmt_pemasukan->execute();
$pemasukan_list = $stmt_pemasukan->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_diambil = array_sum(array_column($pemasukan_list, 'jumlah'));
$sisa_setoran = $closing['setoran_real'] - $total_diambil;

// Get kas awal dan kas akhir
$sql_kas_awal = "SELECT total_nilai FROM kas_awal WHERE kode_transaksi = :kode_transaksi";
$stmt_kas_awal = $pdo->prepare($sql_kas_awal);
$stmt_kas_awal->bindParam(':kode_transaksi', $kode_transaksi);
$stmt_kas_awal->execute();
$kas_awal = $stmt_kas_awal->fetchColumn() ?: 0;

$sql_kas_akhir = "SELECT total_nilai FROM kas_akhir WHERE kode_transaksi = :kode_transaksi";
$stmt_kas_akhir = $pdo->prepare($sql_kas_akhir);
$stmt_kas_akhir->bindParam(':kode_transaksi', $kode_transaksi);
$stmt_kas_akhir->execute();
$kas_akhir = $stmt_kas_akhir->fetchColumn() ?: 0;

// Get pemasukan dan pengeluaran total
$sql_total_pemasukan = "SELECT COALESCE(SUM(jumlah), 0) FROM pemasukan_kasir WHERE kode_transaksi = :kode_transaksi";
$stmt_total_pemasukan = $pdo->prepare($sql_total_pemasukan);
$stmt_total_pemasukan->bindParam(':kode_transaksi', $kode_transaksi);
$stmt_total_pemasukan->execute();
$total_pemasukan_kasir = $stmt_total_pemasukan->fetchColumn() ?: 0;

$sql_total_pengeluaran = "SELECT COALESCE(SUM(jumlah), 0) FROM pengeluaran_kasir WHERE kode_transaksi = :kode_transaksi";
$stmt_total_pengeluaran = $pdo->prepare($sql_total_pengeluaran);
$stmt_total_pengeluaran->bindParam(':kode_transaksi', $kode_transaksi);
$stmt_total_pengeluaran->execute();
$total_pengeluaran_kasir = $stmt_total_pengeluaran->fetchColumn() ?: 0;

function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

function getStatusBadge($status) {
    switch($status) {
        case 'on proses':
            return '<span class="status-badge status-warning">On Proses</span>';
        case 'end proses':
            return '<span class="status-badge status-success">End Proses</span>';
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
        case 'Belum Disetor':
        case '':
        case null:
            return '<span class="status-badge status-secondary">Belum Disetor</span>';
        default:
            return '<span class="status-badge status-secondary">' . htmlspecialchars($status) . '</span>';
    }
}

function getDepositStatusBadge($status) {
    switch($status) {
        case 'Belum Disetor':
        case '':
        case null:
            return '<span class="status-badge status-secondary">Belum Disetor</span>';
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
            return '<span class="status-badge status-secondary">' . htmlspecialchars($status) . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Closing - <?php echo htmlspecialchars($kode_transaksi); ?></title>
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
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        .header-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .header-card h1 {
            font-size: 28px;
            margin-bottom: 8px;
            color: var(--text-dark);
        }
        .header-card .subtitle {
            color: var(--text-muted);
            font-size: 16px;
            margin-bottom: 16px;
        }
        .transaction-badges {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .transaction-badge {
            background: var(--background-light);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        .transaction-badge.primary {
            background: rgba(0,123,255,0.1);
            color: var(--primary-color);
        }
        .transaction-badge.success {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
        }
        .transaction-badge.warning {
            background: rgba(255,193,7,0.1);
            color: #e0a800;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--primary-color);
        }
        .summary-card.success {
            border-left-color: var(--success-color);
        }
        .summary-card.warning {
            border-left-color: var(--warning-color);
        }
        .summary-card.danger {
            border-left-color: var(--danger-color);
        }
        .summary-card h4 {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            font-weight: 600;
        }
        .summary-card .amount {
            font-size: 20px;
            font-weight: bold;
            color: var(--text-dark);
        }
        .summary-card.success .amount {
            color: var(--success-color);
        }
        .summary-card.warning .amount {
            color: #e0a800;
        }
        .summary-card.danger .amount {
            color: var(--danger-color);
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }
        .detail-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
        }
        .detail-card h3 {
            font-size: 18px;
            margin-bottom: 16px;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }
        .detail-item:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 500;
            color: var(--text-muted);
            font-size: 14px;
        }
        .detail-value {
            font-weight: 600;
            color: var(--text-dark);
            text-align: right;
        }
        .detail-value.amount {
            color: var(--success-color);
            font-size: 16px;
        }
        .detail-value.amount.negative {
            color: var(--danger-color);
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
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
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
        .calculation-card {
            background: linear-gradient(135deg, #e3f2fd, #f3e5f5);
            border: 1px solid #1976d2;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .calculation-card h4 {
            color: #1976d2;
            margin-bottom: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .calculation-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px dotted #1976d2;
        }
        .calculation-row:last-child {
            border-bottom: 2px solid #1976d2;
            font-weight: bold;
            font-size: 18px;
            margin-top: 10px;
            padding-top: 15px;
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
        .btn-sm {
            padding: 8px 16px;
            font-size: 12px;
        }
        .actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        .back-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid transparent;
        }
        .alert-warning {
            background: rgba(255,193,7,0.1);
            color: #e0a800;
            border-color: rgba(255,193,7,0.2);
        }
        .alert-info {
            background: rgba(23,162,184,0.1);
            color: var(--info-color);
            border-color: rgba(23,162,184,0.2);
        }
        @media (max-width: 768px) {
            .detail-grid, .summary-grid {
                grid-template-columns: 1fr;
            }
            .detail-item, .calculation-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }
            .detail-value {
                text-align: left;
            }
            .transaction-badges {
                flex-direction: column;
                align-items: flex-start;
            }
            .actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="javascript:history.back()" class="back-link">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>

        <div class="header-card">
            <h1><i class="fas fa-archive"></i> Detail Transaksi Closing</h1>
            <p class="subtitle">Informasi lengkap transaksi closing dan penggunaan dana</p>
            <div class="transaction-badges">
                <div class="transaction-badge primary">
                    <i class="fas fa-barcode"></i> <?php echo htmlspecialchars($kode_transaksi); ?>
                </div>
                <div class="transaction-badge <?php echo $closing['status'] == 'end proses' ? 'success' : 'warning'; ?>">
                    <i class="fas fa-flag"></i> <?php echo strtoupper($closing['status']); ?>
                </div>
                <div class="transaction-badge">
                    <i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($closing['tanggal_transaksi'])); ?>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-grid">
            <div class="summary-card">
                <h4>Setoran Awal</h4>
                <div class="amount"><?php echo formatRupiah($closing['setoran_real']); ?></div>
            </div>
            <div class="summary-card danger">
                <h4>Total Diambil</h4>
                <div class="amount">-<?php echo formatRupiah($total_diambil); ?></div>
            </div>
            <div class="summary-card <?php echo $sisa_setoran >= 0 ? 'success' : 'danger'; ?>">
                <h4>Sisa Setoran</h4>
                <div class="amount"><?php echo formatRupiah($sisa_setoran); ?></div>
            </div>
            <div class="summary-card warning">
                <h4>Jumlah Pemasukan</h4>
                <div class="amount"><?php echo count($pemasukan_list); ?> transaksi</div>
            </div>
        </div>

        <?php if (!empty($pemasukan_list)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Perhatian:</strong> Ada <?php echo count($pemasukan_list); ?> pemasukan yang menggunakan uang dari closing ini. 
            Sisa setoran yang akan disetor ke staff keuangan: <strong><?php echo formatRupiah($sisa_setoran); ?></strong>
        </div>
        <?php endif; ?>

        <div class="detail-grid">
            <!-- Informasi Transaksi -->
            <div class="detail-card">
                <h3><i class="fas fa-info-circle"></i> Informasi Transaksi</h3>
                <div class="detail-item">
                    <span class="detail-label">Kode Transaksi:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($closing['kode_transaksi']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Tanggal Transaksi:</span>
                    <span class="detail-value"><?php echo date('d/m/Y', strtotime($closing['tanggal_transaksi'])); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Tanggal Closing:</span>
                    <span class="detail-value"><?php echo $closing['tanggal_closing'] ? date('d/m/Y', strtotime($closing['tanggal_closing'])) : '-'; ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Jam Closing:</span>
                    <span class="detail-value"><?php echo $closing['jam_closing'] ?: '-'; ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value"><?php echo getStatusBadge($closing['status']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Status Setoran:</span>
                    <span class="detail-value"><?php echo getDepositStatusBadge($closing['deposit_status']); ?></span>
                </div>
            </div>

            <!-- Informasi Kasir -->
            <div class="detail-card">
                <h3><i class="fas fa-user"></i> Informasi Kasir</h3>
                <div class="detail-item">
                    <span class="detail-label">Kode Karyawan:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($closing['kode_karyawan']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Nama Karyawan:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($closing['nama_karyawan']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Kode Cabang:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($closing['kode_cabang']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Nama Cabang:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($closing['nama_cabang']); ?></span>
                </div>
            </div>

            <!-- Data Keuangan -->
            <div class="detail-card">
                <h3><i class="fas fa-calculator"></i> Data Keuangan</h3>
                <div class="detail-item">
                    <span class="detail-label">Kas Awal:</span>
                    <span class="detail-value amount"><?php echo formatRupiah($kas_awal); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Kas Akhir:</span>
                    <span class="detail-value amount"><?php echo formatRupiah($kas_akhir); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Total Pemasukan:</span>
                    <span class="detail-value amount"><?php echo formatRupiah($closing['total_pemasukan']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Total Pengeluaran:</span>
                    <span class="detail-value amount negative">-<?php echo formatRupiah($closing['total_pengeluaran']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Total Penjualan:</span>
                    <span class="detail-value amount"><?php echo formatRupiah($closing['total_penjualan']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Total Servis:</span>
                    <span class="detail-value amount"><?php echo formatRupiah($closing['total_servis']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Omset:</span>
                    <span class="detail-value amount"><?php echo formatRupiah($closing['omset']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Setoran Real:</span>
                    <span class="detail-value amount"><?php echo formatRupiah($closing['setoran_real']); ?></span>
                </div>
            </div>
        </div>

        <!-- Calculation Card -->
        <div class="calculation-card">
            <h4><i class="fas fa-calculator"></i> Perhitungan Setoran Final</h4>
            <div class="calculation-row">
                <span>Setoran Closing Awal:</span>
                <span><?php echo formatRupiah($closing['setoran_real']); ?></span>
            </div>
            <div class="calculation-row">
                <span>Dikurangi: Total Diambil untuk Pemasukan:</span>
                <span style="color: var(--danger-color);">-<?php echo formatRupiah($total_diambil); ?></span>
            </div>
            <div class="calculation-row">
                <span><strong>Sisa Setoran yang Akan Disetor:</strong></span>
                <span style="color: <?php echo $sisa_setoran >= 0 ? 'var(--success-color)' : 'var(--danger-color)'; ?>;">
                    <strong><?php echo formatRupiah($sisa_setoran); ?></strong>
                </span>
            </div>
        </div>

        <?php if (!empty($pemasukan_list)): ?>
        <!-- Pemasukan yang Menggunakan Closing Ini -->
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th colspan="7" style="background: var(--danger-color); color: white;">
                            <i class="fas fa-minus-circle"></i> Pemasukan yang Menggunakan Closing Ini
                        </th>
                    </tr>
                    <tr>
                        <th>ID</th>
                        <th>Tanggal</th>
                        <th>Kasir Pemasukan</th>
                        <th>Kode Transaksi</th>
                        <th>Jumlah Diambil</th>
                        <th>Keterangan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pemasukan_list as $pemasukan): ?>
                        <tr>
                            <td><strong>#<?php echo $pemasukan['id']; ?></strong></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($pemasukan['tanggal'] . ' ' . $pemasukan['waktu'])); ?></td>
                            <td><?php echo htmlspecialchars($pemasukan['kasir_pemasukan']); ?></td>
                            <td><?php echo htmlspecialchars($pemasukan['kode_transaksi']); ?></td>
                            <td style="color: var(--danger-color); font-weight: 600;">
                                -<?php echo formatRupiah($pemasukan['jumlah']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($pemasukan['keterangan_transaksi']); ?></td>
                            <td>
                                <a href="detail_pemasukan.php?id=<?php echo $pemasukan['id']; ?>" class="btn btn-primary btn-sm" target="_blank">
                                    <i class="fas fa-eye"></i> Detail
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr style="background: var(--background-light); font-weight: bold;">
                        <td colspan="4">Total Diambil:</td>
                        <td style="color: var(--danger-color);">-<?php echo formatRupiah($total_diambil); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <strong>Info:</strong> Belum ada pemasukan yang menggunakan uang dari closing ini. 
            Seluruh setoran <?php echo formatRupiah($closing['setoran_real']); ?> masih utuh.
        </div>
        <?php endif; ?>

        <div class="actions">
            <a href="pemasukan_kasir.php?kode_transaksi=<?php echo htmlspecialchars($kode_transaksi); ?>" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Lihat Pemasukan Transaksi
            </a>
            <a href="pengeluaran_kasir.php?kode_transaksi=<?php echo htmlspecialchars($kode_transaksi); ?>" class="btn btn-secondary">
                <i class="fas fa-minus-circle"></i> Lihat Pengeluaran Transaksi
            </a>
            <a href="setoran_keuangan_cs.php" class="btn btn-secondary">
                <i class="fas fa-money-bill"></i> Kelola Setoran
            </a>
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <style>
        @media print {
            .actions, .back-link {
                display: none !important;
            }
            body {
                background: white !important;
            }
            .detail-card, .header-card, .table-container, .calculation-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                break-inside: avoid;
            }
            .calculation-card {
                page-break-inside: avoid;
            }
        }
    </style>
</body>
</html>