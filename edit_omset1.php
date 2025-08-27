<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'config.php'; // Koneksi ke database

// Pastikan user sudah login dan memiliki peran 'kasir'
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
header('Location: ../../login_dashboard/login.php');
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Set timezone ke Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

$kode_karyawan = $_SESSION['kode_karyawan']; // Kode Karyawan dari sesi

// Ambil kode_transaksi dari URL
if (!isset($_GET['kode_transaksi'])) {
    die("Kode transaksi tidak ditemukan.");
}

$kode_transaksi = $_GET['kode_transaksi'];

// Ambil data penjualan dan servis berdasarkan kode_transaksi
$sql = "
    SELECT 
        (SELECT SUM(jumlah_penjualan) FROM data_penjualan WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan) AS total_penjualan, 
        (SELECT SUM(jumlah_servis) FROM data_servis WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan) AS total_servis
";
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt->execute();
$omset_data = $stmt->fetch(PDO::FETCH_ASSOC);

$total_penjualan = $omset_data['total_penjualan'] ?? 0;
$total_servis = $omset_data['total_servis'] ?? 0;

// Cek apakah ini request AJAX untuk update omset
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $jumlah_penjualan = $_POST['jumlah_penjualan'];
    $jumlah_servis = $_POST['jumlah_servis'];

    try {
        $pdo->beginTransaction();

        // Set tanggal dan waktu otomatis
        $tanggal = date('Y-m-d'); // Tanggal saat ini
        $waktu = date('H:i:s');   // Waktu saat ini

        // Cek apakah data penjualan sudah ada
        $sql_check_penjualan = "SELECT id FROM data_penjualan WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan";
        $stmt_check_penjualan = $pdo->prepare($sql_check_penjualan);
        $stmt_check_penjualan->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
        $stmt_check_penjualan->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
        $stmt_check_penjualan->execute();
        $penjualan_exists = $stmt_check_penjualan->fetch(PDO::FETCH_ASSOC);

        if ($penjualan_exists) {
            // Update data penjualan
            $sql_penjualan = "
                UPDATE data_penjualan 
                SET jumlah_penjualan = :jumlah_penjualan, tanggal = :tanggal, waktu = :waktu
                WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan";
            $stmt_penjualan = $pdo->prepare($sql_penjualan);
        } else {
            // Insert data penjualan baru
            $sql_penjualan = "
                INSERT INTO data_penjualan (kode_transaksi, kode_karyawan, jumlah_penjualan, tanggal, waktu) 
                VALUES (:kode_transaksi, :kode_karyawan, :jumlah_penjualan, :tanggal, :waktu)";
            $stmt_penjualan = $pdo->prepare($sql_penjualan);
        }
        $stmt_penjualan->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
        $stmt_penjualan->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
        $stmt_penjualan->bindParam(':jumlah_penjualan', $jumlah_penjualan, PDO::PARAM_STR);
        $stmt_penjualan->bindParam(':tanggal', $tanggal, PDO::PARAM_STR);
        $stmt_penjualan->bindParam(':waktu', $waktu, PDO::PARAM_STR);
        $stmt_penjualan->execute();

        // Cek apakah data servis sudah ada
        $sql_check_servis = "SELECT id FROM data_servis WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan";
        $stmt_check_servis = $pdo->prepare($sql_check_servis);
        $stmt_check_servis->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
        $stmt_check_servis->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
        $stmt_check_servis->execute();
        $servis_exists = $stmt_check_servis->fetch(PDO::FETCH_ASSOC);

        if ($servis_exists) {
            // Update data servis
            $sql_servis = "
                UPDATE data_servis 
                SET jumlah_servis = :jumlah_servis, tanggal = :tanggal, waktu = :waktu
                WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan";
            $stmt_servis = $pdo->prepare($sql_servis);
        } else {
            // Insert data servis baru
            $sql_servis = "
                INSERT INTO data_servis (kode_transaksi, kode_karyawan, jumlah_servis, tanggal, waktu) 
                VALUES (:kode_transaksi, :kode_karyawan, :jumlah_servis, :tanggal, :waktu)";
            $stmt_servis = $pdo->prepare($sql_servis);
        }
        $stmt_servis->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
        $stmt_servis->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
        $stmt_servis->bindParam(':jumlah_servis', $jumlah_servis, PDO::PARAM_STR);
        $stmt_servis->bindParam(':tanggal', $tanggal, PDO::PARAM_STR);
        $stmt_servis->bindParam(':waktu', $waktu, PDO::PARAM_STR);
        $stmt_servis->execute();

        // Commit transaksi
        $pdo->commit();

        // Kembalikan JSON sukses ke AJAX
        echo json_encode(['success' => true]);
        exit;

    } catch (Exception $e) {
        // Rollback jika terjadi kesalahan
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

$total_omset = $total_penjualan + $total_servis; // Hitung total omset

// Ambil nama karyawan dan nama cabang berdasarkan kode_karyawan dari tabel users
$sql_nama_karyawan = "SELECT nama_karyawan, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan";
$stmt_nama_karyawan = $pdo->prepare($sql_nama_karyawan);
$stmt_nama_karyawan->bindParam(':kode_karyawan', $kode_karyawan);
$stmt_nama_karyawan->execute();
$nama_karyawan_data = $stmt_nama_karyawan->fetch(PDO::FETCH_ASSOC);

$nama_karyawan = $nama_karyawan_data['nama_karyawan'] ?? 'Tidak diketahui';
$nama_cabang = $nama_karyawan_data['nama_cabang'] ?? 'Tidak diketahui';
// Gabungkan kode_karyawan dan nama_karyawan
$karyawan_info = $kode_karyawan . ' - ' . ($nama_karyawan ?? 'Tidak diketahui');

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Omset</title>
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
            z-index: 1000;
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
        .sidebar a.active {
            background: var(--primary-color);
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
            margin-left: 200px;
            padding: 30px;
            flex: 1;
            transition: margin-left 0.3s ease;
        }
        .page-header {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
        }
        .page-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 16px;
        }
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 14px;
        }
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .info-card .label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-card .value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-dark);
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            background: var(--background-light);
            border-radius: 12px 12px 0 0;
        }
        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
            margin: 0;
        }
        .card-body {
            padding: 24px;
        }
        .form-group {
            margin-bottom: 20px;
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
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
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
            justify-content: center;
        }
        .btn-primary {
            background: var(--primary-color);
            color: white;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.2);
        }
        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.3);
        }
        .btn-secondary {
            background: var(--secondary-color);
            color: white;
            box-shadow: 0 2px 4px rgba(108, 117, 125, 0.2);
        }
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.3);
        }
        .btn-block {
            width: 100%;
        }
        .summary-card {
            background: linear-gradient(135deg, var(--primary-color), #0056b3);
            color: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            text-align: center;
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.3);
        }
        .summary-card h3 {
            font-size: 24px;
            margin-bottom: 8px;
        }
        .summary-card p {
            font-size: 14px;
            opacity: 0.9;
        }
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
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
            font-weight: 600;
        }
        .table tbody tr:hover {
            background: var(--background-light);
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
            .info-grid {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .table {
                font-size: 12px;
            }
            .table th,
            .table td {
                padding: 8px;
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
        <div class="page-header">
            <h1><i class="fas fa-chart-line"></i> Edit Omset</h1>
            <div class="breadcrumb">
                <a href="index_kasir.php">Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span>Edit Omset</span>
            </div>
        </div>

        <!-- Info Cards -->
        <div class="info-grid">
            <div class="info-card">
                <div class="label">Kode Transaksi</div>
                <div class="value"><?php echo htmlspecialchars($kode_transaksi); ?></div>
            </div>
            <div class="info-card">
                <div class="label">Karyawan</div>
                <div class="value"><?php echo htmlspecialchars($karyawan_info); ?></div>
            </div>
            <div class="info-card">
                <div class="label">Cabang</div>
                <div class="value"><?php echo htmlspecialchars($nama_cabang); ?></div>
            </div>
        </div>

        <!-- Summary Card -->
        <div class="summary-card">
            <h3 id="total-omset-display">
                <i class="fas fa-coins"></i> Rp <?php echo number_format($total_omset, 0, ',', '.'); ?>
            </h3>
            <p>Total Omset Saat Ini</p>
        </div>

        <!-- Form Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-edit"></i> Form Edit Omset</h3>
            </div>
            <div class="card-body">
                <form id="editOmsetForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="jumlah_penjualan" class="form-label">
                                <i class="fas fa-shopping-cart"></i> Jumlah Penjualan (Rp)
                            </label>
                            <input type="number" id="jumlah_penjualan" name="jumlah_penjualan" class="form-control" value="<?php echo htmlspecialchars($total_penjualan); ?>" min="0" step="1" required>
                        </div>

                        <div class="form-group">
                            <label for="jumlah_servis" class="form-label">
                                <i class="fas fa-tools"></i> Jumlah Servis (Rp)
                            </label>
                            <input type="number" id="jumlah_servis" name="jumlah_servis" class="form-control" value="<?php echo htmlspecialchars($total_servis); ?>" min="0" step="1" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="button" class="btn btn-primary btn-block" onclick="submitForm()">
                            <i class="fas fa-save"></i> Update Omset
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Data Table Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-table"></i> View Omset</h3>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-container" style="border: none; box-shadow: none;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Jumlah Penjualan (Rp)</th>
                                <th>Jumlah Servis (Rp)</th>
                                <th>Total Omset (Rp)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td id="view_penjualan" style="color: var(--success-color);">Rp <?php echo number_format($total_penjualan, 0, ',', '.'); ?></td>
                                <td id="view_servis" style="color: var(--primary-color);">Rp <?php echo number_format($total_servis, 0, ',', '.'); ?></td>
                                <td id="view_omset" style="color: var(--text-dark); font-weight: 700;">Rp <?php echo number_format($total_omset, 0, ',', '.'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Back Button -->
        <div style="margin-top: 24px;">
            <button class="btn btn-secondary" onclick="window.location.href='index_kasir.php'">
                <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
            </button>
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

            const minWidth = 180;
            sidebar.style.width = maxWidth > minWidth ? `${maxWidth + 20}px` : `${minWidth}px`;
            document.querySelector('.main-content').style.marginLeft = sidebar.style.width;
        }

        // Run on page load and window resize
        window.addEventListener('load', adjustSidebarWidth);
        window.addEventListener('resize', adjustSidebarWidth);

        function submitForm() {
            const form = document.getElementById('editOmsetForm');
            const formData = new FormData(form);

            fetch('', {  // Submit ke URL yang sama
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Data Omset berhasil diperbarui.');

                    // Update tampilan View Omset tanpa reload
                    const penjualan = parseInt(formData.get('jumlah_penjualan'));
                    const servis = parseInt(formData.get('jumlah_servis'));
                    const totalOmset = penjualan + servis;

                    document.getElementById('view_penjualan').innerText = 'Rp ' + penjualan.toLocaleString();
                    document.getElementById('view_servis').innerText = 'Rp ' + servis.toLocaleString();
                    document.getElementById('view_omset').innerText = 'Rp ' + totalOmset.toLocaleString();
                    
                    // Update summary card
                    document.getElementById('total-omset-display').innerHTML = '<i class="fas fa-coins"></i> Rp ' + totalOmset.toLocaleString();
                } else {
                    alert('Terjadi kesalahan: ' + data.message);
                }
            })
            .catch(error => alert('Terjadi kesalahan: ' + error));
        }
    </script>
</body>
</html>