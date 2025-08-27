<?php
// Include config file for database connection
include('config.php');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session and ensure the user is an admin
session_start();
if (!isset($_SESSION['kode_karyawan']) || 
    ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super_admin')) {
header('Location: ../../login_dashboard/login.php');
    exit;
}

$is_super_admin = false;
$is_admin = false;
$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$cabang_user = $_SESSION['cabang'] ?? 'Cabang Tidak Ditemukan';
$role = $_SESSION['role'] ?? 'User';

$query = "SELECT role FROM users WHERE kode_karyawan = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$kode_karyawan]);
$user = $stmt->fetch();

if ($user) {
    if ($user['role'] === 'super_admin') {
        $is_super_admin = true;
    } elseif ($user['role'] === 'admin') {
        $is_admin = true;
    }
} else {
    echo "Pengguna tidak ditemukan";
}

// Koneksi ke database fitmotor_maintance-beta
$host = "localhost";
$db_user = "fitmotor_LOGIN";
$db_password = "Sayalupa12";
$db_name = "fitmotor_maintance-beta";
$conn = mysqli_connect($host, $db_user, $db_password, $db_name);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Fungsi untuk mengecek apakah kode_akun sudah ada di database
function is_duplicate_kode_akun($conn, $kode_akun, $id = null) {
    $sql = "SELECT * FROM master_akun WHERE kode_akun = '$kode_akun'";
    if ($id) {
        $sql .= " AND id != $id"; // Exclude current record when updating
    }
    $result = mysqli_query($conn, $sql);
    return mysqli_num_rows($result) > 0;
}

$success_message = '';
$error_message = '';

// CREATE (Menambah data akun baru)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create'])) {
    $kode_akun = strtoupper($_POST['kode_akun']);
    $arti = strtoupper($_POST['arti']);  // Mengubah ke huruf besar
    $jenis_akun = strtoupper($_POST['jenis_akun']); // Mengubah ke huruf besar
    $kategori = null; // Initialize kategori
    $require_umur_pakai = isset($_POST['require_umur_pakai']) ? 1 : 0;
    $min_umur_pakai = $_POST['min_umur_pakai'] ?? 0;

    // Set kategori jika jenis akun adalah pengeluaran
    if ($jenis_akun === 'PENGELUARAN') {
        $kategori = $_POST['kategori'];
    }

    // Cek apakah kode_akun sudah ada
    if (is_duplicate_kode_akun($conn, $kode_akun)) {
        $error_message = "Error: Kode akun '$kode_akun' sudah ada!";
    } else {
        // Jika tidak ada duplikat, masukkan ke database
        $sql = "INSERT INTO master_akun (kode_akun, arti, jenis_akun, kategori, require_umur_pakai, min_umur_pakai) 
                VALUES ('$kode_akun', '$arti', '$jenis_akun', '$kategori', '$require_umur_pakai', '$min_umur_pakai')";
        
        if (mysqli_query($conn, $sql)) {
            $success_message = "Data akun berhasil ditambahkan!";
            header("Refresh: 2; URL=master_akun.php");
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    }
}

// UPDATE (Mengupdate data akun yang sudah ada)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update'])) {
    $id = $_POST['id'];
    $kode_akun = strtoupper($_POST['kode_akun']);
    $arti = strtoupper($_POST['arti']);
    $jenis_akun = strtoupper($_POST['jenis_akun']);
    $kategori = null;
    $require_umur_pakai = isset($_POST['require_umur_pakai']) ? 1 : 0;
    $min_umur_pakai = $_POST['min_umur_pakai'] ?? 0;

    // Set kategori jika jenis akun adalah pengeluaran
    if ($jenis_akun === 'PENGELUARAN') {
        $kategori = $_POST['kategori'];
    }

    // Cek apakah kode_akun sudah ada, kecuali untuk record yang sedang diupdate
    if (is_duplicate_kode_akun($conn, $kode_akun, $id)) {
        $error_message = "Error: Kode akun '$kode_akun' sudah ada!";
    } else {
        // Jika tidak ada duplikat, update datanya
        $sql = "UPDATE master_akun 
                SET kode_akun = '$kode_akun', arti = '$arti', jenis_akun = '$jenis_akun', 
                    kategori = '$kategori', require_umur_pakai = '$require_umur_pakai', 
                    min_umur_pakai = '$min_umur_pakai' 
                WHERE id = $id";
        
        if (mysqli_query($conn, $sql)) {
            $success_message = "Data akun berhasil diupdate!";
            header("Refresh: 2; URL=master_akun.php");
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    }
}

// DELETE (Menghapus data akun)
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $sql = "DELETE FROM master_akun WHERE id = $id";

    if (mysqli_query($conn, $sql)) {
        $success_message = "Data akun berhasil dihapus!";
        header("Refresh: 2; URL=master_akun.php");
    } else {
        $error_message = "Error: " . mysqli_error($conn);
    }
}

// FETCH ALL DATA (Mengambil semua data dari tabel master_akun)
$sql = "SELECT * FROM master_akun ORDER BY jenis_akun, kode_akun";
$result = mysqli_query($conn, $sql);

// FETCH ONE DATA FOR EDIT (Mengambil satu data untuk proses edit)
$edit = false;
if (isset($_GET['edit'])) {
    $edit = true;
    $id = $_GET['edit'];
    $sql = "SELECT * FROM master_akun WHERE id = $id";
    $edit_result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($edit_result);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Akun - Admin Dashboard</title>
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
            flex-wrap: wrap;
        }
        .info-tag {
            background: var(--background-light);
            padding: 8px 12px;
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-dark);
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
        .form-grid {
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
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }
        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary-color);
        }
        .checkbox-group label {
            margin: 0;
            font-weight: 500;
            cursor: pointer;
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
            background-color: #bd2130;
        }
        .btn-warning {
            background-color: var(--warning-color);
            color: #212529;
        }
        .btn-warning:hover {
            background-color: #e0a800;
        }
        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
        }
        .btn-secondary:hover {
            background-color: #545b62;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
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
        .alert-success {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
            border-color: rgba(40,167,69,0.2);
        }
        .alert-danger {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
            border-color: rgba(220,53,69,0.2);
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
        .table-header h3 {
            margin: 0;
            color: var(--text-dark);
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
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
        .jenis-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .jenis-pemasukan {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
        }
        .jenis-pengeluaran {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
        }
        .kategori-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .kategori-biaya {
            background: rgba(255,193,7,0.1);
            color: #e0a800;
        }
        .kategori-non-biaya {
            background: rgba(23,162,184,0.1);
            color: var(--info-color);
        }
        .umur-pakai-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            background: rgba(0,123,255,0.1);
            color: var(--primary-color);
        }
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        .text-required {
            color: var(--danger-color);
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
            .action-buttons {
                flex-direction: column;
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
        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
        <div>
            <strong><?php echo htmlspecialchars($username); ?></strong>
            <p style="color: var(--text-muted); font-size: 12px;"><?php echo htmlspecialchars(ucfirst($role)); ?></p>
        </div>
    </div>

    <div class="welcome-card">
        <h1><i class="fas fa-users-cog"></i> Master Akun</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Kelola kode akun untuk pemasukan dan pengeluaran sistem</p>
        <div class="info-tags">
            <div class="info-tag"><i class="fas fa-user"></i> User: <?php echo htmlspecialchars($username); ?></div>
            <div class="info-tag"><i class="fas fa-shield-alt"></i> Role: <?php echo htmlspecialchars(ucfirst($role)); ?></div>
            <div class="info-tag"><i class="fas fa-calendar-day"></i> Tanggal: <?php echo date('d M Y'); ?></div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <!-- Form Card -->
    <div class="form-card">
        <h3>
            <i class="fas fa-<?php echo $edit ? 'edit' : 'plus'; ?>"></i> 
            <?php echo $edit ? 'Edit Akun Master' : 'Tambah Akun Master'; ?>
        </h3>
        <form action="" method="POST">
            <input type="hidden" name="id" value="<?php echo $edit ? $row['id'] : ''; ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="kode_akun" class="form-label">
                        <i class="fas fa-code"></i> Kode Akun <span class="text-required">*</span>
                    </label>
                    <input type="text" name="kode_akun" class="form-control" required 
                           value="<?php echo $edit ? $row['kode_akun'] : ''; ?>"
                           placeholder="Masukkan kode akun"
                           oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="form-group">
                    <label for="arti" class="form-label">
                        <i class="fas fa-align-left"></i> Arti/Deskripsi <span class="text-required">*</span>
                    </label>
                    <input type="text" name="arti" class="form-control" required 
                           value="<?php echo $edit ? $row['arti'] : ''; ?>"
                           placeholder="Masukkan deskripsi akun"
                           oninput="this.value = this.value.toUpperCase()">
                </div>
                <div class="form-group">
                    <label for="jenis_akun" class="form-label">
                        <i class="fas fa-tags"></i> Jenis Akun <span class="text-required">*</span>
                    </label>
                    <select name="jenis_akun" class="form-control" onchange="toggleKategori()" required>
                        <option value="">-- Pilih Jenis Akun --</option>
                        <option value="PEMASUKAN" <?php echo ($edit && $row['jenis_akun'] === 'PEMASUKAN') ? 'selected' : ''; ?>>PEMASUKAN</option>
                        <option value="PENGELUARAN" <?php echo ($edit && $row['jenis_akun'] === 'PENGELUARAN') ? 'selected' : ''; ?>>PENGELUARAN</option>
                    </select>
                </div>
                <div class="form-group" id="kategoriInput" style="<?php echo ($edit && $row['jenis_akun'] === 'PENGELUARAN') ? '' : 'display:none'; ?>">
                    <label for="kategori" class="form-label">
                        <i class="fas fa-list"></i> Kategori
                    </label>
                    <select name="kategori" class="form-control">
                        <option value="BIAYA" <?php echo ($edit && $row['kategori'] === 'BIAYA') ? 'selected' : ''; ?>>BIAYA</option>
                        <option value="NON_BIAYA" <?php echo ($edit && $row['kategori'] === 'NON_BIAYA') ? 'selected' : ''; ?>>NON BIAYA</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="require_umur_pakai" class="form-label">
                        <i class="fas fa-calendar-check"></i> Pengaturan Umur Pakai
                    </label>
                    <div class="checkbox-group">
                        <input type="checkbox" id="require_umur_pakai" name="require_umur_pakai" 
                               onchange="toggleUmurPakai()" 
                               <?php echo ($edit && $row['require_umur_pakai'] == 1) ? 'checked' : ''; ?>>
                        <label for="require_umur_pakai">Memerlukan Input Umur Pakai</label>
                    </div>
                </div>
                <div class="form-group" id="minUmurPakaiInput" style="<?php echo ($edit && $row['require_umur_pakai'] == 1) ? '' : 'display:none'; ?>">
                    <label for="min_umur_pakai" class="form-label">
                        <i class="fas fa-clock"></i> Minimal Umur Pakai (Bulan)
                    </label>
                    <input type="number" name="min_umur_pakai" class="form-control" min="0" 
                           value="<?php echo $edit ? $row['min_umur_pakai'] : '0'; ?>"
                           placeholder="Masukkan minimal umur pakai">
                </div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="submit" name="<?php echo $edit ? 'update' : 'create'; ?>" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?php echo $edit ? 'Update' : 'Tambah'; ?>
                </button>
                <?php if ($edit): ?>
                    <a href="master_akun.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Batal
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Table Card -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-table"></i> Data Master Akun</h3>
        </div>
        <table class="table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Kode Akun</th>
                    <th>Arti/Deskripsi</th>
                    <th>Jenis Akun</th>
                    <th>Kategori</th>
                    <th>Umur Pakai</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (mysqli_num_rows($result) > 0) {
                    $no = 1;
                    while ($row = mysqli_fetch_assoc($result)) {
                        $jenis_class = strtolower($row['jenis_akun']);
                        // Fix untuk menghindari error str_replace dengan null
                        $kategori_class = '';
                        if ($row['kategori'] !== null && $row['kategori'] !== '') {
                            $kategori_class = strtolower(str_replace('_', '-', $row['kategori']));
                        }
                        
                        echo "<tr>";
                        echo "<td><strong>$no</strong></td>";
                        echo "<td><code>" . htmlspecialchars($row['kode_akun']) . "</code></td>";
                        echo "<td>" . htmlspecialchars($row['arti']) . "</td>";
                        echo "<td><span class='jenis-badge jenis-$jenis_class'>" . htmlspecialchars($row['jenis_akun']) . "</span></td>";
                        echo "<td>";
                        if ($row['kategori'] !== null && $row['kategori'] !== '') {
                            echo "<span class='kategori-badge kategori-$kategori_class'>" . htmlspecialchars($row['kategori']) . "</span>";
                        } else {
                            echo "<span style='color: var(--text-muted); font-style: italic;'>-</span>";
                        }
                        echo "</td>";
                        echo "<td>";
                        if ($row['require_umur_pakai'] == 1) {
                            echo "<span class='umur-pakai-badge'>Min: " . $row['min_umur_pakai'] . " bulan</span>";
                        } else {
                            echo "<span style='color: var(--text-muted); font-style: italic;'>Tidak diperlukan</span>";
                        }
                        echo "</td>";
                        echo "<td class='action-buttons'>
                            <a href='?edit={$row['id']}' class='btn btn-warning btn-sm' title='Edit'>
                                <i class='fas fa-edit'></i>
                            </a>
                            <a href='?delete={$row['id']}' class='btn btn-danger btn-sm' title='Hapus'
                               onclick=\"return confirm('Yakin ingin menghapus akun {$row['kode_akun']}?');\">
                                <i class='fas fa-trash'></i>
                            </a>
                        </td>";
                        echo "</tr>";
                        $no++;
                    }
                } else {
                    echo "<tr><td colspan='7' class='no-data'><i class='fas fa-inbox'></i><br>Belum ada data akun</td></tr>";
                }
                ?>
            </tbody>
        </table>
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

        const minWidth = 250;
        sidebar.style.width = maxWidth > minWidth ? `${maxWidth + 30}px` : `${minWidth}px`;
        document.querySelector('.main-content').style.marginLeft = sidebar.style.width;
    }

    function toggleKategori() {
        const jenisAkun = document.querySelector('select[name="jenis_akun"]').value;
        const kategoriInput = document.getElementById('kategoriInput');
        if (jenisAkun === 'PENGELUARAN') {
            kategoriInput.style.display = 'block';
        } else {
            kategoriInput.style.display = 'none';
        }
    }

    function toggleUmurPakai() {
        const requireUmurPakai = document.getElementById('require_umur_pakai').checked;
        const minUmurPakaiInput = document.getElementById('minUmurPakaiInput');
        if (requireUmurPakai) {
            minUmurPakaiInput.style.display = 'block';
        } else {
            minUmurPakaiInput.style.display = 'none';
        }
    }

    // Run on page load and window resize
    window.addEventListener('load', adjustSidebarWidth);
    window.addEventListener('resize', adjustSidebarWidth);

    // Auto-hide alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    alert.remove();
                }, 300);
            }, 5000);
        });
    });
</script>

</body>
</html>