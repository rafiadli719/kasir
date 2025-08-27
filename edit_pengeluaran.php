<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'config.php'; // Include your database connection

// Set timezone sesuai zona waktu lokal
date_default_timezone_set('Asia/Jakarta');

// Check if user is logged in
if (isset($_SESSION['kode_karyawan'])) {
    $kode_karyawan = $_SESSION['kode_karyawan'];
} else {
    die("Kode karyawan tidak ditemukan di session. Silakan login.");
}

// Get pengeluaran ID from URL
if (isset($_GET['id'])) {
    $id = $_GET['id'];
} else {
    die("ID pengeluaran tidak ditemukan.");
}

// Retrieve the data of the pengeluaran record to be edited
$sqlEdit = "SELECT pk.*, ma.kategori, ma.require_umur_pakai, ma.min_umur_pakai
            FROM pengeluaran_kasir pk 
            JOIN master_akun ma ON pk.kode_akun = ma.kode_akun 
            WHERE pk.id = :id AND pk.kode_karyawan = :kode_karyawan";
$stmtEdit = $pdo->prepare($sqlEdit);
$stmtEdit->bindParam(':id', $id, PDO::PARAM_INT);
$stmtEdit->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmtEdit->execute();
$data = $stmtEdit->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    die("Data pengeluaran tidak ditemukan.");
}

// Validasi dan set default value untuk semua field
$kode_transaksi = isset($data['kode_transaksi']) && !empty($data['kode_transaksi']) 
    ? $data['kode_transaksi'] 
    : 'TRX-' . date('YmdHis') . '-' . $id;

$kategori = isset($data['kategori']) && !is_null($data['kategori']) ? $data['kategori'] : '';
$require_umur_pakai = isset($data['require_umur_pakai']) && !is_null($data['require_umur_pakai']) ? $data['require_umur_pakai'] : 0;
$min_umur_pakai = isset($data['min_umur_pakai']) && !is_null($data['min_umur_pakai']) ? $data['min_umur_pakai'] : 0;
$keterangan_transaksi = isset($data['keterangan_transaksi']) && !is_null($data['keterangan_transaksi']) ? $data['keterangan_transaksi'] : '';
$jumlah = isset($data['jumlah']) && !is_null($data['jumlah']) ? $data['jumlah'] : 0;
$umur_pakai = isset($data['umur_pakai']) && !is_null($data['umur_pakai']) ? $data['umur_pakai'] : 0;
$kode_akun = isset($data['kode_akun']) && !is_null($data['kode_akun']) ? $data['kode_akun'] : '';

// Retrieve data from the `master_akun` table where jenis_akun is 'pengeluaran'
$sql = "SELECT *, require_umur_pakai, min_umur_pakai FROM master_akun WHERE jenis_akun = 'pengeluaran'";
$result = $pdo->query($sql);
$master_akun = $result->fetchAll(PDO::FETCH_ASSOC);

// Retrieve master nama transaksi untuk pengeluaran
$sqlNamaTransaksi = "SELECT mnt.*, ma.arti 
                     FROM master_nama_transaksi mnt 
                     JOIN master_akun ma ON mnt.kode_akun = ma.kode_akun 
                     WHERE mnt.status = 'active' AND ma.jenis_akun = 'pengeluaran'
                     ORDER BY mnt.nama_transaksi";
$stmtNamaTransaksi = $pdo->query($sqlNamaTransaksi);
$master_nama_transaksi = $stmtNamaTransaksi->fetchAll(PDO::FETCH_ASSOC);

// Process form submission for editing "Pengeluaran Kasir"
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_pengeluaran'])) {
    $tanggal = date('Y-m-d');
    $waktu = date('H:i:s');
    $kode_akun_form = $_POST['kode_akun'];
    $jumlah_form = $_POST['jumlah'];
    $keterangan_transaksi_form = $_POST['keterangan_transaksi'];
    
    // Handle umur_pakai dengan default value
    $umur_pakai_form = isset($_POST['umur_pakai']) && !empty($_POST['umur_pakai']) ? intval($_POST['umur_pakai']) : 0;
    
    // Validasi angka jumlah agar hanya bilangan bulat
    if (!is_numeric($jumlah_form) || floor($jumlah_form) != $jumlah_form) {
        echo "<script>alert('Jumlah harus berupa bilangan bulat!');</script>";
        exit;
    }

    // Validasi keterangan wajib diisi
    if (empty(trim($keterangan_transaksi_form))) {
        echo "<script>alert('Keterangan transaksi wajib diisi!');</script>";
        exit;
    }

    // Validasi umur pakai untuk kode akun yang memerlukan
    $queryValidasiUmur = "SELECT require_umur_pakai, min_umur_pakai FROM master_akun WHERE kode_akun = :kode_akun";
    $stmtValidasiUmur = $pdo->prepare($queryValidasiUmur);
    $stmtValidasiUmur->bindParam(':kode_akun', $kode_akun_form);
    $stmtValidasiUmur->execute();
    $rowValidasiUmur = $stmtValidasiUmur->fetch(PDO::FETCH_ASSOC);
    
    if ($rowValidasiUmur && $rowValidasiUmur['require_umur_pakai'] == 1) {
        if ($umur_pakai_form < $rowValidasiUmur['min_umur_pakai']) {
            echo "<script>alert('Umur pakai minimal " . $rowValidasiUmur['min_umur_pakai'] . " bulan untuk kode akun ini!');</script>";
            exit;
        }
    }
    
    // Get kategori based on selected kode_akun
    $queryKategori = "SELECT kategori FROM master_akun WHERE kode_akun = :kode_akun";
    $stmtKategori = $pdo->prepare($queryKategori);
    $stmtKategori->bindParam(':kode_akun', $kode_akun_form);
    $stmtKategori->execute();
    $rowKategori = $stmtKategori->fetch(PDO::FETCH_ASSOC);
    $kategori_akun = $rowKategori ? $rowKategori['kategori'] : '';

    // Update the pengeluaran_kasir table with the new values
    $query = "UPDATE pengeluaran_kasir SET 
              kode_akun = :kode_akun, 
              jumlah = :jumlah, 
              keterangan_transaksi = :keterangan_transaksi, 
              tanggal = :tanggal, 
              waktu = :waktu, 
              umur_pakai = :umur_pakai, 
              kategori = :kategori
              WHERE id = :id AND kode_karyawan = :kode_karyawan";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':kode_akun', $kode_akun_form, PDO::PARAM_STR);
    $stmt->bindParam(':jumlah', $jumlah_form, PDO::PARAM_INT);
    $stmt->bindParam(':keterangan_transaksi', $keterangan_transaksi_form, PDO::PARAM_STR);
    $stmt->bindParam(':tanggal', $tanggal, PDO::PARAM_STR);
    $stmt->bindParam(':waktu', $waktu, PDO::PARAM_STR);
    $stmt->bindParam(':umur_pakai', $umur_pakai_form, PDO::PARAM_INT);
    $stmt->bindParam(':kategori', $kategori_akun, PDO::PARAM_STR);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);

    if ($stmt->execute()) {
        // Redirect to pengeluaran_kasir.php after successful update
        echo "<script>alert('Data pengeluaran berhasil diperbarui.'); window.location.href='pengeluaran_kasir.php?kode_transaksi=" . $kode_transaksi . "';</script>";
    } else {
        echo "<script>alert('Terjadi kesalahan saat memperbarui data.');</script>";
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

$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$cabang_user = $_SESSION['nama_cabang'] ?? 'Unknown Cabang';

// Cek nama transaksi saat ini berdasarkan kode akun
$current_nama_transaksi = '';
foreach ($master_nama_transaksi as $mnt) {
    if ($mnt['kode_akun'] == $kode_akun) {
        $current_nama_transaksi = $mnt['nama_transaksi'];
        break;
    }
}

// Ambil keterangan default untuk menampilkan sebagai hint
$current_keterangan_default = '';
foreach ($master_nama_transaksi as $mnt) {
    if ($mnt['kode_akun'] == $kode_akun) {
        $current_keterangan_default = $mnt['keterangan_default'] ?? '';
        break;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Pengeluaran Kasir</title>
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
            font-size: 16px;
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
        .form-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
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
        .search-results.show {
            display: block;
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
        .search-result-item.highlight {
            background: var(--primary-color);
            color: white;
        }
        .search-result-item.highlight .nama,
        .search-result-item.highlight .kode {
            color: white;
        }
        .no-results {
            padding: 16px;
            text-align: center;
            color: var(--text-muted);
            font-style: italic;
        }
        .search-hint {
            background: rgba(0,123,255,0.05);
            border: 1px solid rgba(0,123,255,0.2);
            border-radius: 6px;
            padding: 8px 12px;
            margin-top: 4px;
            font-size: 12px;
            color: var(--primary-color);
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

        /* PERBAIKAN: Style untuk validasi input nama transaksi */
        .form-control.valid {
            border-color: var(--success-color);
            background-color: rgba(40, 167, 69, 0.05);
        }
        .form-control.invalid {
            border-color: var(--danger-color);
            background-color: rgba(220, 53, 69, 0.05);
        }
        
        /* RIGID MODE STYLING */
        .search-input-locked {
            background: rgba(0,123,255,0.05) !important;
            border-color: var(--primary-color) !important;
            font-weight: 600;
        }
        .search-input-invalid {
            background: rgba(220, 53, 69, 0.05) !important;
            border-color: var(--danger-color) !important;
        }
        .validation-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            display: none;
        }
        .validation-icon.valid {
            color: var(--success-color);
            display: block;
        }
        .validation-icon.invalid {
            color: var(--danger-color);
            display: block;
        }
        .input-group {
            position: relative;
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
            <a href="pengeluaran_kasir.php?kode_transaksi=<?php echo htmlspecialchars($kode_transaksi); ?>">Pengeluaran Kasir</a>
            <i class="fas fa-chevron-right"></i>
            <span>Edit Pengeluaran</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-edit"></i> Edit Pengeluaran Kasir</h1>
            <p class="subtitle">Edit dan perbarui data pengeluaran kas</p>
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
            <div class="info-card">
                <div class="label">ID Pengeluaran</div>
                <div class="value">#<?php echo htmlspecialchars($id); ?></div>
            </div>
        </div>

        <!-- Form Section -->
        <div class="form-section">
            <form method="POST" action="" id="editPengeluaranForm">
                <!-- Nama Transaksi dengan Auto Search - PERBAIKAN -->
                <div class="form-group">
                    <label for="nama_transaksi" class="form-label">
                        <i class="fas fa-search"></i> Nama Transaksi
                        <span class="form-hint">Nama transaksi saat ini: <strong><?php echo htmlspecialchars($current_nama_transaksi); ?></strong></span>
                    </label>
                    <div class="search-container">
                        <div class="input-group">
                            <input type="text" id="nama_transaksi" name="nama_transaksi" class="form-control valid" 
                                   value="<?php echo htmlspecialchars($current_nama_transaksi); ?>" 
                                   placeholder="Klik untuk mencari transaksi pengeluaran..." 
                                   autocomplete="off" readonly required>
                            <div class="validation-icon valid" id="validation_icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                        <div class="search-hint">
                            <i class="fas fa-keyboard"></i> Klik untuk mencari dan mengubah transaksi, gunakan ↑↓ untuk navigasi, Enter untuk memilih
                        </div>
                        <div class="search-results" id="search_results">
                            <!-- Results will be populated by JavaScript -->
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="kode_akun" class="form-label">
                        <i class="fas fa-code"></i> Kode Akun
                        <span class="form-hint">Otomatis terisi sesuai dengan nama transaksi yang dipilih.</span>
                    </label>
                    <select name="kode_akun" id="kode_akun" class="form-select" readonly style="pointer-events: none; background: var(--background-light); cursor: not-allowed;" required>
                        <?php foreach ($master_akun as $akun): ?>
                            <option value="<?php echo $akun['kode_akun']; ?>" <?php if ($akun['kode_akun'] == $kode_akun) echo 'selected'; ?>>
                                <?php echo $akun['kode_akun'] . ' - ' . $akun['arti']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="kategori_akun" class="form-label">
                        <i class="fas fa-tags"></i> Kategori Akun
                        <span class="form-hint">Otomatis terisi berdasarkan kode akun yang dipilih.</span>
                    </label>
                    <input type="text" id="kategori_akun" name="kategori_akun" class="form-control" value="<?php echo htmlspecialchars($kategori); ?>" readonly>
                </div>

                <div class="form-group">
                    <label for="keterangan_transaksi" class="form-label">
                        <i class="fas fa-sticky-note"></i> Keterangan Transaksi <span class="required">*</span>
                        <span class="form-hint">Keterangan wajib diisi untuk melengkapi data transaksi.</span>
                    </label>
                    <!-- Tampilkan keterangan default di atas input tanpa auto-fill -->
                    <?php if (!empty($current_keterangan_default)): ?>
                    <div class="keterangan-default show" id="keterangan_default">
                        <strong>Keterangan Default untuk "<?php echo htmlspecialchars($current_nama_transaksi); ?>":</strong> 
                        <span id="default_text"><?php echo htmlspecialchars($current_keterangan_default); ?></span>
                    </div>
                    <?php else: ?>
                    <div class="keterangan-default" id="keterangan_default">
                        <strong>Keterangan Default:</strong> <span id="default_text">-</span>
                    </div>
                    <?php endif; ?>
                    <input type="text" name="keterangan_transaksi" id="keterangan_transaksi" class="form-control" value="<?php echo htmlspecialchars($keterangan_transaksi); ?>" oninput="this.value = this.value.toUpperCase()" required>
                </div>

                <!-- Jumlah dan Umur Pakai Bersebelahan -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="jumlah" class="form-label">
                            <i class="fas fa-money-bill-wave"></i> Jumlah (Rp) <span class="required">*</span>
                            <span class="form-hint">Masukkan jumlah dalam rupiah (bilangan bulat).</span>
                        </label>
                        <input type="number" name="jumlah" id="jumlah" class="form-control" step="1" value="<?php echo htmlspecialchars($jumlah); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="umur_pakai" class="form-label">
                            <i class="fas fa-calendar-alt"></i> Umur Pakai (Bulan)
                            <span class="form-hint">Akan aktif jika kode akun memerlukan umur pakai.</span>
                        </label>
                        <input type="number" name="umur_pakai" id="umur_pakai" class="form-control" min="0" value="<?php echo htmlspecialchars($umur_pakai); ?>" 
                               <?php echo ($require_umur_pakai == 1) ? '' : 'disabled'; ?>>
                    </div>
                </div>

                <button type="submit" name="submit_pengeluaran" class="btn btn-danger" style="width: 100%;">
                    <i class="fas fa-save"></i> Perbarui Data Pengeluaran
                </button>
            </form>
        </div>
    </div>

    <script>
        // Master data transaksi untuk auto search (PENGELUARAN)
        const masterNamaTransaksi = <?php echo json_encode($master_nama_transaksi); ?>;
        const masterAkun = <?php echo json_encode($master_akun); ?>;

        let selectedTransaksi = null;
        let currentHighlightIndex = -1;
        let filteredResults = [];
        let isValidSelection = true; // Track if current selection is valid

        // Initialize selected transaction based on current data
        selectedTransaksi = masterNamaTransaksi.find(item => item.kode_akun === '<?php echo $kode_akun; ?>');

        // Enhanced search functionality with type to find
        const namaTransaksiInput = document.getElementById('nama_transaksi');
        const searchResults = document.getElementById('search_results');
        const validationIcon = document.getElementById('validation_icon');

        // PERBAIKAN: Event listener untuk klik pada input (mengaktifkan mode edit)
        namaTransaksiInput.addEventListener('click', function() {
            if (this.readOnly) {
                this.readOnly = false;
                this.focus();
                this.select();
                this.placeholder = "Ketik untuk mencari transaksi pengeluaran...";
                updateValidationState(false);
            }
        });

        // PERBAIKAN: Event listener untuk blur (keluar dari input)
        namaTransaksiInput.addEventListener('blur', function(e) {
            // Delay untuk memungkinkan klik pada dropdown
            setTimeout(() => {
                if (!searchResults.matches(':hover')) {
                    validateCurrentInput();
                }
            }, 200);
        });

        // Input event listener for real-time search
        namaTransaksiInput.addEventListener('input', function() {
            const query = this.value.trim();
            currentHighlightIndex = -1;
            
            if (query.length >= 1) {
                filteredResults = masterNamaTransaksi.filter(item => 
                    item.nama_transaksi.toLowerCase().includes(query.toLowerCase()) ||
                    item.kode_akun.toLowerCase().includes(query.toLowerCase()) ||
                    item.arti.toLowerCase().includes(query.toLowerCase())
                );
                populateDropdown(filteredResults);
                searchResults.classList.add('show');
                
                // Check if current input exactly matches any result
                const exactMatch = masterNamaTransaksi.find(item => 
                    item.nama_transaksi.toLowerCase() === query.toLowerCase()
                );
                updateValidationState(!!exactMatch);
            } else {
                searchResults.classList.remove('show');
                filteredResults = [];
                updateValidationState(false);
            }
        });

        // Keyboard navigation
        namaTransaksiInput.addEventListener('keydown', function(e) {
            const items = searchResults.querySelectorAll('.search-result-item:not(.no-results)');
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                currentHighlightIndex = Math.min(currentHighlightIndex + 1, items.length - 1);
                updateHighlight(items);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                currentHighlightIndex = Math.max(currentHighlightIndex - 1, 0);
                updateHighlight(items);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (currentHighlightIndex >= 0 && items[currentHighlightIndex]) {
                    const index = parseInt(items[currentHighlightIndex].dataset.index);
                    selectTransaksi(filteredResults[index]);
                }
            } else if (e.key === 'Escape') {
                searchResults.classList.remove('show');
                currentHighlightIndex = -1;
                validateCurrentInput();
            }
        });

        function updateHighlight(items) {
            items.forEach((item, index) => {
                if (index === currentHighlightIndex) {
                    item.classList.add('highlight');
                } else {
                    item.classList.remove('highlight');
                }
            });
        }

        // Populate dropdown with filtered results
        function populateDropdown(results) {
            searchResults.innerHTML = '';
            
            if (results.length === 0) {
                const noResults = document.createElement('div');
                noResults.className = 'no-results';
                noResults.innerHTML = '<i class="fas fa-search"></i> Tidak ada transaksi yang ditemukan';
                searchResults.appendChild(noResults);
                return;
            }
            
            results.forEach((item, index) => {
                const div = document.createElement('div');
                div.className = 'search-result-item';
                div.dataset.index = index;
                
                // Highlight matching text
                const query = namaTransaksiInput.value.toLowerCase();
                const namaHighlighted = highlightText(item.nama_transaksi, query);
                const kodeHighlighted = highlightText(`${item.kode_akun} - ${item.arti}`, query);
                
                div.innerHTML = `
                    <div class="nama">${namaHighlighted}</div>
                    <div class="kode">${kodeHighlighted}</div>
                `;
                
                div.addEventListener('click', function() {
                    selectTransaksi(item);
                });
                
                div.addEventListener('mouseenter', function() {
                    currentHighlightIndex = index;
                    updateHighlight(searchResults.querySelectorAll('.search-result-item:not(.no-results)'));
                });
                
                searchResults.appendChild(div);
            });
        }

        function highlightText(text, query) {
            if (!query) return text;
            
            const regex = new RegExp(`(${escapeRegExp(query)})`, 'gi');
            return text.replace(regex, '<mark style="background: yellow; padding: 0;">$1</mark>');
        }

        function escapeRegExp(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        // PERBAIKAN: Function untuk validasi input saat ini (RIGID MODE)
        function validateCurrentInput() {
            const currentValue = namaTransaksiInput.value.trim();
            const exactMatch = masterNamaTransaksi.find(item => 
                item.nama_transaksi.toLowerCase() === currentValue.toLowerCase()
            );
            
            if (exactMatch) {
                // Valid selection - lock it in
                selectTransaksi(exactMatch, false);
                updateValidationState(true);
            } else {
                // Invalid input - force back to previous valid value or empty
                if (selectedTransaksi) {
                    namaTransaksiInput.value = selectedTransaksi.nama_transaksi;
                    updateValidationState(true);
                } else {
                    namaTransaksiInput.value = '';
                    updateValidationState(false);
                    resetTransaksiSelection();
                }
            }
            
            // Always set readonly after validation (RIGID)
            namaTransaksiInput.readOnly = true;
            namaTransaksiInput.placeholder = "Klik untuk mencari transaksi pengeluaran...";
            searchResults.classList.remove('show');
        }

        // PERBAIKAN: Function untuk reset seleksi transaksi (RIGID MODE)
        function resetTransaksiSelection() {
            selectedTransaksi = null;
            document.getElementById('kode_akun').value = '';
            document.getElementById('kategori_akun').value = '';
            
            // Hide keterangan default
            const keteranganDefault = document.getElementById('keterangan_default');
            if (keteranganDefault) {
                keteranganDefault.classList.remove('show');
            }
            
            // Hide umur pakai section
            const umurPakaiSection = document.getElementById('umur_pakai_section');
            if (umurPakaiSection) {
                umurPakaiSection.style.display = 'none';
            }
            
            // Clear umur pakai field
            const umurPakaiInput = document.getElementById('umur_pakai');
            if (umurPakaiInput) {
                umurPakaiInput.value = '';
                umurPakaiInput.required = false;
            }
        }

        // PERBAIKAN: Function untuk update status validasi visual
        function updateValidationState(isValid) {
            isValidSelection = isValid;
            const input = namaTransaksiInput;
            const icon = validationIcon;
            
            // Remove all validation classes
            input.classList.remove('valid', 'invalid');
            icon.classList.remove('valid', 'invalid');
            
            if (isValid) {
                input.classList.add('valid');
                icon.classList.add('valid');
                icon.innerHTML = '<i class="fas fa-check-circle"></i>';
            } else {
                input.classList.add('invalid');
                icon.classList.add('invalid');
                icon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
            }
        }

        function selectTransaksi(item, fromDropdown = true) {
            selectedTransaksi = item;
            
            // Set nama transaksi
            namaTransaksiInput.value = item.nama_transaksi;
            
            // Set kode akun
            document.getElementById('kode_akun').value = item.kode_akun;
            
            // Tampilkan keterangan default di atas input, tidak auto-fill ke input yang sudah ada
            const keteranganDefault = document.getElementById('keterangan_default');
            const defaultText = document.getElementById('default_text');
            
            if (item.keterangan_default) {
                defaultText.textContent = item.keterangan_default;
                keteranganDefault.classList.add('show');
            } else {
                keteranganDefault.classList.remove('show');
            }
            
            // Set kategori dan umur pakai
            setKategoriAkuntype();
            
            // Update validation state
            updateValidationState(true);
            
            if (fromDropdown) {
                // Set ke readonly mode setelah selection
                namaTransaksiInput.readOnly = true;
                namaTransaksiInput.placeholder = "Klik untuk mengubah nama transaksi";
                
                // Hide search results
                searchResults.classList.remove('show');
                currentHighlightIndex = -1;
            }
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
                    if (umurPakaiInput.value < masterItem.min_umur_pakai) {
                        umurPakaiInput.value = masterItem.min_umur_pakai || 0;
                    }
                    umurPakaiInput.min = masterItem.min_umur_pakai || 0;
                    umurPakaiInput.required = true;
                } else {
                    umurPakaiInput.disabled = true;
                    umurPakaiInput.value = 0;
                    umurPakaiInput.required = false;
                }
            }
        }

        // PERBAIKAN: Form validation dengan pengecekan lebih ketat
        document.getElementById('editPengeluaranForm').addEventListener('submit', function(e) {
            if (!selectedTransaksi || !isValidSelection) {
                e.preventDefault();
                alert('Pilih nama transaksi yang valid terlebih dahulu!');
                namaTransaksiInput.focus();
                return false;
            }
            
            // Validasi nama transaksi ada dalam master data
            const currentValue = namaTransaksiInput.value.trim();
            const isValidTransaksi = masterNamaTransaksi.some(item => 
                item.nama_transaksi.toLowerCase() === currentValue.toLowerCase()
            );
            
            if (!isValidTransaksi) {
                e.preventDefault();
                alert('Nama transaksi tidak valid! Silakan pilih dari daftar yang tersedia.');
                namaTransaksiInput.focus();
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
                searchResults.classList.remove('show');
                currentHighlightIndex = -1;
                if (!namaTransaksiInput.readOnly) {
                    validateCurrentInput();
                }
            }
        });

        // Set kategori akun on page load if an option is selected
        document.addEventListener('DOMContentLoaded', function () {
            setKategoriAkuntype();
            // Set initial validation state
            updateValidationState(true);
        });

        // Format number input
        document.getElementById('jumlah').addEventListener('input', function() {
            // Remove non-numeric characters except for the value itself
            let value = this.value.replace(/[^\d]/g, '');
            this.value = value;
        });
    </script>
</body>
</html>