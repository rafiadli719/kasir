<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'config.php'; // Koneksi ke database

// Pastikan pengguna sudah login dan memiliki peran 'kasir'
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
header('Location: ../../login_dashboard/login.php');
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$kode_karyawan = $_SESSION['kode_karyawan'];

// Dapatkan ID dari URL
if (isset($_GET['id'])) {
    $penjualan_id = $_GET['id'];

    // Ambil data untuk record tertentu dari tabel penjualan dan servis
    $sql = "
        SELECT dp.kode_transaksi, dp.jumlah_penjualan, ds.jumlah_servis, dp.tanggal, dp.waktu
        FROM data_penjualan dp
        JOIN data_servis ds ON dp.kode_transaksi = ds.kode_transaksi
        WHERE dp.id = :id AND dp.kode_karyawan = :kode_karyawan";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $penjualan_id, PDO::PARAM_INT);
    $stmt->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
    $stmt->execute();
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        echo "Record tidak ditemukan atau Anda tidak memiliki izin untuk mengedit record ini.";
        exit;
    }
    
    $kode_transaksi = $record['kode_transaksi'];
} else {
    echo "ID tidak disediakan.";
    exit;
}

// Proses form jika disubmit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $jumlah_penjualan = $_POST['jumlah_penjualan'];
    $jumlah_servis = $_POST['jumlah_servis'];
    $tanggal = $_POST['tanggal'];
    $waktu = $_POST['waktu'];

    // Update data penjualan dan servis
    try {
        $pdo->beginTransaction();

        // Update data penjualan
        $sql_update_penjualan = "
            UPDATE data_penjualan 
            SET jumlah_penjualan = :jumlah_penjualan, tanggal = :tanggal, waktu = :waktu
            WHERE id = :id AND kode_karyawan = :kode_karyawan";
        $stmt_update_penjualan = $pdo->prepare($sql_update_penjualan);
        $stmt_update_penjualan->bindParam(':jumlah_penjualan', $jumlah_penjualan);
        $stmt_update_penjualan->bindParam(':tanggal', $tanggal);
        $stmt_update_penjualan->bindParam(':waktu', $waktu);
        $stmt_update_penjualan->bindParam(':id', $penjualan_id);
        $stmt_update_penjualan->bindParam(':kode_karyawan', $kode_karyawan);
        $stmt_update_penjualan->execute();

        // Update data servis
        $sql_update_servis = "
            UPDATE data_servis 
            SET jumlah_servis = :jumlah_servis, tanggal = :tanggal, waktu = :waktu
            WHERE id = :id AND kode_karyawan = :kode_karyawan";
        $stmt_update_servis = $pdo->prepare($sql_update_servis);
        $stmt_update_servis->bindParam(':jumlah_servis', $jumlah_servis);
        $stmt_update_servis->bindParam(':tanggal', $tanggal);
        $stmt_update_servis->bindParam(':waktu', $waktu);
        $stmt_update_servis->bindParam(':id', $penjualan_id); // ID yang sama
        $stmt_update_servis->bindParam(':kode_karyawan', $kode_karyawan);
        $stmt_update_servis->execute();

        $pdo->commit();
        echo "<script>alert('Data berhasil diperbarui!'); window.location.href='index_kasir.php';</script>";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Terjadi kesalahan saat memperbarui data: " . $e->getMessage();
    }
}

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
    <title>Edit Penjualan dan Servis</title>
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
        .button-group {
            display: flex;
            gap: 16px;
            margin-top: 24px;
        }
        .button-group .btn {
            flex: 1;
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
            .button-group {
                flex-direction: column;
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
            <h1><i class="fas fa-edit"></i> Edit Data Penjualan dan Servis</h1>
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

        <!-- Form Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-edit"></i> Form Edit Data</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="jumlah_penjualan" class="form-label">
                                <i class="fas fa-shopping-cart"></i> Jumlah Penjualan
                            </label>
                            <input type="number" class="form-control" id="jumlah_penjualan" name="jumlah_penjualan" step="0.01" value="<?php echo htmlspecialchars($record['jumlah_penjualan']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="jumlah_servis" class="form-label">
                                <i class="fas fa-tools"></i> Jumlah Servis
                            </label>
                            <input type="number" class="form-control" id="jumlah_servis" name="jumlah_servis" step="0.01" value="<?php echo htmlspecialchars($record['jumlah_servis']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="tanggal" class="form-label">
                                <i class="fas fa-calendar-alt"></i> Tanggal
                            </label>
                            <input type="date" class="form-control" id="tanggal" name="tanggal" value="<?php echo htmlspecialchars($record['tanggal']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="waktu" class="form-label">
                                <i class="fas fa-clock"></i> Waktu
                            </label>
                            <input type="time" class="form-control" id="waktu" name="waktu" value="<?php echo htmlspecialchars($record['waktu']); ?>" required>
                        </div>
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Data
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='index_kasir.php'">
                            <i class="fas fa-times"></i> Batal
                        </button>
                    </div>
                </form>
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
    </script>
</body>
</html>