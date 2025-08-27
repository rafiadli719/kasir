<?php
// Start the session at the very beginning
session_start();

// Include config file for database connection
include('config.php');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if the user is logged in and has a role of admin or super_admin
if (!isset($_SESSION['kode_karyawan']) || !isset($_SESSION['role']) || 
    !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

// Define role-based flags for sidebar display
$role_session = $_SESSION['role'];
$is_super_admin = ($role_session === 'super_admin');
$is_admin = ($role_session === 'admin');
$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User';

// Database connection
$conn = new mysqli('localhost', 'fitmotor_LOGIN', 'Sayalupa12', 'fitmotor_maintance-beta');
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Get search and filter parameters
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$branch_filter = isset($_GET['branch_filter']) ? $conn->real_escape_string($_GET['branch_filter']) : '';
$status_filter = isset($_GET['status_filter']) ? $conn->real_escape_string($_GET['status_filter']) : '';

// Build query for employees
$sql = "SELECT DISTINCT mk.kode_karyawan, mk.nama_karyawan, mk.kode_cabang, mk.status_aktif, cab.nama_cabang
        FROM masterkeys mk
        LEFT JOIN cabang cab ON mk.kode_cabang = cab.kode_cabang
        WHERE (mk.nama_karyawan LIKE '%$search%' OR mk.kode_karyawan LIKE '%$search%')";

if (!empty($branch_filter)) {
    $sql .= " AND mk.kode_cabang = '$branch_filter'";
}

if ($status_filter !== '') {
    $sql .= " AND mk.status_aktif = '$status_filter'";
}

$sql .= " ORDER BY mk.kode_karyawan ASC, mk.nama_karyawan ASC";
$result = $conn->query($sql);

// Get branch data for filter dropdown
$branch_sql = "SELECT DISTINCT kode_cabang, nama_cabang FROM cabang ORDER BY nama_cabang";
$branch_result = $conn->query($branch_sql);

// Count total employees
$count_sql = "SELECT COUNT(DISTINCT kode_karyawan) as total FROM masterkeys";
$count_result = $conn->query($count_sql);
$total_employees = $count_result->fetch_assoc()['total'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Karyawan - Admin Dashboard</title>
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
        .stats-card {
            background: linear-gradient(135deg, var(--info-color), #138496);
            color: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px rgba(23,162,184,0.1);
        }
        .stats-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .stats-info h4 {
            margin: 0 0 5px 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .stats-info .stats-text {
            font-size: 18px;
            font-weight: bold;
            margin: 0;
        }
        .stats-icon {
            font-size: 36px;
            opacity: 0.8;
        }
        .filter-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .filter-card h3 {
            margin-bottom: 20px;
            color: var(--text-dark);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            background-color: #545b62;
        }
        .btn-info {
            background-color: var(--info-color);
            color: white;
        }
        .btn-info:hover {
            background-color: #138496;
        }
        .btn-sm {
            padding: 8px 12px;
            font-size: 12px;
        }
        .table-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .table-card h3 {
            margin-bottom: 20px;
            color: var(--text-dark);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .table-wrapper {
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }
        th, td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        th {
            background: var(--background-light);
            color: var(--text-dark);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        tr:hover {
            background: rgba(0,123,255,0.05);
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-active {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
        }
        .status-inactive {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        .filter-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
            .filter-grid {
                grid-template-columns: 1fr;
            }
            .info-tags {
                flex-direction: column;
                gap: 10px;
            }
            .filter-actions {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                justify-content: center;
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
<div class="sidebar" id="sidebar">
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
        <a href="view_employees.php" class="active"><i class="fas fa-id-card"></i> View Master Karyawan</a>
        <a href="cabang.php"><i class="fas fa-building"></i> Master Cabang</a>
        <a href="master_rekening_cabang.php"><i class="fas fa-university"></i> Master Rekening</a>
        <a href="setoran_keuangan.php"><i class="fas fa-hand-holding-usd"></i> Manajemen Setoran</a>
        <a href="keuangan_pusat.php"><i class="fas fa-wallet"></i> Keuangan Pusat</a>
    <?php endif; ?>
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Kembali ke Dashboard</a>
</div>
<div class="main-content">
    <div class="user-profile">
        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
        <div>
            <strong><?php echo htmlspecialchars($username); ?></strong>
            <p style="color: var(--text-muted); font-size: 12px;"><?php echo htmlspecialchars(ucfirst($role)); ?></p>
        </div>
    </div>

    <div class="welcome-card">
        <h1><i class="fas fa-list"></i> Daftar Karyawan</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Kelola dan lihat semua data karyawan dalam sistem</p>
        <div class="info-tags">
            <div class="info-tag"><i class="fas fa-user"></i> User: <?php echo htmlspecialchars($username); ?></div>
            <div class="info-tag"><i class="fas fa-shield-alt"></i> Role: <?php echo htmlspecialchars(ucfirst($role)); ?></div>
            <div class="info-tag"><i class="fas fa-calendar-day"></i> Tanggal: <?php echo date('d M Y'); ?></div>
        </div>
    </div>

    <!-- Statistics Card -->
    <div class="stats-card">
        <div class="stats-content">
            <div class="stats-info">
                <h4>Total Karyawan Terdaftar</h4>
                <p class="stats-text"><?php echo number_format($total_employees); ?> Karyawan</p>
            </div>
            <div class="stats-icon">
                <i class="fas fa-users"></i>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="filter-card">
        <h3><i class="fas fa-filter"></i> Filter & Pencarian</h3>
        
        <form action="view_employees.php" method="GET">
            <div class="filter-grid">
                <div class="form-group">
                    <label for="search" class="form-label">
                        <i class="fas fa-search"></i> Cari Karyawan
                    </label>
                    <input type="text" 
                           id="search" 
                           name="search" 
                           class="form-control"
                           placeholder="Nama Karyawan atau Kode Karyawan"
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="form-group">
                    <label for="branch_filter" class="form-label">
                        <i class="fas fa-building"></i> Filter Cabang
                    </label>
                    <select id="branch_filter" name="branch_filter" class="form-control">
                        <option value="">Semua Cabang</option>
                        <?php
                        $branch_result->data_seek(0); // Reset result pointer
                        while($branch_row = $branch_result->fetch_assoc()) {
                            $selected = ($branch_filter == $branch_row['kode_cabang']) ? 'selected' : '';
                            echo "<option value='" . $branch_row['kode_cabang'] . "' $selected>" . htmlspecialchars($branch_row['nama_cabang']) . " (" . $branch_row['kode_cabang'] . ")</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="status_filter" class="form-label">
                        <i class="fas fa-toggle-on"></i> Status Aktif
                    </label>
                    <select id="status_filter" name="status_filter" class="form-control">
                        <option value="">Semua Status</option>
                        <option value="1" <?php echo ($status_filter === '1') ? 'selected' : ''; ?>>Aktif</option>
                        <option value="0" <?php echo ($status_filter === '0') ? 'selected' : ''; ?>>Tidak Aktif</option>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Cari
                </button>
                <button type="button" class="btn btn-secondary" onclick="window.location.href='view_employees.php'">
                    <i class="fas fa-refresh"></i> Reset
                </button>
                <a href="masterkey.php" class="btn btn-success">
                    <i class="fas fa-plus"></i> Tambah Karyawan
                </a>
            </div>
        </form>
    </div>

    <!-- Table Card -->
    <div class="table-card">
        <h3><i class="fas fa-table"></i> Data Karyawan</h3>
        
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th><i class="fas fa-id-badge"></i> Kode Karyawan</th>
                        <th><i class="fas fa-user"></i> Nama Karyawan</th>
                        <th><i class="fas fa-building"></i> Cabang</th>
                        <th><i class="fas fa-toggle-on"></i> Status</th>
                        <th><i class="fas fa-cogs"></i> Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            echo "<tr>";
                            echo "<td><strong>" . htmlspecialchars($row['kode_karyawan']) . "</strong></td>";
                            echo "<td>" . htmlspecialchars($row['nama_karyawan']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['kode_cabang']) . " (" . htmlspecialchars($row['nama_cabang']) . ")</td>";
                            
                            // Status badge
                            $status_class = ($row['status_aktif'] == 1) ? 'status-active' : 'status-inactive';
                            $status_text = ($row['status_aktif'] == 1) ? 'Aktif' : 'Tidak Aktif';
                            echo "<td><span class='status-badge $status_class'>$status_text</span></td>";
                            
                            // Action buttons
                            echo "<td>";
                            echo "<a href='edit_employee.php?id=" . urlencode($row['kode_karyawan']) . "' class='btn btn-primary btn-sm' style='margin-right: 8px;'>";
                            echo "<i class='fas fa-edit'></i> Edit";
                            echo "</a>";
                            echo "<a href='delete_employee.php?id=" . urlencode($row['kode_karyawan']) . "' class='btn btn-danger btn-sm' onclick='return confirm(\"Apakah Anda yakin ingin menghapus karyawan ini?\")'>";
                            echo "<i class='fas fa-trash'></i> Hapus";
                            echo "</a>";
                            echo "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='5' class='no-data'>";
                        echo "<i class='fas fa-search' style='font-size: 48px; color: var(--text-muted); margin-bottom: 10px;'></i><br>";
                        echo "Tidak ada data karyawan yang ditemukan";
                        echo "</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="text-align: center;">
        <a href="masterkey.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Kembali ke Master Karyawan
        </a>
    </div>
</div>

<script>
    // Adjust sidebar width based on content
    function adjustSidebarWidth() {
        const sidebar = document.querySelector('.sidebar');
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
</script>

</body>
</html>

<?php
$conn->close();
?>