<?php
// Include config file for database connection
include('config.php');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session and check if the user is an admin or super_admin
session_start();
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../../login_dashboard/login.php');
    exit;
}
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    die("Akses ditolak. Hanya untuk admin dan super_admin.");
}

$is_super_admin = $_SESSION['role'] === 'super_admin';
$is_admin = $_SESSION['role'] === 'admin';
// Ubah bagian ini yang sudah ada
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User'; // Tambahkan ini


$role_session = $_SESSION['role'];
$is_super_admin = ($role_session === 'super_admin');
$is_admin = ($role_session === 'admin');
$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User';

// Database connection
$conn = new mysqli("localhost", "fitmotor_LOGIN", "Sayalupa12", "fitmotor_maintance-beta");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to generate next user code (kode_user) in AA, AB, AC... format
function generateKodeUser($conn) {
    $last_code_query = "SELECT kode_user FROM users ORDER BY kode_user DESC LIMIT 1";
    $result = $conn->query($last_code_query);
    $last_code_result = $result->fetch_assoc();
    $last_code = $last_code_result['kode_user'] ?? 'A@';

    // Generate next code based on last code
    if ($last_code == 'A@') {
        return 'AA';
    }

    // Simple increment logic for demo - you might want to improve this
    $chars = str_split($last_code);
    if (strlen($last_code) >= 2) {
        $first = $chars[0];
        $second = $chars[1];
        
        if ($second < 'Z') {
            $second = chr(ord($second) + 1);
        } else {
            $second = 'A';
            if ($first < 'Z') {
                $first = chr(ord($first) + 1);
            } else {
                $first = 'A';
            }
        }
        return $first . $second;
    }
    
    return 'AA';
}

$success_message = '';
$error_message = '';

// Handle user creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_user'])) {
    $kode_user = strtoupper($_POST['kode_user']);
    $kode_karyawan_nama = explode(" - ", $_POST['kode_karyawan']);
    $kode_karyawan = $kode_karyawan_nama[0];
    $nama_karyawan = strtoupper($kode_karyawan_nama[1]);
    $role = strtolower($_POST['role']);
    $status = $_POST['status'];
    $kode_cabang_nama = explode(" - ", $_POST['kode_cabang']);
    $kode_cabang = $kode_cabang_nama[0];
    $cabang = $kode_cabang_nama[1];

    // Check if kode_user or kode_karyawan already exists in users
    $stmt_check = $conn->prepare("SELECT id FROM users WHERE kode_user = ? OR kode_karyawan = ?");
    $stmt_check->bind_param("ss", $kode_user, $kode_karyawan);
    $stmt_check->execute();
    $stmt_check->store_result();

    if ($stmt_check->num_rows > 0) {
        $error_message = "Error: Kode User atau Kode Karyawan sudah terdaftar.";
    } else {
        // Insert new user with kode_user, cabang, and role
        $password = NULL;
        $stmt = $conn->prepare("INSERT INTO users (kode_user, kode_karyawan, nama_karyawan, password, role, nama_cabang, kode_cabang, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssii", $kode_user, $kode_karyawan, $nama_karyawan, $password, $role, $cabang, $kode_cabang, $status);

        if ($stmt->execute()) {
            $success_message = "User berhasil dibuat dengan kode: " . $kode_user;
            header("Refresh: 2; URL=users.php");
        } else {
            $error_message = "Error: " . $conn->error;
        }
    }
}

// Handle edit user
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_user'])) {
    $user_id = $_POST['user_id'];
    $kode_user = strtoupper($_POST['kode_user']);
    $role = strtolower($_POST['role']);
    $status = $_POST['status'];
    $kode_cabang_nama = explode(" - ", $_POST['kode_cabang']);
    $kode_cabang = $kode_cabang_nama[0];
    $cabang = $kode_cabang_nama[1];

    // Check if kode_user is unique for editing (ignore current user)
    $stmt_check = $conn->prepare("SELECT id FROM users WHERE kode_user = ? AND id != ?");
    $stmt_check->bind_param("si", $kode_user, $user_id);
    $stmt_check->execute();
    $stmt_check->store_result();

    if ($stmt_check->num_rows > 0) {
        $error_message = "Error: Kode User sudah digunakan oleh pengguna lain.";
    } else {
        // Update user details
        $stmt = $conn->prepare("UPDATE users SET kode_user = ?, role = ?, status = ?, nama_cabang = ?, kode_cabang = ? WHERE id = ?");
        $stmt->bind_param("ssissi", $kode_user, $role, $status, $cabang, $kode_cabang, $user_id);

        if ($stmt->execute()) {
            $success_message = "Data user berhasil diupdate.";
            header("Refresh: 2; URL=users.php");
        } else {
            $error_message = "Error: " . $conn->error;
        }
    }
}

// Handle delete user
if (isset($_GET['delete'])) {
    $user_id = $_GET['delete'];
    
    // Get user info for confirmation message
    $get_user = $conn->query("SELECT kode_user, nama_karyawan FROM users WHERE id = $user_id");
    $user_data = $get_user->fetch_assoc();
    
    $conn->query("DELETE FROM users WHERE id = $user_id");
    $success_message = "User " . $user_data['kode_user'] . " (" . $user_data['nama_karyawan'] . ") berhasil dihapus.";
    header("Refresh: 2; URL=users.php");
}

// Fetch users from the database
$sql_users = "SELECT * FROM users ORDER BY kode_user ASC";
$result_users = $conn->query($sql_users);

// Fetch user for editing
$edit_user = null;
if (isset($_GET['edit'])) {
    $user_id = $_GET['edit'];
    $result = $conn->query("SELECT * FROM users WHERE id = $user_id");
    $edit_user = $result->fetch_assoc();
}

// Fetch kode_karyawan and nama_karyawan from masterkeys if not in users
$sql_available_employees = "SELECT mk.kode_karyawan, mk.nama_karyawan FROM masterkeys mk
                            LEFT JOIN users u ON mk.kode_karyawan = u.kode_karyawan
                            WHERE u.kode_karyawan IS NULL";
$result_available_employees = $conn->query($sql_available_employees);

// Fetch kode_cabang and nama_cabang from masterkeys for cabang dropdown
$sql_cabang_options = "SELECT DISTINCT kode_cabang, nama_cabang FROM masterkeys ORDER BY nama_cabang";
$result_cabang_options = $conn->query($sql_cabang_options);

// Calculate statistics
$total_users = $result_users->num_rows;
$active_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE status = 1")->fetch_assoc()['count'];
$total_roles = $conn->query("SELECT COUNT(DISTINCT role) as count FROM users")->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master User - Admin Dashboard</title>
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
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--primary-color);
        }
        .stats-card.success {
            border-left-color: var(--success-color);
        }
        .stats-card.warning {
            border-left-color: var(--warning-color);
        }
        .stats-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .stats-info h4 {
            margin: 0 0 5px 0;
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .stats-info .stats-number {
            font-size: 20px;
            font-weight: bold;
            margin: 0;
            color: var(--text-dark);
        }
        .stats-icon {
            font-size: 28px;
            opacity: 0.7;
            color: var(--primary-color);
        }
        .stats-card.success .stats-icon {
            color: var(--success-color);
        }
        .stats-card.warning .stats-icon {
            color: var(--warning-color);
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
        .form-control:disabled {
            background-color: var(--background-light);
            color: var(--text-muted);
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
        .table-wrapper {
            overflow-x: auto;
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
        .role-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .role-admin {
            background: rgba(255,193,7,0.1);
            color: #e0a800;
        }
        .role-super_admin {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
        }
        .role-kasir {
            background: rgba(23,162,184,0.1);
            color: var(--info-color);
        }
        .role-user {
            background: rgba(108,117,125,0.1);
            color: var(--secondary-color);
        }
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-aktif {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
        }
        .status-tidak-aktif {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
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
            .sidebar.active {
                transform: translateX(0);
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .stats-grid {
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
        <h1><i class="fas fa-user-friends"></i> Master User</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Kelola pengguna sistem dengan role dan hak akses yang berbeda</p>
        <div class="info-tags">
            <div class="info-tag"><i class="fas fa-user"></i> User: <?php echo htmlspecialchars($username); ?></div>
            <div class="info-tag"><i class="fas fa-shield-alt"></i> Role: <?php echo htmlspecialchars(ucfirst($role)); ?></div>
            <div class="info-tag"><i class="fas fa-calendar-day"></i> Tanggal: <?php echo date('d M Y'); ?></div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stats-card">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Total User</h4>
                    <p class="stats-number"><?php echo $total_users; ?></p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>
        <div class="stats-card success">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>User Aktif</h4>
                    <p class="stats-number"><?php echo $active_users; ?></p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-user-check"></i>
                </div>
            </div>
        </div>
        <div class="stats-card warning">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Total Role</h4>
                    <p class="stats-number"><?php echo $total_roles; ?></p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-user-tag"></i>
                </div>
            </div>
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
            <i class="fas fa-<?php echo isset($edit_user) ? 'edit' : 'plus'; ?>"></i> 
            <?php echo isset($edit_user) ? 'Edit User' : 'Tambah User Baru'; ?>
        </h3>
        
        <?php if (isset($edit_user)) { ?>
            <form method="post">
                <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="kode_user" class="form-label">
                            <i class="fas fa-id-card"></i> Kode User <span class="text-required">*</span>
                        </label>
                        <input type="text" 
                               id="kode_user" 
                               name="kode_user" 
                               class="form-control"
                               value="<?php echo htmlspecialchars($edit_user['kode_user']); ?>" 
                               required
                               oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group">
                        <label for="kode_karyawan" class="form-label">
                            <i class="fas fa-user"></i> Karyawan
                        </label>
                        <input type="text" 
                               id="kode_karyawan" 
                               name="kode_karyawan" 
                               class="form-control"
                               value="<?php echo htmlspecialchars($edit_user['kode_karyawan'] . ' - ' . $edit_user['nama_karyawan']); ?>" 
                               disabled>
                    </div>

                    <div class="form-group">
                        <label for="kode_cabang" class="form-label">
                            <i class="fas fa-building"></i> Cabang <span class="text-required">*</span>
                        </label>
                        <select id="kode_cabang" name="kode_cabang" class="form-control" required>
                            <option value="">-- Pilih Cabang --</option>
                            <?php 
                            // Reset result pointer for reuse
                            $result_cabang_options = $conn->query("SELECT DISTINCT kode_cabang, nama_cabang FROM masterkeys ORDER BY nama_cabang");
                            while ($cabang = $result_cabang_options->fetch_assoc()) { 
                                $combined_value = $cabang['kode_cabang'] . ' - ' . $cabang['nama_cabang'];
                                $is_selected = ($edit_user['kode_cabang'] . ' - ' . $edit_user['nama_cabang'] == $combined_value) ? 'selected' : '';
                            ?>
                                <option value="<?php echo $combined_value; ?>" <?php echo $is_selected; ?>>
                                    <?php echo $combined_value; ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="role" class="form-label">
                            <i class="fas fa-user-tag"></i> Role <span class="text-required">*</span>
                        </label>
                        <select name="role" id="role" class="form-control" required>
                            <option value="admin" <?php if (strtolower($edit_user['role']) == 'admin') echo 'selected'; ?>>Admin</option>
                            <option value="super_admin" <?php if (strtolower($edit_user['role']) == 'super_admin') echo 'selected'; ?>>Super Admin</option>
                            <option value="kasir" <?php if (strtolower($edit_user['role']) == 'kasir') echo 'selected'; ?>>Kasir</option>
                            <option value="user" <?php if (strtolower($edit_user['role']) == 'user') echo 'selected'; ?>>User</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status" class="form-label">
                            <i class="fas fa-toggle-on"></i> Status <span class="text-required">*</span>
                        </label>
                        <select name="status" id="status" class="form-control" required>
                            <option value="1" <?php if ($edit_user['status'] == 1) echo 'selected'; ?>>Aktif</option>
                            <option value="0" <?php if ($edit_user['status'] == 0) echo 'selected'; ?>>Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="edit_user" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update User
                    </button>
                    <a href="users.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Batal
                    </a>
                </div>
            </form>
        <?php } else { ?>
            <form method="post">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="kode_user" class="form-label">
                            <i class="fas fa-id-card"></i> Kode User <span class="text-required">*</span>
                        </label>
                        <input type="text" 
                               id="kode_user" 
                               name="kode_user" 
                               class="form-control"
                               value="<?php echo generateKodeUser($conn); ?>" 
                               required
                               oninput="this.value = this.value.toUpperCase()">
                    </div>

                    <div class="form-group">
                        <label for="kode_karyawan" class="form-label">
                            <i class="fas fa-user"></i> Karyawan <span class="text-required">*</span>
                        </label>
                        <select id="kode_karyawan" name="kode_karyawan" class="form-control" required>
                            <option value="">-- Pilih Karyawan --</option>
                            <?php while ($employee = $result_available_employees->fetch_assoc()) { ?>
                                <option value="<?php echo $employee['kode_karyawan'] . ' - ' . $employee['nama_karyawan']; ?>">
                                    <?php echo $employee['kode_karyawan'] . " - " . $employee['nama_karyawan']; ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="kode_cabang" class="form-label">
                            <i class="fas fa-building"></i> Cabang <span class="text-required">*</span>
                        </label>
                        <select id="kode_cabang" name="kode_cabang" class="form-control" required>
                            <option value="">-- Pilih Cabang --</option>
                            <?php 
                            // Reset result pointer for reuse
                            $result_cabang_options = $conn->query("SELECT DISTINCT kode_cabang, nama_cabang FROM masterkeys ORDER BY nama_cabang");
                            while ($cabang = $result_cabang_options->fetch_assoc()) { ?>
                                <option value="<?php echo $cabang['kode_cabang'] . ' - ' . $cabang['nama_cabang']; ?>">
                                    <?php echo $cabang['kode_cabang'] . " - " . $cabang['nama_cabang']; ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="role" class="form-label">
                            <i class="fas fa-user-tag"></i> Role <span class="text-required">*</span>
                        </label>
                        <select name="role" id="role" class="form-control" required>
                            <option value="">-- Pilih Role --</option>
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                            <option value="kasir">Kasir</option>
                            <option value="user">User</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status" class="form-label">
                            <i class="fas fa-toggle-on"></i> Status <span class="text-required">*</span>
                        </label>
                        <select name="status" id="status" class="form-control" required>
                            <option value="1">Aktif</option>
                            <option value="0">Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <button type="submit" name="create_user" class="btn btn-success">
                    <i class="fas fa-plus"></i> Tambah User
                </button>
            </form>
        <?php } ?>
    </div>

    <!-- Table Card -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Daftar User</h3>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode User</th>
                        <th>Kode Karyawan</th>
                        <th>Nama Karyawan</th>
                        <th>Role</th>
                        <th>Cabang</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if ($result_users->num_rows > 0) {
                        $no = 1;
                        // Reset result pointer
                        $result_users = $conn->query("SELECT * FROM users ORDER BY kode_user ASC");
                        while ($user = $result_users->fetch_assoc()) { 
                            $role_class = strtolower(str_replace('_', '_', $user['role']));
                            $status_text = ($user['status'] == 1) ? 'Aktif' : 'Tidak Aktif';
                            $status_class = ($user['status'] == 1) ? 'aktif' : 'tidak-aktif';
                    ?>
                        <tr>
                            <td><strong><?php echo $no; ?></strong></td>
                            <td><code><?php echo htmlspecialchars($user['kode_user']); ?></code></td>
                            <td><code><?php echo htmlspecialchars($user['kode_karyawan']); ?></code></td>
                            <td><?php echo htmlspecialchars($user['nama_karyawan'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="role-badge role-<?php echo $role_class; ?>">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $user['role']))); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($user['nama_cabang'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $status_class; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                            <td class="action-buttons">
                                <a href="users.php?edit=<?php echo $user['id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="users.php?delete=<?php echo $user['id']; ?>" 
                                   class="btn btn-danger btn-sm" 
                                   title="Hapus"
                                   onclick="return confirm('Yakin ingin menghapus user <?php echo $user['kode_user']; ?> (<?php echo $user['nama_karyawan']; ?>)?');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php 
                            $no++;
                        } 
                    } else { 
                    ?>
                        <tr>
                            <td colspan="8" class="no-data">
                                <i class="fas fa-users"></i><br>
                                Belum ada user yang terdaftar
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
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

        const minWidth = 280;
        sidebar.style.width = maxWidth > minWidth ? `${maxWidth + 40}px` : `${minWidth}px`;
        document.querySelector('.main-content').style.marginLeft = sidebar.style.width;
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