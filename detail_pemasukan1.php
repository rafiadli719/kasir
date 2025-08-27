<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'config.php';

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$cabang_user = $_SESSION['nama_cabang'] ?? 'Unknown Cabang';

// Get pemasukan ID from URL
$pemasukan_id = $_GET['id'] ?? null;
$bukti_transaksi = $_GET['bukti'] ?? null;

if (!$pemasukan_id && !$bukti_transaksi) {
    die("Parameter tidak lengkap.");
}

// Query untuk mengambil detail pemasukan
if ($pemasukan_id) {
    $sql_detail = "
        SELECT 
            pk.*,
            u.nama_karyawan,
            u.nama_cabang,
            ma.arti as nama_akun,
            ma.jenis_akun,
            kt.setoran_real as nilai_closing,
            kt.tanggal_transaksi as tanggal_closing,
            kt.deposit_status as status_closing,
            mnt.nama_transaksi
        FROM pemasukan_kasir pk
        LEFT JOIN users u ON pk.kode_karyawan = u.kode_karyawan
        LEFT JOIN master_akun ma ON pk.kode_akun = ma.kode_akun
        LEFT JOIN kasir_transactions kt ON pk.nomor_transaksi_closing = kt.kode_transaksi
        LEFT JOIN master_nama_transaksi mnt ON pk.kode_akun = mnt.kode_akun
        WHERE pk.id = :pemasukan_id";
    $stmt_detail = $pdo->prepare($sql_detail);
    $stmt_detail->bindParam(':pemasukan_id', $pemasukan_id, PDO::PARAM_INT);
} else {
    // Jika dari bukti transaksi (format: PMS-123)
    $id_from_bukti = str_replace('PMS-', '', $bukti_transaksi);
    $sql_detail = "
        SELECT 
            pk.*,
            u.nama_karyawan,
            u.nama_cabang,
            ma.arti as nama_akun,
            ma.jenis_akun,
            kt.setoran_real as nilai_closing,
            kt.tanggal_transaksi as tanggal_closing,
            kt.deposit_status as status_closing,
            mnt.nama_transaksi
        FROM pemasukan_kasir pk
        LEFT JOIN users u ON pk.kode_karyawan = u.kode_karyawan
        LEFT JOIN master_akun ma ON pk.kode_akun = ma.kode_akun
        LEFT JOIN kasir_transactions kt ON pk.nomor_transaksi_closing = kt.kode_transaksi
        LEFT JOIN master_nama_transaksi mnt ON pk.kode_akun = mnt.kode_akun
        WHERE pk.id = :pemasukan_id";
    $stmt_detail = $pdo->prepare($sql_detail);
    $stmt_detail->bindParam(':pemasukan_id', $id_from_bukti, PDO::PARAM_INT);
}

$stmt_detail->execute();
$pemasukan_detail = $stmt_detail->fetch(PDO::FETCH_ASSOC);

if (!$pemasukan_detail) {
    die("Data pemasukan tidak ditemukan.");
}

// Jika ini transaksi DARI CLOSING, ambil riwayat pengambilan lainnya dari closing yang sama
$riwayat_pengambilan = [];
if (!empty($pemasukan_detail['nomor_transaksi_closing'])) {
    $sql_riwayat = "
        SELECT 
            pk.*,
            u.nama_karyawan
        FROM pemasukan_kasir pk
        LEFT JOIN users u ON pk.kode_karyawan = u.kode_karyawan
        WHERE pk.nomor_transaksi_closing = :kode_closing
        AND pk.id != :current_id
        ORDER BY pk.tanggal DESC, pk.waktu DESC";
    $stmt_riwayat = $pdo->prepare($sql_riwayat);
    $stmt_riwayat->bindParam(':kode_closing', $pemasukan_detail['nomor_transaksi_closing']);
    $stmt_riwayat->bindParam(':current_id', $pemasukan_detail['id']);
    $stmt_riwayat->execute();
    $riwayat_pengambilan = $stmt_riwayat->fetchAll(PDO::FETCH_ASSOC);
}

// Hitung total yang sudah diambil dari closing ini
$total_diambil = 0;
if (!empty($pemasukan_detail['nomor_transaksi_closing'])) {
    $sql_total = "SELECT SUM(jumlah) as total FROM pemasukan_kasir WHERE nomor_transaksi_closing = :kode_closing";
    $stmt_total = $pdo->prepare($sql_total);
    $stmt_total->bindParam(':kode_closing', $pemasukan_detail['nomor_transaksi_closing']);
    $stmt_total->execute();
    $total_diambil = $stmt_total->fetchColumn() ?? 0;
}

$sisa_closing = $pemasukan_detail['nilai_closing'] - $total_diambil;

function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

function getStatusBadge($status) {
    switch($status) {
        case 'on proses':
            return '<span class="status-badge status-warning">Sedang Proses</span>';
        case 'end proses':
            return '<span class="status-badge status-success">Selesai</span>';
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
    <title>Detail Pemasukan Kasir</title>
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
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }
        .detail-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
        }
        .detail-card.highlight {
            border-left: 4px solid var(--primary-color);
        }
        .detail-card.warning {
            border-left: 4px solid var(--warning-color);
        }
        .detail-card.success {
            border-left: 4px solid var(--success-color);
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
            font-size: 18px;
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
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-top: 24px;
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
            background: rgba(23,162,184,0.1);
            border-color: rgba(23,162,184,0.2);
            color: var(--info-color);
        }
        .alert-warning {
            background: rgba(255,193,7,0.1);
            border-color: rgba(255,193,7,0.2);
            color: #e0a800;
        }
        .closing-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
        }
        .closing-link:hover {
            text-decoration: underline;
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
            .detail-grid {
                grid-template-columns: 1fr;
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
            <a href="pemasukan_kasir.php?kode_transaksi=<?php echo htmlspecialchars($pemasukan_detail['kode_transaksi']); ?>">Pemasukan Kasir</a>
            <i class="fas fa-chevron-right"></i>
            <span>Detail Pemasukan</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-receipt"></i> Detail Pemasukan Kasir</h1>
            <p class="subtitle">
                Informasi lengkap pemasukan 
                <?php echo empty($pemasukan_detail['nomor_transaksi_closing']) ? 'reguler' : 'dari closing'; ?>
            </p>
        </div>

        <?php if (!empty($pemasukan_detail['nomor_transaksi_closing'])): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <strong>Transaksi DARI CLOSING:</strong> Ini adalah transaksi pengambilan uang dari closing 
            <a href="detail_closing.php?kode_transaksi=<?php echo htmlspecialchars($pemasukan_detail['nomor_transaksi_closing']); ?>" class="closing-link">
                <?php echo htmlspecialchars($pemasukan_detail['nomor_transaksi_closing']); ?>
            </a>
        </div>
        <?php endif; ?>

        <div class="detail-grid">
            <!-- Detail Pemasukan -->
            <div class="detail-card highlight">
                <h3><i class="fas fa-plus-circle"></i> Informasi Pemasukan</h3>
                <div class="detail-item">
                    <span class="detail-label">ID Pemasukan:</span>
                    <span class="detail-value">PMS-<?php echo $pemasukan_detail['id']; ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Kode Transaksi:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($pemasukan_detail['kode_transaksi']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Nama Transaksi:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($pemasukan_detail['nama_transaksi'] ?? 'Tidak diketahui'); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Kode Akun:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($pemasukan_detail['kode_akun']); ?> - <?php echo htmlspecialchars($pemasukan_detail['nama_akun']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Jumlah Pemasukan:</span>
                    <span class="detail-value amount"><?php echo formatRupiah($pemasukan_detail['jumlah']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Keterangan:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($pemasukan_detail['keterangan_transaksi']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Tanggal & Waktu:</span>
                    <span class="detail-value"><?php echo date('d/m/Y H:i', strtotime($pemasukan_detail['tanggal'] . ' ' . $pemasukan_detail['waktu'])); ?></span>
                </div>
            </div>

            <!-- Detail Karyawan -->
            <div class="detail-card success">
                <h3><i class="fas fa-user"></i> Informasi Karyawan</h3>
                <div class="detail-item">
                    <span class="detail-label">Kode Karyawan:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($pemasukan_detail['kode_karyawan']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Nama Karyawan:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($pemasukan_detail['nama_karyawan']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Cabang:</span>
                    <span class="detail-value"><?php echo htmlspecialchars($pemasukan_detail['nama_cabang']); ?></span>
                </div>
            </div>

            <?php if (!empty($pemasukan_detail['nomor_transaksi_closing'])): ?>
            <!-- Detail Closing -->
            <div class="detail-card warning">
                <h3><i class="fas fa-calculator"></i> Informasi Closing Terkait</h3>
                <div class="detail-item">
                    <span class="detail-label">Kode Transaksi Closing:</span>
                    <span class="detail-value">
                        <a href="detail_closing.php?kode_transaksi=<?php echo htmlspecialchars($pemasukan_detail['nomor_transaksi_closing']); ?>" class="closing-link">
                            <?php echo htmlspecialchars($pemasukan_detail['nomor_transaksi_closing']); ?>
                        </a>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Tanggal Closing:</span>
                    <span class="detail-value"><?php echo date('d/m/Y', strtotime($pemasukan_detail['tanggal_closing'])); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Nilai Closing Original:</span>
                    <span class="detail-value amount"><?php echo formatRupiah($pemasukan_detail['nilai_closing']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Total Sudah Diambil:</span>
                    <span class="detail-value amount negative"><?php echo formatRupiah($total_diambil); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Sisa Closing:</span>
                    <span class="detail-value amount <?php echo $sisa_closing < 0 ? 'negative' : ''; ?>">
                        <?php echo formatRupiah($sisa_closing); ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Status Closing:</span>
                    <span class="detail-value"><?php echo getStatusBadge($pemasukan_detail['status_closing']); ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($riwayat_pengambilan)): ?>
        <!-- Riwayat Pengambilan Lainnya -->
        <h3 style="margin-bottom: 16px; color: var(--text-dark);">
            <i class="fas fa-history"></i> Riwayat Pengambilan Lain dari Closing Ini
        </h3>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tanggal & Waktu</th>
                        <th>Karyawan</th>
                        <th>Jumlah Diambil</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($riwayat_pengambilan as $riwayat): ?>
                    <tr>
                        <td>PMS-<?php echo $riwayat['id']; ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($riwayat['tanggal'] . ' ' . $riwayat['waktu'])); ?></td>
                        <td><?php echo htmlspecialchars($riwayat['nama_karyawan']); ?></td>
                        <td style="color: var(--danger-color); font-weight: 600;">
                            <?php echo formatRupiah($riwayat['jumlah']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($riwayat['keterangan_transaksi']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: var(--background-light); font-weight: 600;">
                        <td colspan="3">Total Pengambilan (Termasuk Saat Ini):</td>
                        <td style="color: var(--danger-color);"><?php echo formatRupiah($total_diambil); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($sisa_closing < 0): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Peringatan:</strong> Total pengambilan sudah melebihi nilai closing original! 
            Selisih: <?php echo formatRupiah(abs($sisa_closing)); ?>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div style="margin-top: 32px; display: flex; gap: 16px;">
            <a href="pemasukan_kasir.php?kode_transaksi=<?php echo htmlspecialchars($pemasukan_detail['kode_transaksi']); ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Kembali ke Pemasukan
            </a>
            
            <?php if (!empty($pemasukan_detail['nomor_transaksi_closing'])): ?>
            <a href="detail_closing.php?kode_transaksi=<?php echo htmlspecialchars($pemasukan_detail['nomor_transaksi_closing']); ?>" class="btn btn-primary">
                <i class="fas fa-eye"></i> Lihat Detail Closing
            </a>
            <?php endif; ?>
            
            <button onclick="window.print()" class="btn btn-secondary">
                <i class="fas fa-print"></i> Cetak Detail
            </button>
        </div>
    </div>

    <script>
        // Print functionality
        window.addEventListener('beforeprint', function() {
            document.querySelector('.sidebar').style.display = 'none';
            document.querySelector('.main-content').style.marginLeft = '0';
        });

        window.addEventListener('afterprint', function() {
            document.querySelector('.sidebar').style.display = 'block';
            document.querySelector('.main-content').style.marginLeft = '250px';
        });
    </script>
</body>
</html>