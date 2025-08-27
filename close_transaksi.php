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

// Koneksi ke database dengan PDO
$pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Ambil kode transaksi dari URL
if (isset($_GET['kode_transaksi'])) {
    $kode_transaksi = $_GET['kode_transaksi'];
} else {
    die("Kode transaksi tidak ditemukan.");
}

// Ambil kode cabang dan nama cabang dari sesi atau dari tabel users sebagai cadangan
if (isset($_SESSION['kode_cabang']) && isset($_SESSION['nama_cabang'])) {
    $kode_cabang = $_SESSION['kode_cabang'];
    $nama_cabang = $_SESSION['nama_cabang'];
} else {
    // Ambil kode_cabang dan nama_cabang dari tabel users jika belum ada di sesi
    $sql_cabang = "SELECT kode_cabang, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan LIMIT 1";
    $stmt_cabang = $pdo->prepare($sql_cabang);
    $stmt_cabang->bindParam(':kode_karyawan', $_SESSION['kode_karyawan'], PDO::PARAM_STR);
    $stmt_cabang->execute();
    $user_cabang = $stmt_cabang->fetch(PDO::FETCH_ASSOC);
    $kode_cabang = $user_cabang['kode_cabang'] ?? 'Unknown';
    $nama_cabang = $user_cabang['nama_cabang'] ?? 'Unknown Cabang';

    // Simpan kembali ke sesi agar bisa digunakan di seluruh proses
    $_SESSION['kode_cabang'] = $kode_cabang;
    $_SESSION['nama_cabang'] = $nama_cabang;
}

// Cek apakah `kode_cabang` dan `nama_cabang` sudah tercatat dalam transaksi
$sql_check_cabang = "SELECT kode_cabang, nama_cabang FROM kasir_transactions WHERE kode_transaksi = :kode_transaksi";
$stmt_check_cabang = $pdo->prepare($sql_check_cabang);
$stmt_check_cabang->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_check_cabang->execute();
$recorded_data = $stmt_check_cabang->fetch(PDO::FETCH_ASSOC);

$recorded_kode_cabang = $recorded_data['kode_cabang'] ?? null;
$recorded_nama_cabang = $recorded_data['nama_cabang'] ?? null;

if ($recorded_kode_cabang === null || $recorded_nama_cabang === null) {
    // Update `kode_cabang` dan `nama_cabang` jika belum tercatat dalam transaksi
    $sql_update_cabang = "UPDATE kasir_transactions 
                          SET kode_cabang = IFNULL(kode_cabang, :kode_cabang), 
                              nama_cabang = IFNULL(nama_cabang, :nama_cabang) 
                          WHERE kode_transaksi = :kode_transaksi";
    $stmt_update_cabang = $pdo->prepare($sql_update_cabang);
    $stmt_update_cabang->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
    $stmt_update_cabang->bindParam(':nama_cabang', $nama_cabang, PDO::PARAM_STR);
    $stmt_update_cabang->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
    $stmt_update_cabang->execute();
}

// Fetch and calculate other transaction data
$sql_kas_akhir = "SELECT total_nilai FROM kas_akhir WHERE kode_transaksi = :kode_transaksi";
$stmt_kas_akhir = $pdo->prepare($sql_kas_akhir);
$stmt_kas_akhir->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_kas_akhir->execute();
$total_uang_di_kasir = $stmt_kas_akhir->fetchColumn() ?? 0;

$sql_kas_awal = "SELECT total_nilai FROM kas_awal WHERE kode_transaksi = :kode_transaksi";
$stmt_kas_awal = $pdo->prepare($sql_kas_awal);
$stmt_kas_awal->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_kas_awal->execute();
$kas_awal = $stmt_kas_awal->fetchColumn() ?? 0;

$setoran_real = $total_uang_di_kasir - $kas_awal;

$sql_penjualan = "SELECT SUM(jumlah_penjualan) as total_penjualan FROM data_penjualan WHERE kode_transaksi = :kode_transaksi";
$stmt_penjualan = $pdo->prepare($sql_penjualan);
$stmt_penjualan->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_penjualan->execute();
$data_penjualan = $stmt_penjualan->fetchColumn() ?? 0;

$sql_servis = "SELECT SUM(jumlah_servis) as total_servis FROM data_servis WHERE kode_transaksi = :kode_transaksi";
$stmt_servis = $pdo->prepare($sql_servis);
$stmt_servis->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_servis->execute();
$data_servis = $stmt_servis->fetchColumn() ?? 0;

$omset = $data_penjualan + $data_servis;

$sql_pengeluaran = "SELECT SUM(jumlah) as total_pengeluaran FROM pengeluaran_kasir WHERE kode_transaksi = :kode_transaksi";
$stmt_pengeluaran = $pdo->prepare($sql_pengeluaran);
$stmt_pengeluaran->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pengeluaran->execute();
$pengeluaran_dari_kasir = $stmt_pengeluaran->fetchColumn() ?? 0;

$sql_pemasukan = "SELECT SUM(jumlah) as total_pemasukan FROM pemasukan_kasir WHERE kode_transaksi = :kode_transaksi";
$stmt_pemasukan = $pdo->prepare($sql_pemasukan);
$stmt_pemasukan->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pemasukan->execute();
$uang_masuk_ke_kasir = $stmt_pemasukan->fetchColumn() ?? 0;

$setoran_data = ($omset - $pengeluaran_dari_kasir) + $uang_masuk_ke_kasir;
$selisih_setoran = $setoran_real - $setoran_data;

// Penutupan transaksi jika `Lanjut Closing` ditekan
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tanggal_closing = date('Y-m-d');
    $jam_closing = date('H:i:s');

    $sql_update = "
        UPDATE kasir_transactions 
        SET 
            kas_awal = :kas_awal, 
            kas_akhir = :kas_akhir, 
            total_pemasukan = :total_pemasukan, 
            total_pengeluaran = :total_pengeluaran, 
            total_penjualan = :total_penjualan, 
            total_servis = :total_servis, 
            setoran_real = :setoran_real, 
            omset = :omset, 
            data_setoran = :data_setoran, 
            selisih_setoran = :selisih_setoran, 
            status = 'end proses',
            tanggal_closing = :tanggal_closing,
            jam_closing = :jam_closing
        WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan";

    $stmt_update = $pdo->prepare($sql_update);
    $stmt_update->bindParam(':kas_awal', $_POST['kas_awal'], PDO::PARAM_STR);
    $stmt_update->bindParam(':kas_akhir', $_POST['kas_akhir'], PDO::PARAM_STR);
    $stmt_update->bindParam(':total_pemasukan', $_POST['total_pemasukan'], PDO::PARAM_STR);
    $stmt_update->bindParam(':total_pengeluaran', $_POST['total_pengeluaran'], PDO::PARAM_STR);
    $stmt_update->bindParam(':total_penjualan', $_POST['total_penjualan'], PDO::PARAM_STR);
    $stmt_update->bindParam(':total_servis', $_POST['total_servis'], PDO::PARAM_STR);
    $stmt_update->bindParam(':setoran_real', $_POST['setoran_real'], PDO::PARAM_STR);
    $stmt_update->bindParam(':omset', $_POST['omset'], PDO::PARAM_STR);
    $stmt_update->bindParam(':data_setoran', $_POST['data_setoran'], PDO::PARAM_STR);
    $stmt_update->bindParam(':selisih_setoran', $_POST['selisih_setoran'], PDO::PARAM_STR);
    $stmt_update->bindParam(':tanggal_closing', $tanggal_closing, PDO::PARAM_STR);
    $stmt_update->bindParam(':jam_closing', $jam_closing, PDO::PARAM_STR);
    $stmt_update->bindParam(':kode_transaksi', $_POST['kode_transaksi'], PDO::PARAM_STR);
    $stmt_update->bindParam(':kode_karyawan', $_SESSION['kode_karyawan'], PDO::PARAM_STR);
    $stmt_update->execute();

    echo "<script>alert('Transaksi berhasil ditutup dan dihitung.'); window.location.href = 'index_kasir.php';</script>";
}

$pdo = null;

function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.') . ($angka == 0 ? " (Belum diisi)" : "");
}

// Function khusus untuk format selisih setoran tanpa "(Belum diisi)"
function formatSelisihSetoran($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$cabang = $nama_cabang ?? 'Unknown Cabang';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Penutupan Transaksi</title>
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
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .page-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 8px;
        }
        .page-header .subtitle {
            color: var(--text-muted);
            font-size: 16px;
            margin-bottom: 16px;
        }
        .transaction-code {
            background: var(--background-light);
            padding: 8px 16px;
            border-radius: 12px;
            display: inline-block;
            font-weight: 600;
        }
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 14px;
            margin-top: 16px;
        }
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
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
            padding: 24px;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }
        .summary-item:last-child {
            border-bottom: none;
        }
        .summary-item .label {
            font-weight: 600;
            color: var(--text-dark);
        }
        .summary-item .value {
            font-weight: 600;
        }
        .value.positive {
            color: var(--success-color);
        }
        .value.negative {
            color: var(--danger-color);
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
            padding: 16px 24px;
            text-align: left;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 14px;
            border-bottom: 1px solid var(--border-color);
        }
        .table td {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        .table tbody tr:hover {
            background: var(--background-light);
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
        }
        .btn-success {
            background: var(--success-color);
            color: white;
            box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
        }
        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }
        .btn-danger {
            background: var(--danger-color);
            color: white;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
        }
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }
        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            border: 1px solid transparent;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-warning {
            background: #fff3cd;
            border-color: #ffeaa7;
            color: #856404;
        }
        .highlight-row {
            background: var(--background-light) !important;
            font-weight: 600;
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
            .action-buttons {
                flex-direction: column;
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
                <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($cabang); ?>)
                <p style="color: var(--text-muted); font-size: 12px;">Kasir</p>
            </div>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-calculator"></i> Konfirmasi Penutupan Transaksi</h1>
            <p class="subtitle">Periksa dan validasi data transaksi sebelum melakukan penutupan</p>
            <div class="transaction-code">
                <i class="fas fa-receipt"></i> <?php echo htmlspecialchars($kode_transaksi); ?>
            </div>
            <div class="breadcrumb">
                <a href="index_kasir.php">Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span>Closing Transaksi</span>
            </div>
        </div>

        <!-- Summary Status Card -->
        <div class="summary-card">
            <h3>
                <?php if ($selisih_setoran == 0): ?>
                    <i class="fas fa-check-circle"></i> Transaksi Seimbang
                <?php elseif ($selisih_setoran > 0): ?>
                    <i class="fas fa-arrow-up"></i> Lebih <?php echo 'Rp ' . number_format($selisih_setoran, 0, ',', '.'); ?>
                <?php else: ?>
                    <i class="fas fa-arrow-down"></i> Kurang <?php echo 'Rp ' . number_format(abs($selisih_setoran), 0, ',', '.'); ?>
                <?php endif; ?>
            </h3>
            <p>Status Selisih Setoran</p>
        </div>

        <!-- Ringkasan Setoran Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Ringkasan Setoran</h3>
            </div>
            <div class="card-body">
                <div class="summary-item">
                    <span class="label">Setoran Real (Kas Akhir - Kas Awal)</span>
                    <span class="value"><?php echo formatRupiah($setoran_real); ?></span>
                </div>
                <div class="summary-item">
                    <span class="label">Data Setoran (Omset - Pengeluaran + Pemasukan)</span>
                    <span class="value"><?php echo formatRupiah($setoran_data); ?></span>
                </div>
                <div class="summary-item">
                    <span class="label">Selisih Setoran (Real - Data)</span>
                    <span class="value <?php echo ($selisih_setoran < 0) ? 'negative' : 'positive'; ?>">
                        <?php echo formatSelisihSetoran($selisih_setoran); ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if ($selisih_setoran != 0): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> 
            <div>
                <strong>Perhatian!</strong> Terdapat selisih sebesar <?php echo formatSelisihSetoran($selisih_setoran); ?> 
                antara setoran real dan data sistem. Pastikan untuk memeriksa kembali data transaksi.
            </div>
        </div>
        <?php endif; ?>

        <!-- Data Kas Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-wallet"></i> Data Kas</h3>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-container">
                    <table class="table">
                        <tbody>
                            <tr>
                                <th>Kas Akhir</th>
                                <td><?php echo formatRupiah($total_uang_di_kasir); ?></td>
                            </tr>
                            <tr>
                                <th>Kas Awal</th>
                                <td><?php echo formatRupiah($kas_awal); ?></td>
                            </tr>
                            <tr class="highlight-row">
                                <th>Setoran Real</th>
                                <td><?php echo formatRupiah($setoran_real); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Data Sistem Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-database"></i> Data Sistem Aplikasi</h3>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-container">
                    <table class="table">
                        <tbody>
                            <tr>
                                <th>Data Penjualan</th>
                                <td><?php echo formatRupiah($data_penjualan); ?></td>
                            </tr>
                            <tr>
                                <th>Data Servis</th>
                                <td><?php echo formatRupiah($data_servis); ?></td>
                            </tr>
                            <tr>
                                <th>Omset</th>
                                <td><?php echo formatRupiah($omset); ?></td>
                            </tr>
                            <tr>
                                <th>Pengeluaran dari Kasir</th>
                                <td><?php echo formatRupiah($pengeluaran_dari_kasir); ?></td>
                            </tr>
                            <tr>
                                <th>Uang Masuk ke Kasir</th>
                                <td><?php echo formatRupiah($uang_masuk_ke_kasir); ?></td>
                            </tr>
                            <tr class="highlight-row">
                                <th>Data Setoran</th>
                                <td><?php echo formatRupiah($setoran_data); ?></td>
                            </tr>
                            <tr class="highlight-row">
                                <th>Selisih Setoran (Real - Data)</th>
                                <td style="color: <?php echo ($selisih_setoran < 0) ? 'var(--danger-color)' : 'var(--success-color)'; ?>;">
                                    <?php echo formatSelisihSetoran($selisih_setoran); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Konfirmasi Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-question-circle"></i> Konfirmasi Penutupan</h3>
            </div>
            <div class="card-body">
                <p>Apakah Anda yakin ingin menutup transaksi ini? Setelah ditutup, transaksi tidak dapat diubah kembali.</p>
                
                <div class="action-buttons">
                    <button class="btn btn-success" id="btnLanjutClosing">
                        <i class="fas fa-check"></i> Lanjut Closing
                    </button>
                    <button class="btn btn-danger" onclick="window.location.href='index_kasir.php';">
                        <i class="fas fa-times"></i> Batal Closing
                    </button>
                </div>
            </div>
        </div>

        <form id="formClosing" method="POST" action="" style="display: none;">
            <input type="hidden" name="kas_awal" value="<?php echo $kas_awal; ?>">
            <input type="hidden" name="kas_akhir" value="<?php echo $total_uang_di_kasir; ?>">
            <input type="hidden" name="total_pemasukan" value="<?php echo $uang_masuk_ke_kasir; ?>">
            <input type="hidden" name="total_pengeluaran" value="<?php echo $pengeluaran_dari_kasir; ?>">
            <input type="hidden" name="total_penjualan" value="<?php echo $data_penjualan; ?>">
            <input type="hidden" name="total_servis" value="<?php echo $data_servis; ?>">
            <input type="hidden" name="setoran_real" value="<?php echo $setoran_real; ?>">
            <input type="hidden" name="omset" value="<?php echo $omset; ?>">
            <input type="hidden" name="data_setoran" value="<?php echo $setoran_data; ?>">
            <input type="hidden" name="selisih_setoran" value="<?php echo $selisih_setoran; ?>">
            <input type="hidden" name="kode_transaksi" value="<?php echo $kode_transaksi; ?>">
        </form>
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

        document.getElementById('btnLanjutClosing').addEventListener('click', function() {
            if (confirm('Anda yakin ingin melanjutkan closing transaksi ini?')) {
                document.getElementById('formClosing').submit();
            }
        });
    </script>
</body>
</html>