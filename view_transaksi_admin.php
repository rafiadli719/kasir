<?php
session_start();
include 'config.php'; // Include your database connection

// Ensure the user is logged in and has either the 'admin' or 'super_admin' role
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
header('Location: ../../login_dashboard/login.php');
    exit;
}

// Koneksi ke database fitmotor_maintance-beta
$pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$kode_transaksi = $_GET['kode_transaksi'] ?? null; // Get transaction code from URL

// Check if the transaction exists and fetch data
$sql = "
    SELECT 
        kt.*, 
        (SELECT SUM(jumlah_penjualan) FROM data_penjualan WHERE kode_transaksi = :kode_transaksi) AS data_penjualan,
        (SELECT SUM(jumlah_servis) FROM data_servis WHERE kode_transaksi = :kode_transaksi) AS data_servis,
        (SELECT SUM(jumlah) FROM pengeluaran_kasir WHERE kode_transaksi = :kode_transaksi) AS total_pengeluaran,
        (SELECT SUM(jumlah) FROM pemasukan_kasir WHERE kode_transaksi = :kode_transaksi) AS total_pemasukan,
        ka.total_nilai AS kas_awal,
        kcl.total_nilai AS kas_akhir,
        ka.tanggal AS kas_awal_date,
        kcl.tanggal AS kas_akhir_date,
        ka.waktu AS kas_awal_time,
        kcl.waktu AS kas_akhir_time,
        kt.tanggal_closing,      -- Get the closing date
        kt.jam_closing,          -- Get the closing time
        u.nama_karyawan AS kasir_name,
        kt.nama_cabang AS kasir_cabang
    FROM kasir_transactions kt
    LEFT JOIN kas_awal ka ON ka.kode_transaksi = kt.kode_transaksi
    LEFT JOIN kas_akhir kcl ON kcl.kode_transaksi = kt.kode_transaksi
    LEFT JOIN users u ON u.kode_karyawan = kt.kode_karyawan
    WHERE kt.kode_transaksi = :kode_transaksi
";
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt->execute();
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    die("Transaksi tidak ditemukan.");
}

// Fetch pemasukan data
$pemasukan_stmt = $pdo->prepare("SELECT * FROM pemasukan_kasir WHERE kode_transaksi = :kode_transaksi");
$pemasukan_stmt->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$pemasukan_stmt->execute();
$pemasukan_kasir = $pemasukan_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch pengeluaran data
$pengeluaran_stmt = $pdo->prepare("SELECT * FROM pengeluaran_kasir WHERE kode_transaksi = :kode_transaksi");
$pengeluaran_stmt->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$pengeluaran_stmt->execute();
$pengeluaran_kasir = $pengeluaran_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate additional values with default "Tidak ada transaksi" for missing data
$data_penjualan = $transaction['data_penjualan'] ?? 0;
$data_servis = $transaction['data_servis'] ?? 0;
$total_pemasukan = $transaction['total_pemasukan'] ?? 0;
$total_pengeluaran = $transaction['total_pengeluaran'] ?? 0;

$omset = $data_penjualan + $data_servis;
$total_uang_di_kasir = $transaction['kas_akhir'];
$kas_awal = $transaction['kas_awal'];
$setoran_real = $total_uang_di_kasir - $kas_awal;
$data_setoran = $omset + $total_pemasukan - $total_pengeluaran;
$selisih_setoran = $setoran_real - $data_setoran;

// Fetch nominal and keping data for Kas Awal and Kas Akhir
$sql_kas_awal_detail = "
    SELECT nominal, SUM(jumlah_keping) as jumlah_keping
    FROM detail_kas_awal 
    WHERE kode_transaksi = :kode_transaksi
    GROUP BY nominal";
$stmt_kas_awal_detail = $pdo->prepare($sql_kas_awal_detail);
$stmt_kas_awal_detail->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_kas_awal_detail->execute();
$kas_awal_detail = $stmt_kas_awal_detail->fetchAll(PDO::FETCH_ASSOC);

$sql_kas_akhir_detail = "
    SELECT nominal, SUM(jumlah_keping) as jumlah_keping
    FROM detail_kas_akhir 
    WHERE kode_transaksi = :kode_transaksi
    GROUP BY nominal";
$stmt_kas_akhir_detail = $pdo->prepare($sql_kas_akhir_detail);
$stmt_kas_akhir_detail->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_kas_akhir_detail->execute();
$kas_akhir_detail = $stmt_kas_akhir_detail->fetchAll(PDO::FETCH_ASSOC);

$bulan = [
    1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

    $tanggal = date('d', strtotime($transaction['kas_awal_date']));
    $bulanNama = $bulan[(int)date('m', strtotime($transaction['kas_awal_date']))];
    $tahun = date('Y', strtotime($transaction['kas_awal_date']));
    $formattedDate = "$tanggal $bulanNama $tahun";
    
    // Fetch pengeluaran data separated by categories
$pengeluaran_stmt_biaya = $pdo->prepare("SELECT * FROM pengeluaran_kasir WHERE kode_transaksi = :kode_transaksi AND kategori = 'biaya'");
$pengeluaran_stmt_biaya->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$pengeluaran_stmt_biaya->execute();
$pengeluaran_biaya = $pengeluaran_stmt_biaya->fetchAll(PDO::FETCH_ASSOC);

$pengeluaran_stmt_non_biaya = $pdo->prepare("SELECT * FROM pengeluaran_kasir WHERE kode_transaksi = :kode_transaksi AND kategori = 'non_biaya'");
$pengeluaran_stmt_non_biaya->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$pengeluaran_stmt_non_biaya->execute();
$pengeluaran_non_biaya = $pengeluaran_stmt_non_biaya->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals for each category
$total_biaya = array_sum(array_column($pengeluaran_biaya, 'jumlah'));
$total_non_biaya = array_sum(array_column($pengeluaran_non_biaya, 'jumlah'));

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Transaksi Kasir - Admin Dashboard</title>
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
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        .header-card {
            background: linear-gradient(135deg, var(--primary-color), #0056b3);
            color: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0,123,255,0.2);
        }
        .header-card h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 15px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .info-item {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }
        .info-item strong {
            display: block;
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        .info-item span {
            font-size: 16px;
            font-weight: 600;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }
        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
            border: 1px solid var(--border-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .stats-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .stats-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .stats-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .stats-icon.primary { background: rgba(0,123,255,0.1); color: var(--primary-color); }
        .stats-icon.success { background: rgba(40,167,69,0.1); color: var(--success-color); }
        .stats-icon.danger { background: rgba(220,53,69,0.1); color: var(--danger-color); }
        .stats-icon.warning { background: rgba(255,193,7,0.1); color: var(--warning-color); }
        .stats-icon.info { background: rgba(23,162,184,0.1); color: var(--info-color); }
        .section-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
            border: 1px solid var(--border-color);
        }
        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--background-light);
        }
        .section-header h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-dark);
        }
        .section-header i {
            font-size: 20px;
            color: var(--primary-color);
        }
        .table-wrapper {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            background: white;
        }
        .table th {
            background: var(--background-light);
            padding: 16px 12px;
            text-align: left;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 14px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
        .table td {
            padding: 14px 12px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
            vertical-align: middle;
        }
        .table tbody tr:hover {
            background: var(--background-light);
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .amount-cell {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            text-align: right;
        }
        .amount-positive {
            color: var(--success-color);
        }
        .amount-negative {
            color: var(--danger-color);
        }
        .amount-neutral {
            color: var(--info-color);
        }
        .total-row {
            background: var(--background-light);
            font-weight: 600;
        }
        .total-row td {
            border-top: 2px solid var(--border-color);
            padding: 16px 12px;
        }
        .category-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .badge-biaya {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
        }
        .badge-non-biaya {
            background: rgba(23,162,184,0.1);
            color: var(--info-color);
        }
        .kas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }
        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
            margin-top: 30px;
            padding: 20px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
            border: 1px solid var(--border-color);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            min-width: 150px;
            justify-content: center;
        }
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-2px);
        }
        .btn-success {
            background: var(--success-color);
            color: white;
        }
        .btn-success:hover {
            background: #1e7e34;
            transform: translateY(-2px);
        }
        .btn-danger {
            background: var(--danger-color);
            color: white;
        }
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }
        .btn-secondary:hover {
            background: #545b62;
            transform: translateY(-2px);
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
            font-style: italic;
        }
        .highlight-positive {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
            font-weight: 600;
        }
        .highlight-negative {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
            font-weight: 600;
        }
        .highlight-neutral {
            background: rgba(23,162,184,0.1);
            color: var(--info-color);
            font-weight: 600;
        }
        @media print {
            body { background: white; }
            .action-buttons { display: none !important; }
            .section-card, .stats-card { box-shadow: none; border: 1px solid #ddd; }
            .header-card { background: #f8f9fa !important; color: #333 !important; }
        }
        @media (max-width: 768px) {
            .container { padding: 20px 10px; }
            .header-info { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr; }
            .kas-grid { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
            .btn { width: 100%; }
            .table-wrapper { font-size: 12px; }
            .table th, .table td { padding: 8px 6px; }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Header Card -->
    <div class="header-card">
        <h1><i class="fas fa-receipt"></i> CLOSING <?php echo strtoupper($transaction['kasir_cabang']); ?> <?php echo $formattedDate; ?></h1>
        <div class="header-info">
            <div class="info-item">
                <strong><i class="fas fa-user"></i> Nama Kasir</strong>
                <span><?php echo htmlspecialchars($transaction['kasir_name']); ?></span>
            </div>
            <div class="info-item">
                <strong><i class="fas fa-building"></i> Cabang</strong>
                <span><?php echo htmlspecialchars($transaction['kasir_cabang']); ?></span>
            </div>
            <div class="info-item">
                <strong><i class="fas fa-barcode"></i> Kode Transaksi</strong>
                <span><?php echo htmlspecialchars($kode_transaksi); ?></span>
            </div>
        </div>
    </div>

    <!-- Statistics Grid -->
    <div class="stats-grid">
        <div class="stats-card">
            <div class="stats-header">
                <div class="stats-title">
                    <i class="fas fa-chart-line"></i> Total Omset
                </div>
                <div class="stats-icon success">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
            </div>
            <div class="amount-cell amount-positive" style="font-size: 24px;">
                Rp <?php echo number_format($omset, 0, ',', '.'); ?>
            </div>
            <div style="font-size: 14px; color: var(--text-muted); margin-top: 8px;">
                Penjualan + Servis
            </div>
        </div>

        <div class="stats-card">
            <div class="stats-header">
                <div class="stats-title">
                    <i class="fas fa-wallet"></i> Saldo Kas
                </div>
                <div class="stats-icon info">
                    <i class="fas fa-coins"></i>
                </div>
            </div>
            <div class="amount-cell amount-neutral" style="font-size: 24px;">
                Rp <?php echo number_format($total_uang_di_kasir, 0, ',', '.'); ?>
            </div>
            <div style="font-size: 14px; color: var(--text-muted); margin-top: 8px;">
                Kas Akhir
            </div>
        </div>

        <div class="stats-card">
            <div class="stats-header">
                <div class="stats-title">
                    <i class="fas fa-balance-scale"></i> Selisih Setoran
                </div>
                <div class="stats-icon <?php echo $selisih_setoran >= 0 ? 'success' : 'danger'; ?>">
                    <i class="fas fa-<?php echo $selisih_setoran >= 0 ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                </div>
            </div>
            <div class="amount-cell <?php echo $selisih_setoran >= 0 ? 'amount-positive' : 'amount-negative'; ?>" style="font-size: 24px;">
                Rp <?php echo number_format($selisih_setoran, 0, ',', '.'); ?>
            </div>
            <div style="font-size: 14px; color: var(--text-muted); margin-top: 8px;">
                Real - Data
            </div>
        </div>
    </div>

    <!-- Transaction Date and Time -->
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-clock"></i>
            <h2>Tanggal dan Jam Transaksi</h2>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Jenis</th>
                        <th>Tanggal</th>
                        <th>Jam</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Kas Awal</strong></td>
                        <td><?php echo isset($transaction['kas_awal_date']) ? date('d M Y', strtotime($transaction['kas_awal_date'])) : 'Tidak ada transaksi'; ?></td>
                        <td><?php echo isset($transaction['kas_awal_time']) ? date('H:i:s', strtotime($transaction['kas_awal_time'])) : 'Tidak ada transaksi'; ?></td>
                        <td><span class="category-badge badge-non-biaya">Buka Kasir</span></td>
                    </tr>
                    <tr>
                        <td><strong>Kas Akhir</strong></td>
                        <td><?php echo isset($transaction['kas_akhir_date']) ? date('d M Y', strtotime($transaction['kas_akhir_date'])) : 'Tidak ada transaksi'; ?></td>
                        <td><?php echo isset($transaction['kas_akhir_time']) ? date('H:i:s', strtotime($transaction['kas_akhir_time'])) : 'Tidak ada transaksi'; ?></td>
                        <td><span class="category-badge badge-non-biaya">Tutup Kasir</span></td>
                    </tr>
                    <tr>
                        <td><strong>Closing</strong></td>
                        <td><?php echo isset($transaction['tanggal_closing']) ? date('d M Y', strtotime($transaction['tanggal_closing'])) : 'Tidak ada transaksi'; ?></td>
                        <td><?php echo isset($transaction['jam_closing']) ? date('H:i:s', strtotime($transaction['jam_closing'])) : 'Tidak ada transaksi'; ?></td>
                        <td><span class="category-badge badge-biaya">Final</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Data Sistem Aplikasi -->
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-desktop"></i>
            <h2>Data Sistem Aplikasi</h2>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Jenis</th>
                        <th>Nilai</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Omset Penjualan</strong></td>
                        <td class="amount-cell amount-positive">Rp <?php echo $data_penjualan ? number_format($data_penjualan, 0, ',', '.') : '0'; ?></td>
                        <td><?php echo $data_penjualan ? 'Ada transaksi' : 'Tidak ada transaksi'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Omset Servis</strong></td>
                        <td class="amount-cell amount-positive">Rp <?php echo $data_servis ? number_format($data_servis, 0, ',', '.') : '0'; ?></td>
                        <td><?php echo $data_servis ? 'Ada transaksi' : 'Tidak ada transaksi'; ?></td>
                    </tr>
                    <tr class="total-row">
                        <td><strong>Jumlah Omset</strong></td>
                        <td class="amount-cell amount-positive"><strong>Rp <?php echo number_format($omset, 0, ',', '.'); ?></strong></td>
                        <td><strong>Total Penjualan + Servis</strong></td>
                    </tr>
                    <tr>
                        <td><strong>Pemasukan Kasir</strong></td>
                        <td class="amount-cell amount-positive">Rp <?php echo $total_pemasukan ? number_format($total_pemasukan, 0, ',', '.') : '0'; ?></td>
                        <td><?php echo $total_pemasukan ? 'Ada transaksi' : 'Tidak ada transaksi'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Pengeluaran Kasir</strong></td>
                        <td class="amount-cell amount-negative">Rp <?php echo $total_pengeluaran ? number_format($total_pengeluaran, 0, ',', '.') : '0'; ?></td>
                        <td><?php echo $total_pengeluaran ? 'Ada transaksi' : 'Tidak ada transaksi'; ?></td>
                    </tr>
                    <tr class="total-row">
                        <td><strong>Data Setoran</strong></td>
                        <td class="amount-cell amount-neutral"><strong>Rp <?php echo number_format($data_setoran, 0, ',', '.'); ?></strong></td>
                        <td><strong>Omset + Pemasukan - Pengeluaran</strong></td>
                    </tr>
                    <tr class="<?php echo $selisih_setoran >= 0 ? 'highlight-positive' : 'highlight-negative'; ?>">
                        <td><strong>Selisih Setoran (REAL-DATA)</strong></td>
                        <td class="amount-cell"><strong>Rp <?php echo number_format($selisih_setoran, 0, ',', '.'); ?></strong></td>
                        <td><strong><?php echo $selisih_setoran >= 0 ? 'Surplus' : 'Defisit'; ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Riil Uang Section -->
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-money-bills"></i>
            <h2>Riil Uang</h2>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Jenis</th>
                        <th>Nilai</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Kas Awal</strong></td>
                        <td class="amount-cell amount-neutral">Rp <?php echo number_format($transaction['kas_awal'], 0, ',', '.'); ?></td>
                        <td>Uang awal kasir</td>
                    </tr>
                    <tr>
                        <td><strong>Kas Akhir</strong></td>
                        <td class="amount-cell amount-neutral">Rp <?php echo number_format($transaction['kas_akhir'], 0, ',', '.'); ?></td>
                        <td>Uang akhir kasir</td>
                    </tr>
                    <tr class="total-row">
                        <td><strong>Setoran Riil</strong></td>
                        <td class="amount-cell amount-positive"><strong>Rp <?php echo number_format($setoran_real, 0, ',', '.'); ?></strong></td>
                        <td><strong>Kas Akhir - Kas Awal</strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pemasukan Kasir -->
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-arrow-up"></i>
            <h2>Pemasukan Kasir</h2>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode Transaksi</th>
                        <th>Kode Akun</th>
                        <th>Jumlah (Rp)</th>
                        <th>Keterangan</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pemasukan_kasir): ?>
                        <?php $no = 1; foreach ($pemasukan_kasir as $pemasukan): ?>
                            <tr>
                                <td><?php echo $no++; ?></td>
                                <td><code><?php echo htmlspecialchars($pemasukan['kode_transaksi']); ?></code></td>
                                <td><code><?php echo htmlspecialchars($pemasukan['kode_akun']); ?></code></td>
                                <td class="amount-cell amount-positive">Rp <?php echo number_format($pemasukan['jumlah'], 0, ',', '.'); ?></td>
                                <td><?php echo htmlspecialchars($pemasukan['keterangan_transaksi']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($pemasukan['tanggal'])); ?></td>
                                <td><?php echo htmlspecialchars($pemasukan['waktu']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="3"><strong>Total Pemasukan</strong></td>
                            <td class="amount-cell amount-positive"><strong>Rp <?php echo number_format($total_pemasukan, 0, ',', '.'); ?></strong></td>
                            <td colspan="3"></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="no-data">
                                <i class="fas fa-info-circle"></i> Tidak ada data pemasukan tercatat.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pengeluaran Kasir -->
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-arrow-down"></i>
            <h2>Pengeluaran Kasir (Semua Kategori)</h2>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode Transaksi</th>
                        <th>Kode Akun</th>
                        <th>Kategori</th>
                        <th>Jumlah (Rp)</th>
                        <th>Keterangan</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Umur Pakai</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pengeluaran_kasir): ?>
                        <?php $no = 1; foreach ($pengeluaran_kasir as $pengeluaran): ?>
                            <tr>
                                <td><?php echo $no++; ?></td>
                                <td><code><?php echo htmlspecialchars($pengeluaran['kode_transaksi']); ?></code></td>
                                <td><code><?php echo htmlspecialchars($pengeluaran['kode_akun']); ?></code></td>
                                <td>
                                    <span class="category-badge <?php echo $pengeluaran['kategori'] == 'biaya' ? 'badge-biaya' : 'badge-non-biaya'; ?>">
                                        <?php echo htmlspecialchars($pengeluaran['kategori']); ?>
                                    </span>
                                </td>
                                <td class="amount-cell amount-negative">Rp <?php echo number_format($pengeluaran['jumlah'], 0, ',', '.'); ?></td>
                                <td><?php echo htmlspecialchars($pengeluaran['keterangan_transaksi']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($pengeluaran['tanggal'])); ?></td>
                                <td><?php echo htmlspecialchars($pengeluaran['waktu']); ?></td>
                                <td><?php echo htmlspecialchars($pengeluaran['umur_pakai']); ?> Bulan</td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="4"><strong>Total Pengeluaran</strong></td>
                            <td class="amount-cell amount-negative"><strong>Rp <?php echo number_format($total_pengeluaran, 0, ',', '.'); ?></strong></td>
                            <td colspan="4"></td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="no-data">
                                <i class="fas fa-info-circle"></i> Tidak ada data pengeluaran tercatat.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pengeluaran Biaya -->
    <?php if (!empty($pengeluaran_biaya)): ?>
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-exclamation-triangle"></i>
            <h2>Pengeluaran Biaya</h2>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode Transaksi</th>
                        <th>Kode Akun</th>
                        <th>Kategori</th>
                        <th>Jumlah (Rp)</th>
                        <th>Keterangan</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Umur Pakai</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($pengeluaran_biaya as $pengeluaran): ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><code><?php echo htmlspecialchars($pengeluaran['kode_transaksi']); ?></code></td>
                            <td><code><?php echo htmlspecialchars($pengeluaran['kode_akun']); ?></code></td>
                            <td><span class="category-badge badge-biaya"><?php echo htmlspecialchars($pengeluaran['kategori']); ?></span></td>
                            <td class="amount-cell amount-negative">Rp <?php echo number_format($pengeluaran['jumlah'], 0, ',', '.'); ?></td>
                            <td><?php echo htmlspecialchars($pengeluaran['keterangan_transaksi']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($pengeluaran['tanggal'])); ?></td>
                            <td><?php echo htmlspecialchars($pengeluaran['waktu']); ?></td>
                            <td><?php echo htmlspecialchars($pengeluaran['umur_pakai']); ?> Bulan</td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="4"><strong>Total Pengeluaran Biaya</strong></td>
                        <td class="amount-cell amount-negative"><strong>Rp <?php echo number_format($total_biaya, 0, ',', '.'); ?></strong></td>
                        <td colspan="4"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pengeluaran Non-Biaya -->
    <?php if (!empty($pengeluaran_non_biaya)): ?>
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-info-circle"></i>
            <h2>Pengeluaran Non-Biaya</h2>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode Transaksi</th>
                        <th>Kode Akun</th>
                        <th>Kategori</th>
                        <th>Jumlah (Rp)</th>
                        <th>Keterangan</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Umur Pakai</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($pengeluaran_non_biaya as $pengeluaran): ?>
                        <tr>
                            <td><?php echo $no++; ?></td>
                            <td><code><?php echo htmlspecialchars($pengeluaran['kode_transaksi']); ?></code></td>
                            <td><code><?php echo htmlspecialchars($pengeluaran['kode_akun']); ?></code></td>
                            <td><span class="category-badge badge-non-biaya"><?php echo htmlspecialchars($pengeluaran['kategori']); ?></span></td>
                            <td class="amount-cell amount-negative">Rp <?php echo number_format($pengeluaran['jumlah'], 0, ',', '.'); ?></td>
                            <td><?php echo htmlspecialchars($pengeluaran['keterangan_transaksi']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($pengeluaran['tanggal'])); ?></td>
                            <td><?php echo htmlspecialchars($pengeluaran['waktu']); ?></td>
                            <td><?php echo htmlspecialchars($pengeluaran['umur_pakai']); ?> Bulan</td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="4"><strong>Total Pengeluaran Non-Biaya</strong></td>
                        <td class="amount-cell amount-negative"><strong>Rp <?php echo number_format($total_non_biaya, 0, ',', '.'); ?></strong></td>
                        <td colspan="4"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Data Kas Awal and Data Kas Akhir side by side -->
    <div class="kas-grid">
        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-play-circle"></i>
                <h2>Data Kas Awal</h2>
            </div>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nominal</th>
                            <th>Keping</th>
                            <th>Total Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($kas_awal_detail)): ?>
                            <?php foreach ($kas_awal_detail as $row): ?>
                                <tr>
                                    <td>Rp <?php echo number_format($row['nominal'], 0, ',', '.'); ?></td>
                                    <td><?php echo number_format($row['jumlah_keping']); ?> keping</td>
                                    <td class="amount-cell amount-neutral">Rp <?php echo number_format($row['nominal'] * $row['jumlah_keping'], 0, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td colspan="2"><strong>Total Kas Awal</strong></td>
                                <td class="amount-cell amount-neutral"><strong>Rp <?php echo number_format($kas_awal, 0, ',', '.'); ?></strong></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="no-data">
                                    <i class="fas fa-info-circle"></i> Tidak ada data kas awal.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section-card">
            <div class="section-header">
                <i class="fas fa-stop-circle"></i>
                <h2>Data Kas Akhir</h2>
            </div>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nominal</th>
                            <th>Keping</th>
                            <th>Total Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($kas_akhir_detail)): ?>
                            <?php foreach ($kas_akhir_detail as $row): ?>
                                <tr>
                                    <td>Rp <?php echo number_format($row['nominal'], 0, ',', '.'); ?></td>
                                    <td><?php echo number_format($row['jumlah_keping']); ?> keping</td>
                                    <td class="amount-cell amount-neutral">Rp <?php echo number_format($row['nominal'] * $row['jumlah_keping'], 0, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="total-row">
                                <td colspan="2"><strong>Total Kas Akhir</strong></td>
                                <td class="amount-cell amount-neutral"><strong>Rp <?php echo number_format($total_uang_di_kasir, 0, ',', '.'); ?></strong></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="no-data">
                                    <i class="fas fa-info-circle"></i> Tidak ada data kas akhir.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <form method="post" action="ganerate_pdf_admin.php" style="display: inline;">
            <input type="hidden" name="kode_transaksi" value="<?php echo htmlspecialchars($kode_transaksi); ?>">
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-file-pdf"></i> Unduh PDF
            </button>
        </form>
        
        <form method="post" action="ganerate_excel_admin.php" style="display: inline;">
            <input type="hidden" name="kode_transaksi" value="<?php echo htmlspecialchars($kode_transaksi); ?>">
            <button type="submit" class="btn btn-success">
                <i class="fas fa-file-excel"></i> Unduh Excel
            </button>
        </form>
        
        <button class="btn btn-primary" onclick="window.print()">
            <i class="fas fa-print"></i> Cetak Laporan
        </button>
        
        <button class="btn btn-secondary" onclick="window.location.href='admin_dashboard.php'">
            <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
        </button>
    </div>
</div>

<script>
    // Enhanced print functionality
    function enhancedPrint() {
        // Create a new window for printing
        const printWindow = window.open('', '_blank');
        const content = document.documentElement.outerHTML;
        
        printWindow.document.write(content);
        printWindow.document.close();
        
        // Wait for content to load then print
        printWindow.onload = function() {
            printWindow.print();
            printWindow.close();
        };
    }

    // Add keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+P for print
        if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            window.print();
        }
        
        // Ctrl+E for Excel export
        if (e.ctrlKey && e.key === 'e') {
            e.preventDefault();
            document.querySelector('form[action="ganerate_excel_admin.php"] button').click();
        }
        
        // Escape to go back
        if (e.key === 'Escape') {
            window.location.href = 'admin_dashboard.php';
        }
    });

    // Add smooth scrolling for better UX
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Add loading states for forms
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            const button = this.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
                
                // Re-enable after 5 seconds as fallback
                setTimeout(() => {
                    button.disabled = false;
                    button.innerHTML = button.innerHTML.replace('Memproses...', button.textContent);
                }, 5000);
            }
        });
    });
</script>

</body>
</html>

<?php
// Close the connection
$pdo = null;
?>