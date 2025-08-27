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

if (isset($_SESSION['transaksi_error'])) {
    echo "<script>alert('" . $_SESSION['transaksi_error'] . "');</script>";
    unset($_SESSION['transaksi_error']);
}

$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';

// Handle filter form submission
if (isset($_POST['tanggal_awal']) && isset($_POST['tanggal_akhir'])) {
    $_SESSION['tanggal_awal'] = $_POST['tanggal_awal'];
    $_SESSION['tanggal_akhir'] = $_POST['tanggal_akhir'];
}

// Handle filter reset
if (isset($_POST['reset_filter'])) {
    unset($_SESSION['tanggal_awal']);
    unset($_SESSION['tanggal_akhir']);
}

$tanggal_awal = $_SESSION['tanggal_awal'] ?? '';
$tanggal_akhir = $_SESSION['tanggal_akhir'] ?? '';

$sql_cabang = "SELECT nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan LIMIT 1";
$stmt_cabang = $pdo->prepare($sql_cabang);
$stmt_cabang->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_cabang->execute();
$cabang_data = $stmt_cabang->fetch(PDO::FETCH_ASSOC);
$cabang = $cabang_data['nama_cabang'] ?? 'Unknown Cabang';

// Check for ongoing transactions
$sql_check_on_proses = "SELECT COUNT(*) as count_on_proses FROM kasir_transactions WHERE kode_karyawan = :kode_karyawan AND status = 'on proses'";
$stmt_check = $pdo->prepare($sql_check_on_proses);
$stmt_check->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_check->execute();
$on_proses_count = $stmt_check->fetchColumn();

$button_disabled = ($on_proses_count > 0);
$button_message = $button_disabled ? 'Selesaikan transaksi yang sedang berjalan terlebih dahulu' : 'Mulai transaksi kas awal baru';

$limit = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$sql = "
    SELECT 
        kt.id,
        kt.kode_transaksi,
        u.nama_karyawan AS username,
        kt.nama_cabang AS cabang,
        kt.kas_awal,
        kt.kas_akhir,
        kt.total_pemasukan,
        kt.total_pengeluaran,
        kt.total_penjualan,
        kt.total_servis,
        kt.status,
        kt.deposit_status,
        kt.deposit_difference_status,
        kt.tanggal_transaksi,
        stk.status as serah_terima_status
    FROM kasir_transactions kt
    JOIN users u ON kt.kode_karyawan = u.kode_karyawan
    LEFT JOIN serah_terima_kasir stk ON kt.kode_transaksi = stk.kode_transaksi_asal
    WHERE u.kode_karyawan = :kode_karyawan";

if (!empty($tanggal_awal) && !empty($tanggal_akhir)) {
    $sql .= " AND kt.tanggal_transaksi BETWEEN :tanggal_awal AND :tanggal_akhir";
}

$sql .= " ORDER BY kt.kode_transaksi DESC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
$stmt->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);

if (!empty($tanggal_awal) && !empty($tanggal_akhir)) {
    $stmt->bindParam(':tanggal_awal', $tanggal_awal, PDO::PARAM_STR);
    $stmt->bindParam(':tanggal_akhir', $tanggal_akhir, PDO::PARAM_STR);
}

$stmt->execute();
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sql_count = "
    SELECT COUNT(*) AS total 
    FROM kasir_transactions 
    WHERE kode_karyawan = :kode_karyawan";

if (!empty($tanggal_awal) && !empty($tanggal_akhir)) {
    $sql_count .= " AND tanggal_transaksi BETWEEN :tanggal_awal AND :tanggal_akhir";
}

$stmt_count = $pdo->prepare($sql_count);
$stmt_count->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);

if (!empty($tanggal_awal) && !empty($tanggal_akhir)) {
    $stmt_count->bindParam(':tanggal_awal', $tanggal_awal, PDO::PARAM_STR);
    $stmt_count->bindParam(':tanggal_akhir', $tanggal_akhir, PDO::PARAM_STR);
}

$stmt_count->execute();
$total_transactions = $stmt_count->fetchColumn();
$total_pages = ceil($total_transactions / $limit);

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Kasir</title>
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

        .welcome-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
        }

        .welcome-card h2 {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .welcome-card p {
            color: var(--text-muted);
            margin-bottom: 15px;
        }

        .info-tags {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            margin-bottom: 20px;
        }

        .info-tag {
            background: var(--background-light);
            padding: 8px 12px;
            border-radius: 12px;
            font-size: 14px;
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

        .table-container {
            background: white;
            border-radius: 12px;
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

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
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

        .btn-success {
            background-color: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background-color: #218838;
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        .btn-outline-secondary {
            background-color: transparent;
            border: 1px solid var(--secondary-color);
            color: var(--secondary-color);
        }

        .btn-outline-secondary:hover {
            background-color: #e9ecef;
            color: #5a6268;
        }

        .btn-new-transaction {
            background: linear-gradient(135deg, var(--success-color), #20c997);
            color: white;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
            transition: all 0.3s ease;
            width: auto;
            display: inline-block;
            max-width: 250px;
        }

        .btn-new-transaction:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
            background: linear-gradient(135deg, #218838, #1e7e34);
        }

        .btn-new-transaction:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-new-transaction:disabled:hover {
            background: #6c757d;
            transform: none;
            box-shadow: none;
        }

        .button-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 12px;
            margin-top: 8px;
            display: inline-block;
            max-width: 350px;
        }

        .button-container {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }

        .filter-container {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-container label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .filter-container input[type="date"] {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 14px;
            background: var(--background-light);
            transition: border-color 0.3s ease;
            width: 160px;
        }

        .filter-container input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(0,123,255,0.2);
        }

        .filter-container .btn {
            padding: 8px 16px;
            font-size: 14px;
        }

        .filter-container .btn-reset {
            background: var(--secondary-color);
            color: white;
        }

        .filter-container .btn-reset:hover {
            background: #5a6268;
        }

        .text-muted { color: var(--text-muted) !important; }
        .text-warning { color: var(--warning-color) !important; }
        .text-success { color: var(--success-color) !important; }
        .text-primary { color: var(--primary-color) !important; }
        .text-danger { color: var(--danger-color) !important; }
        .text-info { color: #17a2b8 !important; }

        .pagination .page-link {
            background-color: var(--background-light);
            border: 1px solid var(--border-color);
            color: var(--text-dark);
            padding: 8px 12px;
            border-radius: 4px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .pagination .page-link:hover {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 10px;
            font-weight: 600;
            border-radius: 4px;
            text-transform: uppercase;
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

            .filter-container {
                flex-direction: column;
                align-items: flex-start;
                padding: 12px;
            }

            .filter-container input[type="date"] {
                width: 100%;
            }

            .filter-container .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <a href="index_kasir.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard Kasir</a>
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

        <div class="welcome-card">
            <h2><i class="fas fa-rocket"></i> Selamat datang di Dashboard!</h2>
            <p>Anda masuk sebagai kasir untuk cabang: <?php echo htmlspecialchars($cabang); ?></p>
            <p>Silakan mulai melakukan pekerjaan Anda.</p>
            <div class="info-tags">
                <div class="info-tag">Role: Kasir</div>
                <div class="info-tag">Cabang: <?php echo htmlspecialchars($cabang); ?></div>
                <div class="info-tag">Tanggal: <?php echo date('d M Y'); ?></div>
            </div>

            <div class="button-container">
                <?php if ($button_disabled): ?>
                    <button class="btn btn-new-transaction" disabled>
                        <i class="fas fa-lock"></i> Mulai Kas Awal Baru
                    </button>
                    <div class="button-warning">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo $button_message; ?>
                    </div>
                <?php else: ?>
                    <a href="verifikasi_kas_awal.php" class="btn btn-new-transaction">
                        <i class="fas fa-plus-circle"></i> Mulai Kas Awal Baru
                    </a>
                    <div style="color: var(--success-color); font-size: 12px; margin-top: 8px;">
                        <i class="fas fa-check-circle"></i> Siap memulai transaksi baru
                    </div>
                <?php endif; ?>
            </div>
        </div>


        <h2 class="mt-4">Transaksi Anda</h2>
        
        <div class="filter-container">
            <form method="POST" action="">
                <label for="tanggal_awal">Tanggal Awal:</label>
                <input type="date" name="tanggal_awal" id="tanggal_awal" value="<?php echo htmlspecialchars($tanggal_awal); ?>" required>
                <label for="tanggal_akhir">Tanggal Akhir:</label>
                <input type="date" name="tanggal_akhir" id="tanggal_akhir" value="<?php echo htmlspecialchars($tanggal_akhir); ?>" required>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <?php if (!empty($tanggal_awal) || !empty($tanggal_akhir)): ?>
                    <button type="submit" name="reset_filter" class="btn btn-reset"><i class="fas fa-undo"></i> Reset Filter</button>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-container">
            <table class="table table-striped">
                <thead class="table-light">
                    <tr>
                        <th>No Transaksi</th>
                        <th>Nama User</th>
                        <th>Cabang</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th>Status Setoran/Serah Terima</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($transactions): ?>
                        <?php foreach ($transactions as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['kode_transaksi'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['username'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['cabang'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['tanggal_transaksi'] ?? ''); ?></td>
                                <td>
                                    <?php if ($row['status'] == 'on proses'): ?>
                                        <span style="color: var(--warning-color); font-weight: 600;">
                                            <i class="fas fa-clock"></i> <?php echo htmlspecialchars($row['status'] ?? ''); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--success-color); font-weight: 600;">
                                            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($row['status'] ?? ''); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="<?php
                                    $display_status = '';
                                    $css_class = '';
                                    $deposit_status = $row['deposit_status'] ?? '';
                                    $serah_terima_status = $row['serah_terima_status'] ?? '';
                                    $deposit_difference_status = $row['deposit_difference_status'] ?? '';

                                    if (!empty($deposit_status) && $deposit_status !== 'NULL') {
                                        $display_status = $deposit_status;
                                        if ($deposit_status == 'Belum Disetor') {
                                            $css_class = 'text-warning';
                                        } elseif ($deposit_status == 'Sudah Disetor ke Bank') {
                                            $css_class = 'text-success';
                                        } elseif ($deposit_status == 'Sedang Dibawa Kurir') {
                                            $css_class = 'text-info';
                                        } elseif ($deposit_status == 'Diterima Staff Keuangan') {
                                            $css_class = 'text-primary';
                                        } elseif ($deposit_status == 'Validasi Keuangan OK') {
                                            $css_class = 'text-success';
                                        } elseif ($deposit_status == 'Validasi Keuangan SELISIH') {
                                            $css_class = 'text-danger';
                                        } else {
                                            $css_class = 'text-primary';
                                        }
                                        if ($deposit_difference_status == 'Selisih') {
                                            $display_status .= ' (Selisih)';
                                        } elseif ($deposit_difference_status == 'Sesuai') {
                                            $display_status .= ' (Sesuai)';
                                        }
                                    } elseif (!empty($serah_terima_status) && $serah_terima_status !== 'NULL') {
                                        $display_status = 'Serah Terima: ';
                                        if ($serah_terima_status == 'pending') {
                                            $display_status .= 'Pending';
                                            $css_class = 'text-warning';
                                        } elseif ($serah_terima_status == 'completed') {
                                            $display_status .= 'Selesai';
                                            $css_class = 'text-success';
                                        } elseif ($serah_terima_status == 'cancelled') {
                                            $display_status .= 'Dibatalkan';
                                            $css_class = 'text-danger';
                                        }
                                    } else {
                                        $display_status = 'Belum Ada';
                                        $css_class = 'text-muted';
                                    }
                                    echo $css_class;
                                ?>">
                                    <?php echo htmlspecialchars($display_status); ?>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'on proses'): ?>
                                        <?php if ($row['kas_awal'] === null): ?>
                                            <button class="btn btn-primary btn-sm" onclick="kasAwal('<?php echo htmlspecialchars($row['kode_transaksi']); ?>')">Kas Awal</button>
                                        <?php else: ?>
                                            <button class="btn btn-success btn-sm" onclick="editKasAwal('<?php echo htmlspecialchars($row['kode_transaksi']); ?>')">Edit Kas Awal</button>
                                        <?php endif; ?>
                                        <?php if ($row['kas_akhir'] === null): ?>
                                            <button class="btn btn-primary btn-sm" onclick="pemasukan('<?php echo htmlspecialchars($row['kode_transaksi']); ?>')">Pemasukan</button>
                                            <button class="btn btn-primary btn-sm" onclick="pengeluaran('<?php echo htmlspecialchars($row['kode_transaksi']); ?>')">Pengeluaran</button>
                                            <button class="btn btn-primary btn-sm" onclick="dataPenjualanServis('<?php echo htmlspecialchars($row['kode_transaksi']); ?>')">Omset</button>
                                            <button class="btn btn-primary btn-sm" onclick="kasAkhir('<?php echo htmlspecialchars($row['kode_transaksi']); ?>')">Kas Akhir</button>
                                            <button class="btn btn-primary btn-sm" onclick="cekData('<?php echo htmlspecialchars($row['kode_transaksi']); ?>')">Cek Data</button>
                                        <?php else: ?>
                                            <button class="btn btn-success btn-sm" onclick="editPemasukan('<?php echo htmlspecialchars($row['kode_transaksi']); ?>')">Pemasukan</button>
                                            <button class="btn btn-success btn-sm" onclick="editPengeluaran('<?php echo htmlspecialchars($row['kode_transaksi']); ?>')">Pengeluaran</button>
                                            <button class="btn btn-success btn-sm" onclick="editPenjualanServis('<?php echo htmlspecialchars($row['kode_transaksi']); ?>')">Omset</button>
                                            <button class="btn btn-success btn-sm" onclick="editKasAkhir('<?php echo htmlspecialchars($row['kode_transaksi']); ?>')">Edit Kas Akhir</button>
                                            <button class="btn btn-danger btn-sm" onclick="closeTransaksi('<?php echo htmlspecialchars($row['kode_transaksi']); ?>')">Closing</button>
                                        <?php endif; ?>
                                    <?php elseif ($row['status'] === 'end proses'): ?>
                                        <button class="btn btn-secondary btn-sm" onclick="viewTransaksi('<?php echo htmlspecialchars($row['kode_transaksi']); ?>')">View</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">Tidak ada transaksi ditemukan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <nav aria-label="Page navigation" class="mt-4">
                <ul class="pagination">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="index_kasir.php?page=<?php echo $page - 1; ?>">Back</a>
                        </li>
                    <?php endif; ?>
                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="index_kasir.php?page=<?php echo $page + 1; ?>">Next</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
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
            sidebar.style.width = `${maxWidth > minWidth ? maxWidth + 20 : minWidth}px`;
            document.querySelector('.main-content').style.marginLeft = sidebar.style.width;
        }

        window.addEventListener('load', adjustSidebarWidth);
        window.addEventListener('resize', adjustSidebarWidth);

        function kasAwal(kode_transaksi) { window.location.href = "kasir_dashboard_baru.php?kode_transaksi=" + kode_transaksi; }
        function editKasAwal(kode_transaksi) { window.location.href = "edit_kas_awal.php?kode_transaksi=" + kode_transaksi; }
        function pemasukan(kode_transaksi) { window.location.href = "pemasukan_kasir.php?kode_transaksi=" + kode_transaksi; }
        function pengeluaran(kode_transaksi) { window.location.href = "pengeluaran_kasir.php?kode_transaksi=" + kode_transaksi; }
        function dataPenjualanServis(kode_transaksi) { window.location.href = "input_penjualan_servis.php?kode_transaksi=" + kode_transaksi; }
        function kasAkhir(kode_transaksi) { window.location.href = "kasir_closing_dashboard.php?kode_transaksi=" + kode_transaksi; }
        function editKasAkhir(kode_transaksi) { window.location.href = "edit_kas_akhir.php?kode_transaksi=" + kode_transaksi; }
        function closeTransaksi(kode_transaksi) { window.location.href = "close_transaksi.php?kode_transaksi=" + kode_transaksi; }
        function viewTransaksi(kode_transaksi) { window.location.href = "view_transaksi.php?kode_transaksi=" + kode_transaksi; }
        function editPemasukan(kode_transaksi) { window.location.href = "edit_pemasukan1.php?kode_transaksi=" + kode_transaksi; }
        function editPengeluaran(kode_transaksi) { window.location.href = "edit_pengeluaran1.php?kode_transaksi=" + kode_transaksi; }
        function editPenjualanServis(kode_transaksi) { window.location.href = "edit_omset1.php?kode_transaksi=" + kode_transaksi; }
        function cekData(kode_transaksi) { window.location.href = 'cek_data.php?kode_transaksi=' + kode_transaksi; }
    </script>
</body>
</html>