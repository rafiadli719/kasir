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
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    die("Akses ditolak. Hanya untuk admin dan super_admin.");
}

$is_super_admin = $_SESSION['role'] === 'super_admin';
$is_admin = $_SESSION['role'] === 'admin';
// Ubah bagian ini yang sudah ada
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User'; // Tambahkan ini


// Define role-based flags for sidebar display
$role_session = $_SESSION['role'];
$is_super_admin = ($role_session === 'super_admin');
$is_admin = ($role_session === 'admin');
$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User';

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Karyawan - Admin Dashboard</title>
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
        @media (max-width: 768px) {
            .sidebar.active {
                transform: translateX(0);
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
            .stats-content {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
        }
    </style>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Set current year and month in dropdowns
            const currentYear = new Date().getFullYear();
            const currentMonth = new Date().getMonth() + 1;

            // Populate year dropdown
            for (let i = 2000; i <= currentYear + 1; i++) {
                $('#entry_year').append(new Option(i, i));
            }
            $('#entry_year').val(currentYear);

            // Populate month dropdown
            const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
                               'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            for (let i = 1; i <= 12; i++) {
                $('#entry_month').append(new Option(monthNames[i-1] + ' (' + i + ')', i));
            }
            $('#entry_month').val(currentMonth);

            // Handle form submission with confirmation
            $('#employeeForm').on('submit', function(event) {
                event.preventDefault(); // Prevent the default form submission

                // Show loading state
                $('#submitBtn').prop('disabled', true);
                $('.loading').addClass('show');
                hideAlert();

                // Retrieve form data
                const formData = $(this).serialize(); // Serialize the form data
                
                // Send the form data using AJAX
                $.ajax({
                    url: 'store_masterkey.php', // The URL of the PHP script that processes the form data
                    type: 'POST',
                    data: formData,
                    dataType: 'json', // Expecting a JSON response from the server
                    success: function(response) {
                        $('.loading').removeClass('show');
                        $('#submitBtn').prop('disabled', false);
                        
                        if (response.status === 'success') {
                            showAlert('success', response.message);
                            $('#employeeForm')[0].reset(); // Reset form
                            
                            // Reset dropdowns to current values
                            $('#entry_year').val(currentYear);
                            $('#entry_month').val(currentMonth);
                            
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
                        $('#submitBtn').prop('disabled', false);
                        console.log(xhr.responseText); // For debugging
                        showAlert('danger', 'Gagal mengirim data. Silakan coba lagi.');
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

            // Make showAlert and hideAlert globally accessible
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
        <h1><i class="fas fa-id-card"></i> Master Karyawan</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Form input data karyawan baru untuk sistem manajemen</p>
        <div class="info-tags">
            <div class="info-tag"><i class="fas fa-user"></i> User: <?php echo htmlspecialchars($username); ?></div>
            <div class="info-tag"><i class="fas fa-shield-alt"></i> Role: <?php echo htmlspecialchars(ucfirst($role)); ?></div>
            <div class="info-tag"><i class="fas fa-calendar-day"></i> Tanggal: <?php echo date('d M Y'); ?></div>
        </div>
    </div>

    <!-- Info Card -->
    <div class="stats-card">
        <div class="stats-content">
            <div class="stats-info">
                <h4>Form Input Karyawan Baru</h4>
                <p class="stats-text">Isi data karyawan dengan lengkap dan benar</p>
            </div>
            <div class="stats-icon">
                <i class="fas fa-user-plus"></i>
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
        <h3><i class="fas fa-plus"></i> Form Input Karyawan</h3>
        
        <form id="employeeForm">
            <div class="form-grid">
                <div class="form-group">
                    <label for="nama_karyawan" class="form-label">
                        <i class="fas fa-user"></i> Nama Karyawan <span class="text-required">*</span>
                    </label>
                    <input type="text" 
                           id="nama_karyawan" 
                           name="nama_karyawan" 
                           class="form-control"
                           placeholder="Masukkan nama lengkap karyawan"
                           oninput="this.value = this.value.toUpperCase();" 
                           required>
                    <div class="form-help">Nama akan otomatis diubah ke huruf kapital</div>
                </div>

                <div class="form-group">
                    <label for="entry_year" class="form-label">
                        <i class="fas fa-calendar-alt"></i> Tahun Masuk <span class="text-required">*</span>
                    </label>
                    <select id="entry_year" name="entry_year" class="form-control" required>
                        <option value="">-- Pilih Tahun --</option>
                    </select>
                    <div class="form-help">Tahun karyawan mulai bekerja</div>
                </div>

                <div class="form-group">
                    <label for="entry_month" class="form-label">
                        <i class="fas fa-calendar"></i> Bulan Masuk <span class="text-required">*</span>
                    </label>
                    <select id="entry_month" name="entry_month" class="form-control" required>
                        <option value="">-- Pilih Bulan --</option>
                    </select>
                    <div class="form-help">Bulan karyawan mulai bekerja</div>
                </div>

                <div class="form-group">
                    <label for="branch" class="form-label">
                        <i class="fas fa-building"></i> Pilih Cabang <span class="text-required">*</span>
                    </label>
                    <select id="branch" name="kode_cabang" class="form-control" required>
                        <option value="">-- Pilih Cabang --</option>
                        <?php
                            // Database connection and query
                            $mysqli = new mysqli('localhost', 'fitmotor_LOGIN', 'Sayalupa12', 'fitmotor_maintance-beta');
                            if ($mysqli->connect_error) {
                                die("Connection failed: " . $mysqli->connect_error);
                            }
                            $query = "SELECT kode_cabang, nama_cabang FROM cabang ORDER BY nama_cabang";
                            $result = $mysqli->query($query);
                            if ($result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    echo "<option value='" . $row['kode_cabang'] . "'>" . htmlspecialchars($row['nama_cabang']) . " (" . $row['kode_cabang'] . ")</option>";
                                }
                            } else {
                                echo "<option value=''>Tidak ada cabang tersedia</option>";
                            }
                            $mysqli->close();
                        ?>
                    </select>
                    <div class="form-help">Cabang tempat karyawan akan ditempatkan</div>
                </div>

                <div class="form-group">
                    <label for="status_aktif" class="form-label">
                        <i class="fas fa-toggle-on"></i> Status Aktif <span class="text-required">*</span>
                    </label>
                    <select id="status_aktif" name="status_aktif" class="form-control" required>
                        <option value="1">Aktif</option>
                        <option value="0">Tidak Aktif</option>
                    </select>
                    <div class="form-help">Status keaktifan karyawan dalam sistem</div>
                </div>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="submit" id="submitBtn" class="btn btn-success">
                    <i class="fas fa-save"></i> Simpan Data
                </button>
                <button type="button" class="btn btn-info" onclick="window.location.href='view_employees.php'">
                    <i class="fas fa-list"></i> Lihat Data
                </button>
                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                    <i class="fas fa-refresh"></i> Reset Form
                </button>
                <div class="loading">
                    <i class="fas fa-spinner"></i>
                    <span>Menyimpan data...</span>
                </div>
            </div>
        </form>
    </div>

    <div class="alert alert-info show">
        <i class="fas fa-info-circle"></i>
        <span class="alert-message">Pastikan semua data yang diinput sudah benar sebelum menyimpan. Data karyawan akan digunakan untuk pembuatan akun user.</span>
    </div>
</div>

<script>
    function resetForm() {
        document.getElementById('employeeForm').reset();
        
        // Reset dropdowns to current values
        const currentYear = new Date().getFullYear();
        const currentMonth = new Date().getMonth() + 1;
        
        $('#entry_year').val(currentYear);
        $('#entry_month').val(currentMonth);
        
        hideAlert();
    }
</script>

</body>
</html>