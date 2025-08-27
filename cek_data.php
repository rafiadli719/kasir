<?php
session_start();
include 'config.php'; // Koneksi ke database

// Pastikan user sudah login
if (!isset($_SESSION['kode_karyawan'])) {
header('Location: ../../login_dashboard/login.php');
    exit;
}

// Ambil kode transaksi dari URL
if (isset($_GET['kode_transaksi'])) {
    $kode_transaksi = $_GET['kode_transaksi'];
} else {
    die("Kode transaksi tidak ditemukan.");
}

// Ambil total uang di kasir dari tabel kas_akhir
$sql_kas_akhir = "SELECT total_nilai FROM kas_akhir WHERE kode_transaksi = :kode_transaksi";
$stmt_kas_akhir = $pdo->prepare($sql_kas_akhir);
$stmt_kas_akhir->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_kas_akhir->execute();
$total_uang_di_kasir = $stmt_kas_akhir->fetchColumn();

// Ambil kas awal dari tabel kas_awal
$sql_kas_awal = "SELECT total_nilai FROM kas_awal WHERE kode_transaksi = :kode_transaksi";
$stmt_kas_awal = $pdo->prepare($sql_kas_awal);
$stmt_kas_awal->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_kas_awal->execute();
$kas_awal = $stmt_kas_awal->fetchColumn();

// Setoran real dihitung dari kas awal - total uang di kasir
$setoran_real = $total_uang_di_kasir - $kas_awal;

// Ambil data penjualan dari tabel data_penjualan
$sql_penjualan = "SELECT SUM(jumlah_penjualan) as total_penjualan FROM data_penjualan WHERE kode_transaksi = :kode_transaksi";
$stmt_penjualan = $pdo->prepare($sql_penjualan);
$stmt_penjualan->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_penjualan->execute();
$data_penjualan = $stmt_penjualan->fetchColumn();

// Ambil data servis dari tabel data_servis
$sql_servis = "SELECT SUM(jumlah_servis) as total_servis FROM data_servis WHERE kode_transaksi = :kode_transaksi";
$stmt_servis = $pdo->prepare($sql_servis);
$stmt_servis->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_servis->execute();
$data_servis = $stmt_servis->fetchColumn();

// Omset dihitung dari penjualan + servis
$omset = $data_penjualan + $data_servis;

// Ambil pengeluaran dari kasir dari tabel pengeluaran_kasir
// Negative values in pengeluaran will reduce total pengeluaran
$sql_pengeluaran = "SELECT SUM(jumlah) as total_pengeluaran FROM pengeluaran_kasir WHERE kode_transaksi = :kode_transaksi";
$stmt_pengeluaran = $pdo->prepare($sql_pengeluaran);
$stmt_pengeluaran->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pengeluaran->execute();
$pengeluaran_dari_kasir = $stmt_pengeluaran->fetchColumn();

// Ambil uang masuk ke kasir dari tabel pemasukan_kasir
// Negative values in pemasukan will reduce total pemasukan
$sql_pemasukan = "SELECT SUM(jumlah) as total_pemasukan FROM pemasukan_kasir WHERE kode_transaksi = :kode_transaksi";
$stmt_pemasukan = $pdo->prepare($sql_pemasukan);
$stmt_pemasukan->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pemasukan->execute();
$uang_masuk_ke_kasir = $stmt_pemasukan->fetchColumn();

// Data setoran dihitung dari omset - pengeluaran + pemasukan
$setoran_data = ($omset - $pengeluaran_dari_kasir) + $uang_masuk_ke_kasir;

// Selisih setoran (REAL-DATA) dihitung dari setoran real - setoran data
$selisih_setoran = $setoran_real - $setoran_data;

// Ambil informasi karyawan
$kode_karyawan = $_SESSION['kode_karyawan'];
$sql_nama_karyawan = "SELECT nama_karyawan, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan";
$stmt_nama_karyawan = $pdo->prepare($sql_nama_karyawan);
$stmt_nama_karyawan->bindParam(':kode_karyawan', $kode_karyawan);
$stmt_nama_karyawan->execute();
$nama_karyawan_data = $stmt_nama_karyawan->fetch(PDO::FETCH_ASSOC);

$nama_karyawan = $nama_karyawan_data['nama_karyawan'] ?? 'Tidak diketahui';
$nama_cabang = $nama_karyawan_data['nama_cabang'] ?? 'Tidak diketahui';
$karyawan_info = $kode_karyawan . ' - ' . ($nama_karyawan ?? 'Tidak diketahui');

// Fungsi untuk format angka ke dalam format Rupiah tanpa desimal
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cek Data Kasir</title>
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
            width: auto;
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
        .sidebar a.active {
            background: var(--primary-color);
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
            margin-left: 200px;
            padding: 30px;
            flex: 1;
            transition: margin-left 0.3s ease;
        }
        .page-header {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
        }
        .page-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 16px;
        }
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 14px;
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
            margin-bottom: 24px;
        }
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .info-card .label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-card .value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-dark);
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            background: var(--background-light);
            border-radius: 12px 12px 0 0;
        }
        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
            margin: 0;
        }
        .card-body {
            padding: 0;
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
        .btn-secondary {
            background: var(--secondary-color);
            color: white;
            box-shadow: 0 2px 4px rgba(108, 117, 125, 0.2);
        }
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.3);
        }
        .table-container {
            border-radius: 0 0 12px 12px;
            overflow: hidden;
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
            width: 40%;
        }
        .table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
            font-weight: 600;
        }
        .table tbody tr:hover {
            background: var(--background-light);
        }
        .summary-card {
            background: linear-gradient(135deg, var(--primary-color), #0056b3);
            color: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            text-align: center;
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.3);
        }
        .summary-card h3 {
            font-size: 24px;
            margin-bottom: 8px;
        }
        .summary-card p {
            font-size: 14px;
            opacity: 0.9;
        }
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 8px;
        }
        .status-sesuai {
            background: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        .status-selisih {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 53, 69, 0.3);
        }
        .highlight-positive {
            color: var(--success-color);
        }
        .highlight-negative {
            color: var(--danger-color);
        }
        .highlight-neutral {
            color: var(--primary-color);
        }
        .section-divider {
            background: var(--primary-color);
            color: white;
            padding: 12px 24px;
            margin: 24px 0;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            font-size: 16px;
        }
        .final-result-row {
            background: var(--background-light) !important;
        }
        .final-result-row th {
            font-size: 16px;
            font-weight: 700;
        }
        .final-result-row td {
            font-size: 16px;
            font-weight: 700;
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
            .info-grid {
                grid-template-columns: 1fr;
            }
            .table th,
            .table td {
                padding: 12px;
                font-size: 12px;
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
        <div class="page-header">
            <h1><i class="fas fa-chart-pie"></i> Cek Data Kasir</h1>
            <div class="breadcrumb">
                <a href="index_kasir.php">Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span>Cek Data</span>
            </div>
        </div>

        <!-- Info Cards -->
        <div class="info-grid">
            <div class="info-card">
                <div class="label">Kode Transaksi</div>
                <div class="value"><?php echo htmlspecialchars($kode_transaksi); ?></div>
            </div>
            <div class="info-card">
                <div class="label">Karyawan</div>
                <div class="value"><?php echo htmlspecialchars($karyawan_info); ?></div>
            </div>
            <div class="info-card">
                <div class="label">Cabang</div>
                <div class="value"><?php echo htmlspecialchars($nama_cabang); ?></div>
            </div>
        </div>

        <!-- Summary Card -->
        <div class="summary-card">
            <h3>
                <?php if ($selisih_setoran == 0): ?>
                    <i class="fas fa-check-circle"></i> Data Sesuai
                <?php elseif ($selisih_setoran > 0): ?>
                    <i class="fas fa-arrow-up"></i> Lebih <?php echo formatRupiah($selisih_setoran); ?>
                <?php else: ?>
                    <i class="fas fa-arrow-down"></i> Kurang <?php echo formatRupiah(abs($selisih_setoran)); ?>
                <?php endif; ?>
            </h3>
            <p>Status Selisih Setoran</p>
        </div>

        <!-- Data Kas Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-cash-register"></i> Data Kas</h3>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table class="table">
                        <tbody>
                            <tr>
                                <th><i class="fas fa-cash-register"></i> KAS AKHIR</th>
                                <td class="highlight-neutral"><?php echo formatRupiah($total_uang_di_kasir); ?></td>
                            </tr>
                            <tr>
                                <th><i class="fas fa-coins"></i> KAS AWAL</th>
                                <td class="highlight-neutral"><?php echo formatRupiah($kas_awal); ?></td>
                            </tr>
                            <tr>
                                <th><i class="fas fa-hand-holding-usd"></i> SETORAN REAL</th>
                                <td class="highlight-positive"><?php echo formatRupiah($setoran_real); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Section Divider -->
        <div class="section-divider">
            <i class="fas fa-database"></i> DATA SISTEM APLIKASI
        </div>

        <!-- Data Sistem Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-database"></i> Data Sistem</h3>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table class="table">
                        <tbody>
                            <tr>
                                <th><i class="fas fa-shopping-cart"></i> DATA PENJUALAN</th>
                                <td class="highlight-positive"><?php echo formatRupiah($data_penjualan); ?></td>
                            </tr>
                            <tr>
                                <th><i class="fas fa-tools"></i> DATA SERVIS</th>
                                <td class="highlight-positive"><?php echo formatRupiah($data_servis); ?></td>
                            </tr>
                            <tr>
                                <th><i class="fas fa-chart-line"></i> OMSET</th>
                                <td class="highlight-positive"><?php echo formatRupiah($omset); ?></td>
                            </tr>
                            <tr>
                                <th><i class="fas fa-minus-circle"></i> PENGELUARAN DARI KASIR</th>
                                <td class="highlight-negative"><?php echo formatRupiah($pengeluaran_dari_kasir); ?></td>
                            </tr>
                            <tr>
                                <th><i class="fas fa-plus-circle"></i> UANG MASUK KE KASIR</th>
                                <td class="highlight-positive"><?php echo formatRupiah($uang_masuk_ke_kasir); ?></td>
                            </tr>
                            <tr>
                                <th><i class="fas fa-calculator"></i> DATA SETORAN</th>
                                <td class="highlight-neutral"><?php echo formatRupiah($setoran_data); ?></td>
                            </tr>
                            <tr class="final-result-row">
                                <th>
                                    <i class="fas fa-balance-scale"></i> SELISIH SETORAN (REAL-DATA)
                                    <?php if ($selisih_setoran == 0): ?>
                                        <span class="status-indicator status-sesuai">
                                            <i class="fas fa-check"></i> SESUAI
                                        </span>
                                    <?php else: ?>
                                        <span class="status-indicator status-selisih">
                                            <i class="fas fa-exclamation-triangle"></i> SELISIH
                                        </span>
                                    <?php endif; ?>
                                </th>
                                <td style="color: <?php echo ($selisih_setoran < 0) ? 'var(--danger-color)' : ($selisih_setoran > 0 ? 'var(--success-color)' : 'var(--text-dark)'); ?>;">
                                    <?php echo formatRupiah($selisih_setoran); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Back Button -->
        <div style="margin-top: 24px;">
            <button class="btn btn-secondary" onclick="window.location.href='index_kasir.php'">
                <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
            </button>
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

            const minWidth = 180;
            sidebar.style.width = maxWidth > minWidth ? `${maxWidth + 20}px` : `${minWidth}px`;
            document.querySelector('.main-content').style.marginLeft = sidebar.style.width;
        }

        // Run on page load and window resize
        window.addEventListener('load', adjustSidebarWidth);
        window.addEventListener('resize', adjustSidebarWidth);
    </script>
</body>
</html>

<?php
// Tutup koneksi database
$pdo = null;
?>