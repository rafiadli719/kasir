<?php
session_start();
include 'config.php'; // Include your database connection

// Ensure the user is logged in and has the 'kasir' role
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

// Database connection
$pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get user details from the session
$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$cabang_user = $_SESSION['nama_cabang'] ?? 'Unknown Cabang';

$kode_transaksi = $_GET['kode_transaksi'] ?? null; // Get transaction code from URL

// Retrieve transaction data, including the branch name directly from `kasir_transactions`
$sql = "
    SELECT 
        kt.*, 
        kt.nama_cabang AS cabang,   -- Get the branch name directly from kasir_transactions
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
        kt.jam_closing           -- Get the closing time
    FROM kasir_transactions kt
    LEFT JOIN kas_awal ka ON ka.kode_transaksi = kt.kode_transaksi
    LEFT JOIN kas_akhir kcl ON kcl.kode_transaksi = kt.kode_transaksi
    WHERE kt.kode_transaksi = :kode_transaksi AND kt.kode_karyawan = :kode_karyawan
";

$stmt = $pdo->prepare($sql);
$stmt->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt->execute();
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    die("Transaksi tidak ditemukan atau Anda tidak memiliki akses.");
}

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

// Fetch Pemasukan Kasir details
$sql_pemasukan = "
    SELECT kode_transaksi, kode_akun, jumlah, keterangan_transaksi, tanggal, waktu 
    FROM pemasukan_kasir 
    WHERE kode_transaksi = :kode_transaksi";
$stmt_pemasukan = $pdo->prepare($sql_pemasukan);
$stmt_pemasukan->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pemasukan->execute();
$pemasukan_kasir = $stmt_pemasukan->fetchAll(PDO::FETCH_ASSOC);

// Fetch Pengeluaran Kasir details
$sql_pengeluaran = "
    SELECT kode_transaksi, kode_akun, jumlah, keterangan_transaksi, tanggal, waktu, umur_pakai, kategori 
    FROM pengeluaran_kasir 
    WHERE kode_transaksi = :kode_transaksi";
$stmt_pengeluaran = $pdo->prepare($sql_pengeluaran);
$stmt_pengeluaran->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pengeluaran->execute();
$pengeluaran_kasir = $stmt_pengeluaran->fetchAll(PDO::FETCH_ASSOC);

// Calculate additional variables for display
$omset = $transaction['data_penjualan'] + $transaction['data_servis'];
$setoran_real = $transaction['kas_akhir'] - $transaction['kas_awal'];
$data_setoran = $omset + $transaction['total_pemasukan'] - $transaction['total_pengeluaran'];
$selisih_setoran = $setoran_real - $data_setoran;

$bulan = [
    1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

$tanggal = date('d', strtotime($transaction['kas_awal_date']));
$bulanNama = $bulan[(int)date('m', strtotime($transaction['kas_awal_date']))];
$tahun = date('Y', strtotime($transaction['kas_awal_date']));
$formattedDate = "$tanggal $bulanNama $tahun";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Closing Kasir</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
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
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
        }
        .header {
            text-align: center;
            margin-bottom: 32px;
        }
        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-dark);
        }
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-dark);
            margin: 32px 0 16px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--border-color);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            justify-content: center;
        }
        .btn-info {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            box-shadow: 0 2px 4px rgba(23, 162, 184, 0.2);
        }
        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(23, 162, 184, 0.3);
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
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #0056b3);
            color: white;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.2);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.3);
        }
        .btn-secondary {
            background: linear-gradient(135deg, var(--secondary-color), #5a6268);
            color: white;
            box-shadow: 0 2px 4px rgba(108, 117, 125, 0.2);
        }
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.3);
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
            .container {
                padding: 24px;
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
            <span>Closing Kasir</span>
        </div>

        <div class="container">
            <div class="header">
                <h1><i class="fas fa-file-invoice"></i> CLOSING <?php echo htmlspecialchars($transaction['cabang']) . ' ' . $formattedDate; ?></h1>
            </div>

            <!-- Transaction Date and Time -->
            <h2 class="section-title">Tanggal dan Jam Transaksi</h2>
            <table class="table table-bordered">
                <tr>
                    <th>Kas Awal - Tanggal</th>
                    <td><?php echo !empty($transaction['kas_awal_date']) ? date('d M Y', strtotime($transaction['kas_awal_date'])) : 'Belum diisi'; ?></td>
                    <th>Kas Awal - Jam</th>
                    <td><?php echo !empty($transaction['kas_awal_time']) ? date('H:i:s', strtotime($transaction['kas_awal_time'])) : 'Belum diisi'; ?></td>
                </tr>
                <tr>
                    <th>Kas Akhir - Tanggal</th>
                    <td><?php echo !empty($transaction['kas_akhir_date']) ? date('d M Y', strtotime($transaction['kas_akhir_date'])) : 'Belum diisi'; ?></td>
                    <th>Kas Akhir - Jam</th>
                    <td><?php echo !empty($transaction['kas_akhir_time']) ? date('H:i:s', strtotime($transaction['kas_akhir_time'])) : 'Belum diisi'; ?></td>
                </tr>
                <tr>
                    <th>Tanggal Closing</th>
                    <td><?php echo !empty($transaction['tanggal_closing']) ? date('d M Y', strtotime($transaction['tanggal_closing'])) : 'Belum diisi'; ?></td>
                    <th>Jam Closing</th>
                    <td><?php echo !empty($transaction['jam_closing']) ? date('H:i:s', strtotime($transaction['jam_closing'])) : 'Belum diisi'; ?></td>
                </tr>
            </table>

            <!-- Data Sistem Aplikasi -->
            <h2 class="section-title">Data Sistem Aplikasi</h2>
            <table class="table table-striped table-bordered">
                <tr><th>Omset Penjualan</th><td>Rp<?php echo number_format($transaction['data_penjualan'], 0, ',', '.'); ?></td></tr>
                <tr><th>Omset Servis</th><td>Rp<?php echo number_format($transaction['data_servis'], 0, ',', '.'); ?></td></tr>
                <tr><th>Jumlah Omset (Penjualan + Servis)</th><td>Rp<?php echo number_format($omset, 0, ',', '.'); ?></td></tr>
                <tr><th>Pemasukan Kas</th><td>Rp<?php echo number_format($transaction['total_pemasukan'], 0, ',', '.'); ?></td></tr>
                <tr><th>Total Uang Masuk Kas</th><td>Rp<?php echo number_format($omset + $transaction['total_pemasukan'], 0, ',', '.'); ?></td></tr>
                <tr><th>Pengeluaran Kas</th><td>Rp<?php echo number_format($transaction['total_pengeluaran'], 0, ',', '.'); ?></td></tr>
                <tr><th>Data Setoran</th><td>Rp<?php echo number_format($data_setoran, 0, ',', '.'); ?></td></tr>
                <tr><th>Selisih Setoran (REAL - DATA)</th><td>Rp<?php echo number_format($selisih_setoran, 0, ',', '.'); ?></td></tr>
            </table>

            <!-- Riil Uang -->
            <h2 class="section-title">Riil Uang</h2>
            <table class="table table-bordered">
                <tr><th>Kas Awal</th><td>Rp<?php echo number_format($transaction['kas_awal'], 0, ',', '.'); ?></td></tr>
                <tr><th>Kas Akhir</th><td>Rp<?php echo number_format($transaction['kas_akhir'], 0, ',', '.'); ?></td></tr>
                <tr><th>Setoran Riil</th><td>Rp<?php echo number_format($setoran_real, 0, ',', '.'); ?></td></tr>
            </table>

            <!-- Data Kas Awal and Data Kas Akhir side by side -->
            <div class="row">
                <div class="col-md-6">
                    <h2 class="section-title">Data Kas Awal</h2>
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>Nominal</th>
                                <th>Keping</th>
                                <th>Total Nilai</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($kas_awal_detail as $row): ?>
                                <tr>
                                    <td>Rp<?php echo number_format($row['nominal'], 0, ',', '.'); ?></td>
                                    <td><?php echo $row['jumlah_keping']; ?></td>
                                    <td>Rp<?php echo number_format($row['nominal'] * $row['jumlah_keping'], 0, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="col-md-6">
                    <h2 class="section-title">Data Kas Akhir</h2>
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>Nominal</th>
                                <th>Keping</th>
                                <th>Total Nilai</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($kas_akhir_detail as $row): ?>
                                <tr>
                                    <td>Rp<?php echo number_format($row['nominal'], 0, ',', '.'); ?></td>
                                    <td><?php echo $row['jumlah_keping']; ?></td>
                                    <td>Rp<?php echo number_format($row['nominal'] * $row['jumlah_keping'], 0, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- View Pemasukan Kasir -->
            <h2 class="section-title">Pemasukan Kasir</h2>
            <table class="table table-striped table-bordered">
                <tr><th>Kode Transaksi</th><th>Kode Akun</th><th>Jumlah (Rp)</th><th>Keterangan</th><th>Tanggal</th><th>Waktu</th></tr>
                <?php if ($pemasukan_kasir): ?>
                    <?php foreach ($pemasukan_kasir as $pemasukan): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($pemasukan['kode_transaksi']); ?></td>
                            <td><?php echo htmlspecialchars($pemasukan['kode_akun']); ?></td>
                            <td>Rp<?php echo number_format($pemasukan['jumlah'], 0, ',', '.'); ?></td>
                            <td><?php echo htmlspecialchars($pemasukan['keterangan_transaksi']); ?></td>
                            <td><?php echo htmlspecialchars($pemasukan['tanggal']); ?></td>
                            <td><?php echo htmlspecialchars($pemasukan['waktu']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6">Tidak ada data pemasukan tercatat.</td></tr>
                <?php endif; ?>
            </table>

            <!-- View Pengeluaran Kasir -->
            <h2 class="section-title">Pengeluaran Kasir</h2>
            <table class="table table-striped table-bordered">
                <tr><th>Kode Transaksi</th><th>Kode Akun</th><th>Kategori Akun</th><th>Jumlah (Rp)</th><th>Keterangan</th><th>Tanggal</th><th>Waktu</th><th>Umur Pakai (Bulan)</th></tr>
                <?php if ($pengeluaran_kasir): ?>
                    <?php foreach ($pengeluaran_kasir as $pengeluaran): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($pengeluaran['kode_transaksi']); ?></td>
                            <td><?php echo htmlspecialchars($pengeluaran['kode_akun']); ?></td>
                            <td><?php echo htmlspecialchars($pengeluaran['kategori']); ?></td>
                            <td>Rp<?php echo number_format($pengeluaran['jumlah'], 0, ',', '.'); ?></td>
                            <td><?php echo htmlspecialchars($pengeluaran['keterangan_transaksi']); ?></td>
                            <td><?php echo htmlspecialchars($pengeluaran['tanggal']); ?></td>
                            <td><?php echo htmlspecialchars($pengeluaran['waktu']); ?></td>
                            <td><?php echo htmlspecialchars($pengeluaran['umur_pakai']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8">Tidak ada data pengeluaran tercatat.</td></tr>
                <?php endif; ?>
            </table>

            <div class="btn-group mb-3">
                <a href="generate_pdf.php?kode_transaksi=<?php echo $kode_transaksi; ?>" class="btn btn-info"><i class="fas fa-file-pdf"></i> Unduh PDF</a>
                <a href="generate_excel.php?kode_transaksi=<?php echo $kode_transaksi; ?>" class="btn btn-success"><i class="fas fa-file-excel"></i> Unduh Excel</a>
                <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Cetak</button>
            </div>
            <div>
                <button class="btn btn-secondary" onclick="window.location.href='index_kasir.php'"><i class="fas fa-arrow-left"></i> Kembali ke Dashboard Kasir</button>
            </div>
        </div>
    </div>

    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
$pdo = null;
?>