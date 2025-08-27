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
$kode_karyawan_session = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User';

// Database connection
$conn = new mysqli('localhost', 'fitmotor_LOGIN', 'Sayalupa12', 'fitmotor_maintance-beta');
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Get employee ID from URL parameter
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: view_employees.php');
    exit;
}

$kode_karyawan = $conn->real_escape_string($_GET['id']);

// Get employee data from database
$result = $conn->query("SELECT mk.*, c.nama_cabang FROM masterkeys mk LEFT JOIN cabang c ON mk.kode_cabang = c.kode_cabang WHERE mk.kode_karyawan = '$kode_karyawan'");

if ($result->num_rows == 0) {
    header('Location: view_employees.php');
    exit;
}

$employee = $result->fetch_assoc();

// Get all branches for dropdown
$cabang_result = $conn->query("SELECT kode_cabang, nama_cabang FROM cabang ORDER BY nama_cabang");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Karyawan - Admin Dashboard</title>
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
            background: var(--background-light);
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
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: none;
            align-items: center;
            gap: 10px;
            border: 1px solid transparent;
        }
        .alert.show {
            display: flex;
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
        .alert-info {
            background: rgba(23,162,184,0.1);
            color: var(--info-color);
            border-color: rgba(23,162,184,0.2);
        }
        .text-required {
            color: var(--danger-color);
        }
        .loading {
            display: none;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
        }
        .loading.show {
            display: flex;
        }
        .loading i {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .form-help {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .employee-info-card {
            background: linear-gradient(135deg, var(--warning-color), #e0a800);
            color: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px rgba(255,193,7,0.1);
        }
        .employee-info-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .employee-info h4 {
            margin: 0 0 5px 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .employee-info .employee-text {
            font-size: 18px;
            font-weight: bold;
            margin: 0;
        }
        .employee-icon {
            font-size: 36px;
            opacity: 0.8;
        }
        .readonly-field {
            background: rgba(23,162,184,0.1);
            color: var(--info-color);
            border: 1px solid rgba(23,162,184,0.2);
            padding: 12px 16px;
            border-radius: 8px;
            font-weight: 500;
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
            .btn {
                width: 100%;
                justify-content: center;
            }
            .employee-info-content {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
        }
    </style>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Handle form submission with confirmation
            $('#editEmployeeForm').on('submit', function(event) {
                event.preventDefault();

                if (!confirm('Apakah Anda yakin ingin mengupdate data karyawan ini?')) {
                    return;
                }

                // Show loading state
                $('#updateBtn').prop('disabled', true);
                $('.loading').addClass('show');
                hideAlert();

                // Retrieve form data
                const formData = $(this).serialize();
                
                // Send the form data using AJAX
                $.ajax({
                    url: 'update_employee.php',
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        $('.loading').removeClass('show');
                        $('#updateBtn').prop('disabled', false);
                        
                        if (response.status === 'success') {
                            showAlert('success', response.message);
                            
                            // Redirect after 2 seconds
                            setTimeout(function() {
                                window.location.href = 'view_employees.php';
                            }, 2000);
                        } else {
                            showAlert('danger', 'Error: ' + response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        $('.loading').removeClass('show');
                        $('#updateBtn').prop('disabled', false);
                        console.log(xhr.responseText);
                        
                        // Try to parse response as plain text for non-JSON responses
                        try {
                            const response = JSON.parse(xhr.responseText);
                            showAlert('danger', 'Error: ' + response.message);
                        } catch(e) {
                            // If it's not JSON, assume it's a redirect (success case for standard PHP)
                            showAlert('success', 'Data karyawan berhasil diupdate!');
                            setTimeout(function() {
                                window.location.href = 'view_employees.php';
                            }, 2000);
                        }
                    }
                });
            });

            // Auto-hide alerts after 5 seconds
            function autoHideAlert() {
                setTimeout(function() {
                    hideAlert();
                }, 5000);
            }

            // Show alert function
            function showAlert(type, message) {
                const alertBox = $('.alert');
                alertBox.removeClass('alert-success alert-danger alert-info');
                alertBox.addClass('alert-' + type);
                alertBox.find('.alert-message').text(message);
                alertBox.addClass('show');
                autoHideAlert();
            }

            // Hide alert function
            function hideAlert() {
                $('.alert').removeClass('show');
            }

            // Make functions globally accessible
            window.showAlert = showAlert;
            window.hideAlert = hideAlert;
        });

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
        <a href="View_employees.php"><i class="fas fa-id-card"></i> View Master Karyawan</a>
        <a href="edit_employee.php" class="active"><i class="fas fa-id-card"></i>Edit Master Karyawan</a>
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
        <h1><i class="fas fa-edit"></i> Edit Data Karyawan</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Ubah informasi karyawan dalam sistem manajemen</p>
        <div class="info-tags">
            <div class="info-tag"><i class="fas fa-user"></i> User: <?php echo htmlspecialchars($username); ?></div>
            <div class="info-tag"><i class="fas fa-shield-alt"></i> Role: <?php echo htmlspecialchars(ucfirst($role)); ?></div>
            <div class="info-tag"><i class="fas fa-calendar-day"></i> Tanggal: <?php echo date('d M Y'); ?></div>
        </div>
    </div>

    <!-- Employee Info Card -->
    <div class="employee-info-card">
        <div class="employee-info-content">
            <div class="employee-info">
                <h4>Data Karyawan</h4>
                <p class="employee-text"><?php echo htmlspecialchars($employee['nama_karyawan']); ?> (<?php echo htmlspecialchars($employee['kode_karyawan']); ?>)</p>
            </div>
            <div class="employee-icon">
                <i class="fas fa-user-edit"></i>
            </div>
        </div>
    </div>

    <!-- Alert -->
    <div class="alert">
        <i class="fas fa-info-circle"></i>
        <span class="alert-message"></span>
    </div>

    <!-- Form Card -->
    <div class="form-card">
        <h3><i class="fas fa-edit"></i> Form Edit Karyawan</h3>
        
        <form id="editEmployeeForm">
            <!-- Hidden field for employee ID -->
            <input type="hidden" name="kode_karyawan" value="<?php echo htmlspecialchars($employee['kode_karyawan']); ?>">
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="kode_karyawan_display" class="form-label">
                        <i class="fas fa-id-badge"></i> Kode Karyawan
                    </label>
                    <div class="readonly-field">
                        <?php echo htmlspecialchars($employee['kode_karyawan']); ?>
                    </div>
                    <div class="form-help">Kode karyawan tidak dapat diubah</div>
                </div>

                <div class="form-group">
                    <label for="nama_karyawan_display" class="form-label">
                        <i class="fas fa-user"></i> Nama Karyawan
                    </label>
                    <div class="readonly-field">
                        <?php echo htmlspecialchars($employee['nama_karyawan']); ?>
                    </div>
                    <div class="form-help">Nama karyawan tidak dapat diubah</div>
                </div>

                <div class="form-group">
                    <label for="entry_year_display" class="form-label">
                        <i class="fas fa-calendar-alt"></i> Tahun Masuk
                    </label>
                    <div class="readonly-field">
                        <?php echo htmlspecialchars($employee['entry_year']); ?>
                    </div>
                    <div class="form-help">Tahun masuk tidak dapat diubah</div>
                </div>

                <div class="form-group">
                    <label for="entry_month_display" class="form-label">
                        <i class="fas fa-calendar"></i> Bulan Masuk
                    </label>
                    <div class="readonly-field">
                        <?php 
                        $monthNames = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
                                      'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                        echo htmlspecialchars($monthNames[$employee['entry_month']] . ' (' . $employee['entry_month'] . ')'); 
                        ?>
                    </div>
                    <div class="form-help">Bulan masuk tidak dapat diubah</div>
                </div>

                <div class="form-group">
                    <label for="kode_cabang" class="form-label">
                        <i class="fas fa-building"></i> Pilih Cabang <span class="text-required">*</span>
                    </label>
                    <select id="kode_cabang" name="kode_cabang" class="form-control" required>
                        <?php
                        if ($cabang_result->num_rows > 0) {
                            while ($cabang_row = $cabang_result->fetch_assoc()) {
                                $selected = ($cabang_row['kode_cabang'] == $employee['kode_cabang']) ? 'selected' : '';
                                echo "<option value='" . htmlspecialchars($cabang_row['kode_cabang']) . "' $selected>";
                                echo htmlspecialchars($cabang_row['nama_cabang']) . " (" . htmlspecialchars($cabang_row['kode_cabang']) . ")";
                                echo "</option>";
                            }
                        } else {
                            echo "<option value=''>Tidak ada cabang tersedia</option>";
                        }
                        ?>
                    </select>
                    <div class="form-help">Pilih cabang tempat karyawan ditempatkan</div>
                </div>

                <div class="form-group">
                    <label for="status_aktif" class="form-label">
                        <i class="fas fa-toggle-on"></i> Status Aktif <span class="text-required">*</span>
                    </label>
                    <select id="status_aktif" name="status_aktif" class="form-control" required>
                        <option value="1" <?php echo ($employee['status_aktif'] == '1') ? 'selected' : ''; ?>>Aktif</option>
                        <option value="0" <?php echo ($employee['status_aktif'] == '0') ? 'selected' : ''; ?>>Tidak Aktif</option>
                    </select>
                    <div class="form-help">Status keaktifan karyawan dalam sistem</div>
                </div>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="submit" id="updateBtn" class="btn btn-success">
                    <i class="fas fa-save"></i> Update Data
                </button>
                <a href="view_employees.php" class="btn btn-info">
                    <i class="fas fa-list"></i> Kembali ke Daftar
                </a>
                <button type="button" class="btn btn-secondary" onclick="resetChanges()">
                    <i class="fas fa-undo"></i> Reset Changes
                </button>
                <div class="loading">
                    <i class="fas fa-spinner"></i>
                    <span>Mengupdate data...</span>
                </div>
            </div>
        </form>
    </div>

    <div class="alert alert-info show">
        <i class="fas fa-info-circle"></i>
        <span class="alert-message">Hanya cabang dan status aktif yang dapat diubah. Data lain bersifat permanen untuk menjaga integritas sistem.</span>
    </div>
</div>

<script>
    function resetChanges() {
        // Reset form to original values
        document.getElementById('kode_cabang').value = '<?php echo htmlspecialchars($employee['kode_cabang']); ?>';
        document.getElementById('status_aktif').value = '<?php echo htmlspecialchars($employee['status_aktif']); ?>';
        
        hideAlert();
        
        // Show confirmation
        showAlert('info', 'Form telah direset ke nilai asli.');
    }
</script>

</body>
</html>

<?php
$conn->close();
?>