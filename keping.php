<?php
// Include config file to establish database connection
include('config.php');

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

// Database connection ke fitmotor_maintance-beta
$host = "localhost"; 
$db_user = "fitmotor_LOGIN";  
$db_password = "Sayalupa12";  
$db_name = "fitmotor_maintance-beta"; 

// Create connection
$conn = mysqli_connect($host, $db_user, $db_password, $db_name);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

$success_message = '';
$error_message = '';

// CREATE Nominal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create'])) {
    $nominal = $_POST['nominal'];
    
    // Check if nominal already exists
    $check_sql = "SELECT * FROM keping WHERE nominal = '$nominal'";
    $check_result = mysqli_query($conn, $check_sql);
    
    if (mysqli_num_rows($check_result) > 0) {
        $error_message = "Nominal Rp " . number_format($nominal, 0, ',', '.') . " sudah ada!";
    } else {
        // Insert the new nominal into the 'keping' table
        $sql = "INSERT INTO keping (nominal) VALUES ('$nominal')";
        if (mysqli_query($conn, $sql)) {
            $success_message = "Nominal Rp " . number_format($nominal, 0, ',', '.') . " berhasil ditambahkan!";
            header("Refresh: 2; URL=keping.php");
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    }
}

// UPDATE Nominal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update'])) {
    $id = $_POST['id'];
    $nominal = $_POST['nominal'];
    
    // Check if nominal already exists (excluding current record)
    $check_sql = "SELECT * FROM keping WHERE nominal = '$nominal' AND id != $id";
    $check_result = mysqli_query($conn, $check_sql);
    
    if (mysqli_num_rows($check_result) > 0) {
        $error_message = "Nominal Rp " . number_format($nominal, 0, ',', '.') . " sudah ada!";
    } else {
        // Update the selected nominal
        $sql = "UPDATE keping SET nominal = '$nominal' WHERE id = $id";
        if (mysqli_query($conn, $sql)) {
            $success_message = "Nominal berhasil diupdate!";
            header("Refresh: 2; URL=keping.php");
        } else {
            $error_message = "Error: " . mysqli_error($conn);
        }
    }
}

// DELETE Nominal
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Get nominal value for confirmation message
    $get_nominal = "SELECT nominal FROM keping WHERE id = $id";
    $get_result = mysqli_query($conn, $get_nominal);
    $nominal_data = mysqli_fetch_assoc($get_result);
    
    $sql = "DELETE FROM keping WHERE id = $id";
    
    if (mysqli_query($conn, $sql)) {
        $success_message = "Nominal Rp " . number_format($nominal_data['nominal'], 0, ',', '.') . " berhasil dihapus!";
        header("Refresh: 2; URL=keping.php");
    } else {
        $error_message = "Error: " . mysqli_error($conn);
    }
}

// FETCH ALL DATA
$sql = "SELECT * FROM keping ORDER BY nominal ASC";
$result = mysqli_query($conn, $sql);

// FETCH ONE DATA FOR EDIT
$edit = false;
if (isset($_GET['edit'])) {
    $edit = true;
    $id = $_GET['edit'];
    $sql = "SELECT * FROM keping WHERE id = $id";
    $edit_result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($edit_result);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Nominal - Admin Dashboard</title>
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
        .nominal-display {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: var(--success-color);
        }
        .stats-card {
            background: linear-gradient(135deg, var(--primary-color), #0056b3);
            color: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px rgba(0,123,255,0.1);
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
        .stats-info .stats-number {
            font-size: 24px;
            font-weight: bold;
            margin: 0;
        }
        .stats-icon {
            font-size: 36px;
            opacity: 0.8;
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
            .stats-content {
                flex-direction: column;
                text-align: center;
                gap: 10px;
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
        <h1><i class="fas fa-coins"></i> Master Nominal (Keping)</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Kelola denominasi nominal uang untuk sistem transaksi kasir</p>
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
            <?php echo $edit ? 'Edit Nominal' : 'Tambah Nominal Baru'; ?>
        </h3>
        <form action="" method="POST">
            <input type="hidden" name="id" value="<?php echo $edit ? $row['id'] : ''; ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="nominal" class="form-label">
                        <i class="fas fa-money-bill-wave"></i> Nominal (Rp) <span class="text-required">*</span>
                    </label>
                    <input type="number" 
                           id="nominal" 
                           name="nominal" 
                           class="form-control" 
                           value="<?php echo $edit ? $row['nominal'] : ''; ?>" 
                           min="0" 
                           step="1" 
                           required 
                           placeholder="Masukkan nominal"
                           onkeydown="return event.key !== '.' && event.key !== '-' && event.key !== ','"
                           oninput="formatPreview(this)">
                    <div style="margin-top: 8px; font-size: 12px; color: var(--text-muted);">
                        <span id="preview-text">Preview: Rp 0</span>
                    </div>
                </div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="submit" name="<?php echo $edit ? 'update' : 'create'; ?>" class="btn btn-<?php echo $edit ? 'primary' : 'success'; ?>">
                    <i class="fas fa-save"></i> <?php echo $edit ? 'Update Nominal' : 'Tambah Nominal'; ?>
                </button>
                <?php if ($edit): ?>
                    <a href="keping.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Batal
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Table Card -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Daftar Nominal (Keping)</h3>
        </div>
        <table class="table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Nominal</th>
                    <th>Format Rupiah</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (mysqli_num_rows($result) > 0) {
                    $no = 1;
                    // Reset result pointer
                    mysqli_data_seek($result, 0);
                    while ($row = mysqli_fetch_assoc($result)) {
                        echo "<tr>";
                        echo "<td><strong>$no</strong></td>";
                        echo "<td class='nominal-display'>" . $row['nominal'] . "</td>";
                        echo "<td>Rp " . number_format($row['nominal'], 0, ',', '.') . "</td>";
                        echo "<td class='action-buttons'>
                            <a href='keping.php?edit=" . $row['id'] . "' class='btn btn-warning btn-sm' title='Edit'>
                                <i class='fas fa-edit'></i>
                            </a>
                            <a href='keping.php?delete=" . $row['id'] . "' class='btn btn-danger btn-sm' title='Hapus'
                               onclick=\"return confirm('Yakin ingin menghapus nominal Rp " . number_format($row['nominal'], 0, ',', '.') . "?');\">
                                <i class='fas fa-trash'></i>
                            </a>
                        </td>";
                        echo "</tr>";
                        $no++;
                    }
                } else {
                    echo "<tr><td colspan='4' class='no-data'><i class='fas fa-inbox'></i><br>Belum ada nominal yang ditambahkan</td></tr>";
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

    // Format number preview
    function formatPreview(input) {
        const value = input.value;
        const previewText = document.getElementById('preview-text');
        
        if (value === '' || value === '0') {
            previewText.textContent = 'Preview: Rp 0';
            previewText.style.color = 'var(--text-muted)';
        } else {
            const formatted = new Intl.NumberFormat('id-ID').format(value);
            previewText.textContent = `Preview: Rp ${formatted}`;
            previewText.style.color = 'var(--success-color)';
            previewText.style.fontWeight = 'bold';
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

        // Initialize preview for edit mode
        const nominalInput = document.getElementById('nominal');
        if (nominalInput.value) {
            formatPreview(nominalInput);
        }
    });

    // Prevent invalid characters in number input
    document.getElementById('nominal').addEventListener('keypress', function(e) {
        // Allow: backspace, delete, tab, escape, enter
        if ([46, 8, 9, 27, 13].indexOf(e.keyCode) !== -1 ||
            // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
            (e.keyCode === 65 && e.ctrlKey === true) ||
            (e.keyCode === 67 && e.ctrlKey === true) ||
            (e.keyCode === 86 && e.ctrlKey === true) ||
            (e.keyCode === 88 && e.ctrlKey === true)) {
            return;
        }
        // Ensure that it is a number and stop the keypress
        if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
            e.preventDefault();
        }
    });
</script>

</body>
</html>