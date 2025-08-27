<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'config.php'; // Assumes PDO connection is defined here as $pdo

// Check if user is logged in and has the correct role
if (!isset($_SESSION['kode_karyawan']) || 
    ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super_admin')) {
    header('Location: ../../login_dashboard/login.php');
    exit();
}

// Determine user role
$is_super_admin = false;
$is_admin = false;
$kode_karyawan = $_SESSION['kode_karyawan'];

$query = "SELECT role FROM users WHERE kode_karyawan = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$kode_karyawan]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    if ($user['role'] === 'super_admin') {
        $is_super_admin = true;
    } elseif ($user['role'] === 'admin') {
        $is_admin = true;
    }
} else {
    echo "Pengguna tidak ditemukan";
    exit();
}

// Handle filter reset
if (isset($_GET['all'])) {
    unset($_SESSION['tanggal_awal'], $_SESSION['tanggal_akhir'], $_SESSION['cabang'], $_SESSION['status']);
    header('Location: admin_dashboard.php');
    exit();
}

// Initialize variables
$result_transaksi = [];
$tanggal_awal = '';
$tanggal_akhir = '';
$cabang = 'all';
$status = 'all';

// Handle filter form submission or existing session
if (isset($_POST['filter']) || isset($_SESSION['tanggal_awal']) || isset($_SESSION['status'])) {
    if (isset($_POST['filter'])) {
        $_SESSION['tanggal_awal'] = $_POST['tanggal_awal'] ?? '';
        $_SESSION['tanggal_akhir'] = $_POST['tanggal_akhir'] ?? '';
        $_SESSION['cabang'] = $_POST['cabang'] ?? 'all';
        $_SESSION['status'] = $_POST['status'] ?? 'all';
    }

    // Assign session variables with defaults
    $tanggal_awal = isset($_SESSION['tanggal_awal']) ? $_SESSION['tanggal_awal'] : '';
    $tanggal_akhir = isset($_SESSION['tanggal_akhir']) ? $_SESSION['tanggal_akhir'] : '';
    $cabang = isset($_SESSION['cabang']) ? $_SESSION['cabang'] : 'all';
    $status = isset($_SESSION['status']) ? $_SESSION['status'] : 'all';

    // Validate dates
    if (!empty($tanggal_awal) && !empty($tanggal_akhir)) {
        if (strtotime($tanggal_awal) > strtotime($tanggal_akhir)) {
            die('Error: Tanggal awal tidak boleh lebih besar dari tanggal akhir.');
        }
    }

    // Build query with PDO
    $query = "
        SELECT kt.*, kt.nama_cabang, u.nama_karyawan AS kasir_name
        FROM kasir_transactions kt
        JOIN users u ON kt.kode_karyawan = u.kode_karyawan
        WHERE kt.tanggal_transaksi BETWEEN ? AND ?
    ";
    $params = [$tanggal_awal, $tanggal_akhir];

    if ($cabang && $cabang !== 'all') {
        $query .= " AND kt.nama_cabang COLLATE utf8mb4_general_ci = ?";
        $params[] = $cabang;
    }

    if ($status && $status !== 'all') {
        $query .= " AND kt.status = ?";
        $params[] = $status;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $result_transaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// User info
$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User';

// Fetch cabang options
$query_cabang = "SELECT DISTINCT TRIM(LOWER(nama_cabang)) as nama_cabang 
                 FROM kasir_transactions 
                 WHERE nama_cabang IS NOT NULL AND nama_cabang != ''";
$stmt_cabang = $pdo->query($query_cabang);
$cabang_options = $stmt_cabang->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Laporan Kasir</title>
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
        }
        .info-tag {
            background: var(--background-light);
            padding: 8px 12px;
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-dark);
        }
        .filter-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .filter-card h5 {
            margin-bottom: 20px;
            color: var(--text-dark);
            font-weight: 600;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
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
        .btn-info {
            background-color: var(--info-color);
            color: white;
            padding: 6px 12px;
            font-size: 12px;
        }
        .btn-info:hover {
            background-color: #138496;
        }
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
        }
        .table-header {
            background: var(--background-light);
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
        }
        .table-header h2 {
            margin: 0;
            color: var(--text-dark);
            font-size: 18px;
            font-weight: 600;
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
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-on-proses {
            background: rgba(255,193,7,0.1);
            color: #e0a800;
        }
        .status-end-proses {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: center;
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
            .form-grid {
                grid-template-columns: 1fr;
            }
            .info-tags {
                flex-direction: column;
                gap: 10px;
            }
            .filter-actions {
                flex-direction: column;
                width: 100%;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
    <div class="user-profile">
        <div class="user-avatar"><?php echo htmlspecialchars(strtoupper(substr($username, 0, 1))); ?></div>
        <div>
            <strong><?php echo htmlspecialchars($username); ?></strong>
            <p style="color: var(--text-muted); font-size: 12px;"><?php echo htmlspecialchars(ucfirst($role)); ?></p>
        </div>
    </div>

    <div class="welcome-card">
        <h1><i class="fas fa-chart-bar"></i> Dashboard Admin - Laporan Kasir</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Kelola dan pantau semua transaksi kasir dari berbagai cabang</p>
        <div class="info-tags">
            <div class="info-tag">User: <?php echo htmlspecialchars($username); ?></div>
            <div class="info-tag">Role: <?php echo htmlspecialchars(ucfirst($role)); ?></div>
            <div class="info-tag">Tanggal: <?php echo date('d M Y'); ?></div>
        </div>
    </div>

    <div class="filter-card">
        <h5><i class="fas fa-filter"></i> Filter Data Berdasarkan Tanggal, Cabang, dan Status</h5>
        
        <form action="" method="POST">
            <div class="form-grid">
                <div class="form-group">
                    <label for="tanggal_awal" class="form-label">Tanggal Awal</label>
                    <input type="date" name="tanggal_awal" class="form-control" required 
                           value="<?php echo htmlspecialchars($tanggal_awal); ?>">
                </div>
                <div class="form-group">
                    <label for="tanggal_akhir" class="form-label">Tanggal Akhir</label>
                    <input type="date" name="tanggal_akhir" class="form-control" required 
                           value="<?php echo htmlspecialchars($tanggal_akhir); ?>">
                </div>
                <div class="form-group">
                    <label for="cabang" class="form-label">Cabang</label>
                    <select name="cabang" id="cabang" class="form-control" required>
                        <option value="all" <?php echo ($cabang == 'all') ? 'selected' : ''; ?>>Semua Cabang</option>
                        <?php foreach ($cabang_options as $cabang_option): ?>
                            <option value="<?php echo htmlspecialchars($cabang_option['nama_cabang']); ?>" 
                                    <?php echo ($cabang == $cabang_option['nama_cabang']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($cabang_option['nama_cabang'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-control" required>
                        <option value="all" <?php echo ($status == 'all') ? 'selected' : ''; ?>>Semua Proses</option>
                        <option value="On Proses" <?php echo ($status == 'On Proses') ? 'selected' : ''; ?>>On Proses</option>
                        <option value="End Proses" <?php echo ($status == 'End Proses') ? 'selected' : ''; ?>>End Proses</option>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" name="filter" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filter Data
                    </button>
                    <?php if (!empty($tanggal_awal)): ?>
                        <a href="?all=1" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Reset Filter
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <?php if (!empty($tanggal_awal)): ?>
    <div class="table-container">
        <div class="table-header">
            <h2><i class="fas fa-table"></i> Hasil Laporan Transaksi</h2>
        </div>
        <table class="table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>No Transaksi</th>
                    <th>Nama Kasir</th>
                    <th>Cabang</th>
                    <th>Tanggal Transaksi</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($result_transaksi) > 0): ?>
                    <?php $no = 1; ?>
                    <?php foreach ($result_transaksi as $row): ?>
                        <?php $status_class = strtolower(str_replace(' ', '-', $row['status'])); ?>
                        <tr>
                            <td><?php echo $no; ?></td>
                            <td><strong><?php echo htmlspecialchars($row['kode_transaksi']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['kasir_name']); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($row['nama_cabang'])); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($row['tanggal_transaksi'])); ?></td>
                            <td><span class="status-badge status-<?php echo $status_class; ?>">
                                <?php echo htmlspecialchars($row['status']); ?>
                            </span></td>
                            <td><a href="view_transaksi_admin.php?kode_transaksi=<?php echo htmlspecialchars($row['kode_transaksi']); ?>" 
                                   class="btn btn-info"><i class="fas fa-eye"></i> View</a></td>
                        </tr>
                        <?php $no++; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="no-data">
                            <i class="fas fa-exclamation-circle"></i><br>
                            Tidak ada data yang ditemukan untuk kriteria filter yang dipilih.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
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

        const minWidth = 200;
        sidebar.style.width = maxWidth > minWidth ? `${maxWidth + 20}px` : `${minWidth}px`;
        document.querySelector('.main-content').style.marginLeft = sidebar.style.width;
    }

    // Run on page load and window resize
    window.addEventListener('load', adjustSidebarWidth);
    window.addEventListener('resize', adjustSidebarWidth);

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const tanggalAwal = document.querySelector('input[name="tanggal_awal"]').value;
        const tanggalAkhir = document.querySelector('input[name="tanggal_akhir"]').value;
        
        if (tanggalAwal && tanggalAkhir && new Date(tanggalAwal) > new Date(tanggalAkhir)) {
            e.preventDefault();
            alert('Tanggal awal tidak boleh lebih besar dari tanggal akhir!');
            return false;
        }
    });
</script>

</body>
</html>