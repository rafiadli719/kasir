<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'config.php'; // Include your database connection

// Set timezone sesuai zona waktu lokal
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

// Check if user is logged in
if (isset($_SESSION['kode_karyawan'])) {
    $kode_karyawan = $_SESSION['kode_karyawan'];
} else {
    die("Kode karyawan tidak ditemukan di session. Silakan login.");
}

// Get transaction code from URL or session
if (isset($_GET['kode_transaksi'])) {
    $kode_transaksi = $_GET['kode_transaksi'];
} else {
    die("Kode transaksi tidak ditemukan.");
}

// Process form submission for "Pengeluaran Kasir"
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_pengeluaran'])) {
    $kode_akun = $_POST['kode_akun'];
    $jumlah = $_POST['jumlah'];
    $keterangan_transaksi = $_POST['keterangan_transaksi'];
    $nama_transaksi = $_POST['nama_transaksi'] ?? '';
    
    // Set tanggal dan waktu otomatis sesuai zona waktu
    $tanggal = date('Y-m-d'); // Tanggal real-time
    $waktu = date('H:i:s'); // Waktu real-time
    
    // Handle umur_pakai dengan default value dan validasi
    $umur_pakai = isset($_POST['umur_pakai']) && !empty($_POST['umur_pakai']) ? intval($_POST['umur_pakai']) : 0;

    // Validasi angka jumlah agar hanya bilangan bulat
    if (!is_numeric($jumlah) || floor($jumlah) != $jumlah) {
        echo "<script>alert('Jumlah harus berupa bilangan bulat!');</script>";
        exit;
    }

    // Validasi keterangan wajib diisi
    if (empty(trim($keterangan_transaksi))) {
        echo "<script>alert('Keterangan transaksi wajib diisi!');</script>";
        exit;
    }

    // Validasi umur pakai untuk kode akun yang memerlukan
    $queryValidasiUmur = "SELECT require_umur_pakai, min_umur_pakai FROM master_akun WHERE kode_akun = '$kode_akun'";
    $resultValidasiUmur = mysqli_query($conn, $queryValidasiUmur);
    $rowValidasiUmur = mysqli_fetch_assoc($resultValidasiUmur);
    
    if ($rowValidasiUmur && $rowValidasiUmur['require_umur_pakai'] == 1) {
        if ($umur_pakai < $rowValidasiUmur['min_umur_pakai']) {
            echo "<script>alert('Umur pakai minimal " . $rowValidasiUmur['min_umur_pakai'] . " bulan untuk kode akun ini!');</script>";
            exit;
        }
    }

    // Ambil nilai kategori dari tabel master_akun berdasarkan kode_akun yang dipilih
    $queryKategori = "SELECT kategori FROM master_akun WHERE kode_akun = '$kode_akun'";
    $resultKategori = mysqli_query($conn, $queryKategori);
    $rowKategori = mysqli_fetch_assoc($resultKategori);
    $kategori = $rowKategori ? $rowKategori['kategori'] : '';

    // Insert data ke tabel pengeluaran_kasir termasuk kategori
    $query = "INSERT INTO pengeluaran_kasir (kode_transaksi, kode_karyawan, kode_akun, jumlah, keterangan_transaksi, tanggal, waktu, umur_pakai, kategori)
              VALUES ('$kode_transaksi', '$kode_karyawan', '$kode_akun', '$jumlah', '$keterangan_transaksi', '$tanggal', '$waktu', '$umur_pakai', '$kategori')";
    if ($conn->query($query) === TRUE) {
        // Redirect setelah berhasil untuk menghindari form resubmission
        header("Location: pengeluaran_kasir.php?kode_transaksi=$kode_transaksi&success=1");
        exit;
    } else {
        echo "Error: " . $conn->error;
    }
}

// Retrieve data from the master_akun table where jenis_akun is 'pengeluaran'
$sql = "SELECT *, require_umur_pakai, min_umur_pakai FROM master_akun WHERE jenis_akun = 'pengeluaran'";
$result = mysqli_query($conn, $sql);
$master_akun = [];
while ($row = mysqli_fetch_assoc($result)) {
    $master_akun[] = $row;
}

// Retrieve master nama transaksi untuk pengeluaran
$sqlNamaTransaksi = "SELECT mnt.*, ma.arti 
                     FROM master_nama_transaksi mnt 
                     JOIN master_akun ma ON mnt.kode_akun = ma.kode_akun 
                     WHERE mnt.status = 'active' AND ma.jenis_akun = 'pengeluaran'
                     ORDER BY mnt.nama_transaksi";
$resultNamaTransaksi = mysqli_query($conn, $sqlNamaTransaksi);
$master_nama_transaksi = [];
while ($row = mysqli_fetch_assoc($resultNamaTransaksi)) {
    $master_nama_transaksi[] = $row;
}

// Retrieve pengeluaran data only for the active transaction
$sqlPengeluaran = "SELECT pk.*, u.nama_karyawan, ma.kategori
                   FROM pengeluaran_kasir pk
                   JOIN users u ON CONVERT(pk.kode_karyawan USING utf8mb4) = CONVERT(u.kode_karyawan USING utf8mb4)
                   JOIN master_akun ma ON pk.kode_akun = ma.kode_akun
                   WHERE CONVERT(pk.kode_transaksi USING utf8mb4) = CONVERT('$kode_transaksi' USING utf8mb4)
                   AND CONVERT(pk.kode_karyawan USING utf8mb4) = CONVERT('$kode_karyawan' USING utf8mb4)
                   ORDER BY pk.tanggal DESC, pk.waktu DESC";
$resultPengeluaran = mysqli_query($conn, $sqlPengeluaran);

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

$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$cabang_user = $_SESSION['nama_cabang'] ?? 'Unknown Cabang';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengeluaran Kasir</title>
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
        .transaction-code {
            background: var(--background-light);
            padding: 8px 16px;
            border-radius: 12px;
            display: inline-block;
            margin-top: 12px;
            font-weight: 600;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .info-card .label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 4px;
            text-transform: uppercase;
        }
        .info-card .value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-dark);
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
        .alert-success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        .alert-info {
            background: rgba(23,162,184,0.1);
            color: var(--primary-color);
            border-color: rgba(23,162,184,0.2);
        }
        .form-group {
            margin-bottom: 24px;
        }
        .form-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-dark);
            font-size: 14px;
        }
        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-left: 10px;
            font-style: italic;
        }
        .form-control, .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }
        .form-control[readonly] {
            background: var(--background-light);
            color: var(--text-muted);
            cursor: not-allowed;
        }
        .form-control:disabled {
            background: var(--background-light);
            color: var(--text-muted);
            cursor: not-allowed;
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
        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #c82333);
            color: white;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
        }
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
        }
        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #e0a800);
            color: white;
            box-shadow: 0 2px 4px rgba(255, 193, 7, 0.2);
        }
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.3);
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-top: 32px;
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
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-dark);
            margin: 32px 0 16px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--border-color);
        }
        .form-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
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
        .search-container {
            position: relative;
        }
        .search-input-wrapper {
            position: relative;
        }
        .search-input-wrapper .form-control {
            padding-right: 40px;
        }
        .search-clear-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: all 0.3s ease;
            display: none;
        }
        .search-clear-btn:hover {
            background: var(--background-light);
            color: var(--text-dark);
        }
        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .search-result-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s ease;
        }
        .search-result-item:hover {
            background: var(--background-light);
        }
        .search-result-item:last-child {
            border-bottom: none;
        }
        .search-result-item .nama {
            font-weight: 600;
            color: var(--text-dark);
        }
        .search-result-item .kode {
            font-size: 12px;
            color: var(--text-muted);
        }
        .search-no-results {
            padding: 12px 16px;
            text-align: center;
            color: var(--text-muted);
            font-style: italic;
        }
        .search-input-locked {
            background: rgba(0,123,255,0.05) !important;
            border-color: var(--primary-color) !important;
        }
        .search-input-invalid {
            background: rgba(220,53,69,0.05) !important;
            border-color: var(--danger-color) !important;
            color: var(--danger-color) !important;
        }
        .required {
            color: var(--danger-color);
        }
        
        /* Style untuk keterangan default */
        .keterangan-default {
            background: rgba(0,123,255,0.05);
            border: 1px solid rgba(0,123,255,0.2);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 8px;
            font-size: 13px;
            color: var(--primary-color);
            display: none;
        }
        .keterangan-default.show {
            display: block;
        }
        .keterangan-default strong {
            color: var(--text-dark);
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
        <div class="user-profile">
            <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
            <div>
                <strong><?php echo htmlspecialchars($username); ?></strong> (<?php echo htmlspecialchars($cabang_user); ?>)
                <p style="color: var(--text-muted); font-size: 12px;">Kasir</p>
            </div>
        </div>

        <div class="breadcrumb">
            <a href="index_kasir.php"><i class="fas fa-home"></i> Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <span>Pengeluaran Kasir</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-minus-circle"></i> Pengeluaran Kasir</h1>
            <p class="subtitle">Input dan kelola data pengeluaran kas</p>
            <div class="transaction-code">
                <i class="fas fa-receipt"></i> <?php echo htmlspecialchars($kode_transaksi); ?>
            </div>
        </div>

        <!-- Info Cards -->
        <div class="info-grid">
            <div class="info-card">
                <div class="label">Karyawan</div>
                <div class="value"><?php echo htmlspecialchars($karyawan_info); ?></div>
            </div>
            <div class="info-card">
                <div class="label">Cabang</div>
                <div class="value"><?php echo htmlspecialchars($nama_cabang); ?></div>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                Pengeluaran kasir berhasil ditambahkan.
            </div>
        <?php endif; ?>

        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> 
            <strong>Info:</strong> Ketik untuk mencari nama transaksi dengan cepat. Hanya nama transaksi yang tersedia di sistem yang dapat dipilih. Input akan berubah warna biru saat transaksi valid dipilih.
        </div>

        <!-- Form Section -->
        <div class="form-section">
            <h3 style="margin-bottom: 20px; color: var(--text-dark);"><i class="fas fa-edit"></i> Form Input Pengeluaran</h3>
            <form method="POST" action="" id="pengeluaranForm">
                <!-- Nama Transaksi dengan Rigid Search - Full Width -->
                <div class="form-group">
                    <label for="nama_transaksi" class="form-label">
                        <i class="fas fa-search"></i> Nama Transaksi <span class="required">*</span>
                        <span class="form-hint">Ketik untuk mencari - hanya bisa memilih dari daftar yang tersedia</span>
                    </label>
                    <div class="search-container">
                        <div class="search-input-wrapper">
                            <input type="text" id="nama_transaksi" name="nama_transaksi" class="form-control" 
                                   placeholder="Klik untuk mencari transaksi pengeluaran..." autocomplete="off" readonly required>
                            <button type="button" class="search-clear-btn" id="clear_search" title="Hapus pencarian">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="search-results" id="search_results">
                            <!-- Results will be populated by JavaScript -->
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="kode_akun" class="form-label">
                        <i class="fas fa-code"></i> Kode Akun <span class="required">*</span>
                        <span class="form-hint">Otomatis terisi sesuai dengan nama transaksi yang dipilih.</span>
                    </label>
                    <select name="kode_akun" id="kode_akun" class="form-select" readonly style="pointer-events: none; background: var(--background-light); cursor: not-allowed;" required>
                        <option value="">-- Otomatis terisi berdasarkan nama transaksi --</option>
                        <?php foreach ($master_akun as $akun): ?>
                            <option value="<?php echo $akun['kode_akun']; ?>"><?php echo $akun['kode_akun'] . ' - ' . $akun['arti']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="kategori" class="form-label">
                        <i class="fas fa-tags"></i> Kategori Akun
                        <span class="form-hint">Otomatis terisi berdasarkan kode akun yang dipilih.</span>
                    </label>
                    <input type="text" name="kategori" id="kategori_akun" class="form-control" readonly>
                </div>

                <div class="form-group">
                    <label for="keterangan_transaksi" class="form-label">
                        <i class="fas fa-sticky-note"></i> Keterangan Transaksi <span class="required">*</span>
                        <span class="form-hint">Keterangan wajib diisi untuk melengkapi data transaksi.</span>
                    </label>
                    <!-- Tampilkan keterangan default di atas input tanpa auto-fill -->
                    <div class="keterangan-default" id="keterangan_default">
                        <strong>Keterangan Default:</strong> <span id="default_text">-</span>
                    </div>
                    <input type="text" name="keterangan_transaksi" id="keterangan_transaksi" class="form-control" oninput="this.value = this.value.toUpperCase()" placeholder="Masukkan keterangan" required>
                </div>

                <!-- Jumlah dan Umur Pakai Bersebelahan -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="jumlah" class="form-label">
                            <i class="fas fa-money-bill-wave"></i> Jumlah (Rp) <span class="required">*</span>
                            <span class="form-hint">Masukkan jumlah dalam rupiah (bilangan bulat).</span>
                        </label>
                        <input type="number" name="jumlah" id="jumlah" class="form-control" step="1" placeholder="Masukkan jumlah" required>
                    </div>

                    <div class="form-group">
                        <label for="umur_pakai" class="form-label">
                            <i class="fas fa-calendar-alt"></i> Umur Pakai (Bulan)
                            <span class="form-hint">Akan aktif jika kode akun memerlukan umur pakai.</span>
                        </label>
                        <input type="number" name="umur_pakai" id="umur_pakai" class="form-control" min="0" placeholder="Masukkan umur pakai" value="0" disabled>
                    </div>
                </div>

                <button type="submit" name="submit_pengeluaran" class="btn btn-danger" style="width: 100%;" id="submit_btn">
                    <i class="fas fa-save"></i> Tambah Pengeluaran
                </button>
            </form>
        </div>

        <!-- Data Table Section -->
        <h2 class="section-title"><i class="fas fa-table"></i> Data Pengeluaran Kasir</h2>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Kasir</th>
                        <th>Kode Akun</th>
                        <th>Kategori Akun</th>
                        <th>Jumlah (Rp)</th>
                        <th>Keterangan</th>
                        <th>Umur Pakai</th>
                        <th>Tanggal</th>
                        <th>Waktu</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $i = 1;
                    while ($row = mysqli_fetch_assoc($resultPengeluaran)) : ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($row['nama_karyawan'] ?? 'N/A'); ?></td>
                            <td style="font-weight: 600;"><?php echo htmlspecialchars($row['kode_akun'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['kategori'] ?? 'N/A'); ?></td>
                            <td style="font-weight: 600; color: var(--danger-color);">Rp <?php echo number_format($row['jumlah'] ?? 0, 0, ',', '.'); ?></td>
                            <td><?php echo htmlspecialchars($row['keterangan_transaksi'] ?? 'N/A'); ?></td>
                            <td><?php echo ($row['umur_pakai'] ?? 0) . ' bulan'; ?></td>
                            <td><?php echo htmlspecialchars($row['tanggal'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($row['waktu'] ?? 'N/A'); ?></td>
                            <td>
                                <a href="edit_pengeluaran.php?id=<?php echo $row['id']; ?>" class="btn btn-warning btn-sm">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <a href="hapus_pengeluaran.php?id=<?php echo $row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Apakah Anda yakin ingin menghapus data ini?')">
                                    <i class="fas fa-trash"></i> Hapus
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
                 
    <script>
        // Master data transaksi untuk auto search (PENGELUARAN)
        const masterNamaTransaksi = <?php echo json_encode($master_nama_transaksi); ?>;
        const masterAkun = <?php echo json_encode($master_akun); ?>;

        let selectedTransaksi = null;
        let lastValidValue = '';
        let isSelecting = false;

        // Enhanced search functionality with rigid validation
        function setupRigidSearch() {
            const input = document.getElementById('nama_transaksi');
            const resultsContainer = document.getElementById('search_results');
            const clearBtn = document.getElementById('clear_search');
            
            if (!input || !resultsContainer || !clearBtn) return;
            
            // PERBAIKAN: Click handler untuk rigid mode
            input.addEventListener('click', function() {
                if (this.readOnly) {
                    this.readOnly = false;
                    this.focus();
                    this.select();
                    this.placeholder = "Ketik untuk mencari transaksi pengeluaran...";
                }
            });
            
            // Show dropdown when input gets focus
            input.addEventListener('focus', function() {
                const query = this.value.trim();
                populateDropdown(query);
                resultsContainer.style.display = 'block';
            });
            
            // Real-time search as user types with rigid validation
            input.addEventListener('input', function() {
                if (isSelecting) return; // Skip validation during selection
                
                const query = this.value.trim();
                populateDropdown(query);
                resultsContainer.style.display = 'block';
                
                // Check if current value matches any exact transaction name
                const exactMatch = masterNamaTransaksi.find(item => 
                    item.nama_transaksi.toLowerCase() === query.toLowerCase()
                );
                
                if (exactMatch) {
                    // Valid exact match
                    this.classList.remove('search-input-invalid');
                    this.classList.add('search-input-locked');
                    selectedTransaksi = exactMatch;
                    updateTransaksiFields(exactMatch);
                    lastValidValue = exactMatch.nama_transaksi;
                } else if (query === '') {
                    // Empty input - reset everything
                    this.classList.remove('search-input-invalid', 'search-input-locked');
                    resetTransaksiSelection();
                    lastValidValue = '';
                } else {
                    // Typing but no exact match yet - show as search mode
                    this.classList.remove('search-input-locked');
                    this.classList.add('search-input-invalid');
                    // Don't reset selection yet, user might be typing
                }
                
                // Show/hide clear button
                clearBtn.style.display = query ? 'block' : 'none';
            });
            
            // PERBAIKAN: Validate on blur dengan rigid mode
            input.addEventListener('blur', function() {
                setTimeout(() => { // Delay to allow click events on results
                    const query = this.value.trim();
                    const exactMatch = masterNamaTransaksi.find(item => 
                        item.nama_transaksi.toLowerCase() === query.toLowerCase()
                    );
                    
                    if (!exactMatch && query !== '') {
                        // Invalid input - revert to last valid value
                        this.value = lastValidValue;
                        this.classList.remove('search-input-invalid');
                        if (lastValidValue) {
                            this.classList.add('search-input-locked');
                        }
                        if (!lastValidValue) {
                            resetTransaksiSelection();
                        }
                    }
                    
                    // Always set readonly after validation (RIGID)
                    this.readOnly = true;
                    this.placeholder = "Klik untuk mencari transaksi pengeluaran...";
                    resultsContainer.style.display = 'none';
                }, 200);
            });
            
            // Clear button functionality
            clearBtn.addEventListener('click', function() {
                input.value = '';
                lastValidValue = '';
                input.classList.remove('search-input-invalid', 'search-input-locked');
                resetTransaksiSelection();
                populateDropdown('');
                resultsContainer.style.display = 'block';
                input.focus();
                this.style.display = 'none';
            });
        }

        // Populate dropdown with filtered results
        function populateDropdown(query) {
            const resultsContainer = document.getElementById('search_results');
            
            if (!resultsContainer) return;
            
            const filteredResults = masterNamaTransaksi.filter(item => 
                item.nama_transaksi.toLowerCase().includes(query.toLowerCase()) ||
                item.kode_akun.toLowerCase().includes(query.toLowerCase()) ||
                item.arti.toLowerCase().includes(query.toLowerCase())
            );
            
            resultsContainer.innerHTML = '';
            
            if (filteredResults.length === 0) {
                const noResults = document.createElement('div');
                noResults.className = 'search-no-results';
                noResults.textContent = 'Tidak ada transaksi yang ditemukan';
                resultsContainer.appendChild(noResults);
            } else {
                filteredResults.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'search-result-item';
                    
                    // Highlight matching text
                    const highlightText = (text, query) => {
                        if (!query) return text;
                        const regex = new RegExp(`(${query})`, 'gi');
                        return text.replace(regex, '<mark style="background: yellow; padding: 0;">$1</mark>');
                    };
                    
                    div.innerHTML = `
                        <div class="nama">${highlightText(item.nama_transaksi, query)}</div>
                        <div class="kode">${highlightText(item.kode_akun, query)} - ${highlightText(item.arti, query)}</div>
                    `;
                    div.addEventListener('click', function() {
                        selectTransaksi(item);
                    });
                    resultsContainer.appendChild(div);
                });
            }
        }

        function updateTransaksiFields(item) {
            // Set kode akun
            document.getElementById('kode_akun').value = item.kode_akun;
            
            // Tampilkan keterangan default di atas input, tidak auto-fill ke input
            const keteranganDefault = document.getElementById('keterangan_default');
            const defaultText = document.getElementById('default_text');
            
            if (item.keterangan_default) {
                defaultText.textContent = item.keterangan_default;
                keteranganDefault.classList.add('show');
            } else {
                keteranganDefault.classList.remove('show');
            }
            
            // Kosongkan input keterangan - jangan auto-fill
            document.getElementById('keterangan_transaksi').value = '';
            
            // Set kategori dan umur pakai
            setKategoriAkuntype();
        }

        function selectTransaksi(item) {
            const input = document.getElementById('nama_transaksi');
            const clearBtn = document.getElementById('clear_search');
            
            // Set flag to prevent validation during selection
            isSelecting = true;
            
            selectedTransaksi = item;
            
            // Set nama transaksi
            input.value = item.nama_transaksi;
            input.classList.remove('search-input-invalid');
            input.classList.add('search-input-locked');
            
            // Update all related fields
            updateTransaksiFields(item);
            
            // Update last valid value
            lastValidValue = item.nama_transaksi;
            
            // Hide search results
            document.getElementById('search_results').style.display = 'none';
            
            // Hide clear button
            if (clearBtn) clearBtn.style.display = 'none';
            
            // Reset flag
            setTimeout(() => {
                isSelecting = false;
            }, 100);
        }

        function resetTransaksiSelection() {
            selectedTransaksi = null;
            const input = document.getElementById('nama_transaksi');
            if (input) {
                input.classList.remove('search-input-invalid', 'search-input-locked');
            }
            document.getElementById('kode_akun').value = '';
            document.getElementById('keterangan_default').classList.remove('show');
            document.getElementById('kategori_akun').value = '';
            document.getElementById('umur_pakai').disabled = true;
            document.getElementById('umur_pakai').value = 0;
            document.getElementById('umur_pakai').required = false;
        }

        function setKategoriAkuntype() {
            var kodeAkun = document.getElementById("kode_akun");
            var kategoriAkunInput = document.getElementById("kategori_akun");
            var umurPakaiInput = document.getElementById("umur_pakai");
            
            // Set kategori berdasarkan master data
            const masterItem = masterAkun.find(item => item.kode_akun === kodeAkun.value);
            if (masterItem) {
                kategoriAkunInput.value = masterItem.kategori || '';
                
                // Set umur pakai behavior
                if (masterItem.require_umur_pakai == 1) {
                    umurPakaiInput.disabled = false;
                    umurPakaiInput.value = masterItem.min_umur_pakai || 0;
                    umurPakaiInput.min = masterItem.min_umur_pakai || 0;
                    umurPakaiInput.required = true;
                } else {
                    umurPakaiInput.disabled = true;
                    umurPakaiInput.value = 0;
                    umurPakaiInput.required = false;
                }
            }
        }

        // Form validation with rigid check
        document.getElementById('pengeluaranForm').addEventListener('submit', function(e) {
            if (!selectedTransaksi) {
                e.preventDefault();
                alert('Pilih nama transaksi terlebih dahulu!');
                document.getElementById('nama_transaksi').focus();
                return false;
            }
            
            // Validate keterangan is filled
            const keterangan = document.getElementById('keterangan_transaksi').value.trim();
            if (!keterangan) {
                e.preventDefault();
                alert('Keterangan transaksi wajib diisi!');
                document.getElementById('keterangan_transaksi').focus();
                return false;
            }
            
            // Validate jumlah is integer
            const jumlah = document.getElementById('jumlah').value;
            if (!jumlah || isNaN(jumlah) || Math.floor(jumlah) != jumlah) {
                e.preventDefault();
                alert('Jumlah harus berupa bilangan bulat!');
                document.getElementById('jumlah').focus();
                return false;
            }
            
            return true;
        });

        // Hide search results when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-container')) {
                document.getElementById('search_results').style.display = 'none';
            }
        });

        // Format number input
        document.getElementById('jumlah').addEventListener('input', function() {
            // Remove non-numeric characters except for the value itself
            let value = this.value.replace(/[^\d]/g, '');
            this.value = value;
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            setupRigidSearch();
            
            // Add mark styling for search highlights
            const style = document.createElement('style');
            style.textContent = `
                mark {
                    background-color: #ffeb3b;
                    color: #000;
                    padding: 1px 2px;
                    border-radius: 2px;
                    font-weight: 600;
                }
            `;
            document.head.appendChild(style);
        });
    </script>
</body>
</html>