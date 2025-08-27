<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Pastikan pengguna telah login dan memiliki peran yang sesuai
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

// Koneksi ke database
$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Include session helper
include_once 'session_helper.php';

$kode_karyawan = $_SESSION['kode_karyawan'];

// PERBAIKAN: Setup dan validasi session dengan helper
$branch_data = setupValidatedSession($pdo, $kode_karyawan);
$kode_cabang = $branch_data['kode_cabang'];
$nama_cabang = $branch_data['nama_cabang'];

// Log untuk debugging
logBranchData($kode_karyawan, 'verifikasi_kas_awal_access', $branch_data);

$tanggal_hari_ini = date('Y-m-d');

// Cek apakah sudah ada transaksi pada tanggal yang sama dan cabang yang sama
$sql_check = "SELECT DISTINCT tanggal_transaksi FROM kasir_transactions 
              WHERE kode_karyawan = :kode_karyawan AND kode_cabang = :kode_cabang
              ORDER BY tanggal_transaksi DESC";
$stmt_check = $pdo->prepare($sql_check);
$stmt_check->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_check->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
$stmt_check->execute();
$tanggal_transaksi_exist = $stmt_check->fetchAll(PDO::FETCH_COLUMN);

// Jika form dikirim
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['selected_date'])) {
    $selected_date = $_POST['selected_date'];

    // Validasi tanggal tidak boleh masa lampau
    if ($selected_date < $tanggal_hari_ini) {
        $_SESSION['transaksi_error'] = "Tidak dapat memilih tanggal yang sudah lewat.";
        header("Location: verifikasi_kas_awal.php");
        exit;
    }

    // Jika tanggal yang dipilih sudah ada untuk cabang yang sama, berikan pesan kesalahan
    if (in_array($selected_date, $tanggal_transaksi_exist)) {
        $_SESSION['transaksi_error'] = "Tanggal yang Anda pilih sudah memiliki kas awal untuk cabang ini. Silakan pilih tanggal lain.";
        header("Location: verifikasi_kas_awal.php");
        exit;
    } else {
        // PERBAIKAN: Validasi ulang data cabang sebelum menyimpan
        $current_branch = validateAndGetBranchData($pdo, $kode_karyawan);
        if (!$current_branch['is_valid']) {
            $_SESSION['transaksi_error'] = "Data cabang tidak valid. Silakan login ulang.";
            header("Location: verifikasi_kas_awal.php");
            exit;
        }

        // Simpan tanggal yang dipilih ke session dan arahkan ke halaman kasir_dashboard_baru.php
        $_SESSION['selected_date'] = $selected_date;
        $_SESSION['validated_branch'] = [
            'kode_cabang' => $current_branch['kode_cabang'],
            'nama_cabang' => $current_branch['nama_cabang'],
            'validation_time' => time()
        ];
        
        header("Location: kasir_dashboard_baru.php");
        exit;
    }
}

$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$cabang = $nama_cabang ?? 'Unknown Cabang';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Kas Awal</title>
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
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
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
        .alert-warning {
            background-color: #fff3cd;
            border-color: #ffeaa7;
            color: #856404;
        }
        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }
        .form-group {
            margin-bottom: 24px;
        }
        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-dark);
            font-size: 14px;
        }
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: white;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            justify-content: center;
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
        .instruction-box {
            background: var(--background-light);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            border-left: 4px solid var(--primary-color);
        }
        .instruction-box p {
            color: var(--text-muted);
            margin: 0;
            font-size: 14px;
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
        .branch-info {
            background: linear-gradient(135deg, var(--primary-color), #0056b3);
            color: white;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            text-align: center;
        }
        .branch-info h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
        }
        .branch-info p {
            margin: 0;
            opacity: 0.9;
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
                <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($cabang); ?>)
                <p style="color: var(--text-muted); font-size: 12px;">Kasir</p>
            </div>
        </div>

        <div class="breadcrumb">
            <a href="index_kasir.php"><i class="fas fa-home"></i> Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <span>Verifikasi Kas Awal</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-check-circle"></i> Verifikasi Kas Awal</h1>
            <p class="subtitle">Pilih tanggal untuk memulai transaksi kas awal baru</p>
        </div>

        <!-- Branch Information -->
        <div class="branch-info">
            <h3><i class="fas fa-building"></i> Informasi Cabang</h3>
            <p><?php echo htmlspecialchars($kode_cabang); ?> - <?php echo htmlspecialchars($nama_cabang); ?></p>
        </div>

        <div class="container">
            <?php if (isset($_SESSION['transaksi_error'])): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo $_SESSION['transaksi_error']; ?>
                </div>
                <?php unset($_SESSION['transaksi_error']); ?>
            <?php endif; ?>

            <div class="instruction-box">
                <p><i class="fas fa-info-circle"></i> Anda tidak dapat memilih tanggal yang sudah memiliki transaksi di cabang yang sama.</p>
            </div>

            <?php if (count($tanggal_transaksi_exist) > 0): ?>
                <div class="alert alert-info">
                    <i class="fas fa-calendar-times"></i>
                    <div>
                        <strong>Tanggal yang tidak tersedia:</strong><br>
                        <?php echo implode(', ', array_map(function($date) { return date('d/m/Y', strtotime($date)); }, $tanggal_transaksi_exist)); ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" id="dateForm">
                <div class="form-group">
                    <label for="selected_date" class="form-label">
                        <i class="fas fa-calendar-alt"></i> Pilih Tanggal Transaksi
                    </label>
                    <input type="date" 
                           id="selected_date" 
                           name="selected_date" 
                           class="form-control" 
                           min="<?php echo date('Y-m-d'); ?>" 
                           required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-arrow-right"></i> Lanjutkan
                </button>
            </form>
        </div>
    </div>

    <script>
        // Ambil tanggal yang sudah ada transaksinya dari PHP
        var disabledDates = <?php echo json_encode($tanggal_transaksi_exist); ?>;
        
        // Ambil input date
        var dateInput = document.getElementById('selected_date');
        var form = document.getElementById('dateForm');

        // Tambahkan event listener saat user memilih tanggal
        dateInput.addEventListener('input', function() {
            var selectedDate = this.value;
            var today = new Date().toISOString().split('T')[0];

            // Cek apakah tanggal masa lampau
            if (selectedDate < today) {
                alert('Tidak dapat memilih tanggal yang sudah lewat.');
                this.value = '';
                return;
            }

            // Cek apakah tanggal yang dipilih sudah ada transaksinya di cabang yang sama
            if (disabledDates.includes(selectedDate)) {
                alert('Tanggal yang Anda pilih sudah memiliki kas awal untuk cabang ini. Silakan pilih tanggal lain.');
                this.value = '';
            }
        });

        // Validasi sebelum submit
        form.addEventListener('submit', function(e) {
            var selectedDate = dateInput.value;
            var today = new Date().toISOString().split('T')[0];

            if (!selectedDate) {
                e.preventDefault();
                alert('Silakan pilih tanggal terlebih dahulu.');
                return;
            }

            if (selectedDate < today) {
                e.preventDefault();
                alert('Tidak dapat memilih tanggal yang sudah lewat.');
                dateInput.value = '';
                return;
            }

            if (disabledDates.includes(selectedDate)) {
                e.preventDefault();
                alert('Tanggal yang Anda pilih sudah memiliki kas awal untuk cabang ini. Silakan pilih tanggal lain.');
                dateInput.value = '';
                return;
            }

            // Konfirmasi sebelum melanjutkan
            if (!confirm('Anda yakin ingin membuat kas awal untuk tanggal ' + selectedDate + '?')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>