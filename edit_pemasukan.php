<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'config.php'; // Include your database connection

// Set timezone to local time (e.g., Asia/Jakarta)
date_default_timezone_set('Asia/Jakarta');

// Check if the user is logged in
if (isset($_SESSION['kode_karyawan'])) {
    $kode_karyawan = $_SESSION['kode_karyawan'];
} else {
    die("Kode Karyawan tidak ditemukan di session. Silakan login.");
}

// Get ID from URL
if (isset($_GET['id'])) {
    $id = $_GET['id'];
} else {
    die("Pemasukan ID tidak ditemukan.");
}

// Fetch the pemasukan data based on ID
$sql = "SELECT * FROM pemasukan_kasir WHERE id = :id AND kode_karyawan = :kode_karyawan";
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':id', $id, PDO::PARAM_INT);
$stmt->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt->execute();
$pemasukan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pemasukan) {
    die("Data pemasukan tidak ditemukan.");
}

// Get user's cabang info
$sql_user_cabang = "SELECT kode_cabang, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan LIMIT 1";
$stmt_user_cabang = $pdo->prepare($sql_user_cabang);
$stmt_user_cabang->bindParam(':kode_karyawan', $kode_karyawan);
$stmt_user_cabang->execute();
$user_cabang_data = $stmt_user_cabang->fetch(PDO::FETCH_ASSOC);
$user_nama_cabang = $user_cabang_data['nama_cabang'] ?? '';

// Process form submission for editing pemasukan kasir
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $kode_akun = $_POST['kode_akun'];
    $jumlah = $_POST['jumlah'];
    $keterangan_transaksi = $_POST['keterangan_transaksi'];
    $nomor_transaksi_closing = $_POST['nomor_transaksi_closing'] ?? '';

    // Set tanggal dan waktu otomatis
    $tanggal = date('Y-m-d');  // Tanggal saat ini
    $waktu = date('H:i:s');    // Waktu saat ini

    // Validate that jumlah is numeric and an integer
    if (!is_numeric($jumlah) || floor($jumlah) != $jumlah) {
        echo "<script>alert('Jumlah harus berupa bilangan bulat!');</script>";
        exit;
    }

    // Cek apakah ini transaksi "DARI CLOSING"
    $sql_nama_transaksi = "SELECT mnt.nama_transaksi 
                          FROM master_nama_transaksi mnt 
                          WHERE mnt.kode_akun = :kode_akun AND mnt.status = 'active'";
    $stmt_nama_transaksi = $pdo->prepare($sql_nama_transaksi);
    $stmt_nama_transaksi->bindParam(':kode_akun', $kode_akun);
    $stmt_nama_transaksi->execute();
    $nama_transaksi_data = $stmt_nama_transaksi->fetch(PDO::FETCH_ASSOC);
    $nama_transaksi = $nama_transaksi_data['nama_transaksi'] ?? '';

    // Validasi khusus untuk transaksi "DARI CLOSING"
    if (strtoupper($nama_transaksi) === 'DARI CLOSING') {
        // Validasi nomor transaksi closing wajib diisi
        if (empty($nomor_transaksi_closing)) {
            echo "<script>alert('Nomor Transaksi Closing wajib dipilih untuk transaksi DARI CLOSING!');</script>";
            exit;
        }

        // Ambil nilai setoran dari transaksi closing yang dipilih
        $sql_closing = "SELECT setoran_real FROM kasir_transactions WHERE kode_transaksi = :kode_transaksi AND status = 'end proses'";
        $stmt_closing = $pdo->prepare($sql_closing);
        $stmt_closing->bindParam(':kode_transaksi', $nomor_transaksi_closing);
        $stmt_closing->execute();
        $closing_data = $stmt_closing->fetch(PDO::FETCH_ASSOC);

        if (!$closing_data) {
            echo "<script>alert('Transaksi closing tidak ditemukan atau belum end proses!');</script>";
            exit;
        }

        $setoran_closing = $closing_data['setoran_real'];

        // Validasi jumlah pemasukan tidak boleh lebih besar dari setoran closing
        if ($jumlah > $setoran_closing) {
            echo "<script>alert('Jumlah pemasukan (" . number_format($jumlah, 0, ',', '.') . ") tidak boleh lebih besar dari nilai setoran transaksi closing (" . number_format($setoran_closing, 0, ',', '.') . "). Silakan cek kembali jumlah pemasukan atau nomor transaksi closing yang dipilih.');</script>";
            exit;
        }
    }

    // Validasi keterangan wajib diisi
    if (empty(trim($keterangan_transaksi))) {
        echo "<script>alert('Keterangan transaksi wajib diisi!');</script>";
        exit;
    }

    // Update the record in `pemasukan_kasir`
    $query = "UPDATE pemasukan_kasir SET 
              kode_akun = :kode_akun, 
              jumlah = :jumlah, 
              keterangan_transaksi = :keterangan_transaksi, 
              tanggal = :tanggal, 
              waktu = :waktu, 
              nomor_transaksi_closing = COALESCE(:nomor_transaksi_closing, nomor_transaksi_closing)
              WHERE id = :id AND kode_karyawan = :kode_karyawan";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':kode_akun', $kode_akun, PDO::PARAM_STR);
    $stmt->bindParam(':jumlah', $jumlah, PDO::PARAM_INT);
    $stmt->bindParam(':keterangan_transaksi', $keterangan_transaksi, PDO::PARAM_STR);
    $stmt->bindParam(':tanggal', $tanggal, PDO::PARAM_STR);
    $stmt->bindParam(':waktu', $waktu, PDO::PARAM_STR);
    $stmt->bindParam(':nomor_transaksi_closing', $nomor_transaksi_closing, PDO::PARAM_STR);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);

    if ($stmt->execute()) {
        // Jika transaksi "DARI CLOSING", update bukti_transaksi di kasir_transactions
        if (strtoupper($nama_transaksi) === 'DARI CLOSING' && !empty($nomor_transaksi_closing)) {
            $update_bukti = "UPDATE kasir_transactions SET bukti_transaksi = :pemasukan_id WHERE kode_transaksi = :kode_transaksi";
            $stmt_bukti = $pdo->prepare($update_bukti);
            $stmt_bukti->bindParam(':pemasukan_id', $id, PDO::PARAM_INT);
            $stmt_bukti->bindParam(':kode_transaksi', $nomor_transaksi_closing, PDO::PARAM_STR);
            $stmt_bukti->execute();
        }

        // Redirect to pemasukan_kasir.php after successful update
        echo "<script>alert('Data pemasukan berhasil diperbarui.'); window.location.href='pemasukan_kasir.php?kode_transaksi=" . $pemasukan['kode_transaksi'] . "';</script>";
    } else {
        echo "<script>alert('Terjadi kesalahan saat memperbarui data.');</script>";
    }
}

// Fetch master_akun for dropdown (hanya untuk pemasukan)
$sql_akun = "SELECT * FROM master_akun WHERE jenis_akun = 'pemasukan'";
$result = $pdo->query($sql_akun);
$master_akun = $result->fetchAll(PDO::FETCH_ASSOC);

// Retrieve master nama transaksi untuk pemasukan
$sql_nama_transaksi = "SELECT mnt.*, ma.arti 
                       FROM master_nama_transaksi mnt 
                       JOIN master_akun ma ON mnt.kode_akun = ma.kode_akun 
                       WHERE mnt.status = 'active' AND ma.jenis_akun = 'pemasukan'
                       ORDER BY mnt.nama_transaksi";
$stmt_nama_transaksi = $pdo->query($sql_nama_transaksi);
$master_nama_transaksi = $stmt_nama_transaksi->fetchAll(PDO::FETCH_ASSOC);

// PERBAIKAN: Retrieve transaksi closing yang belum disetor untuk cabang yang sama dengan kasir login
// Dan hanya menampilkan closing yang statusnya 'end proses'
$sql_closing_available = "SELECT kode_transaksi, tanggal_transaksi, setoran_real, kode_karyawan 
                         FROM kasir_transactions 
                         WHERE nama_cabang = :nama_cabang 
                         AND status = 'end proses' 
                         AND (deposit_status IS NULL OR deposit_status = '' OR deposit_status = 'Belum Disetor')
                         ORDER BY tanggal_transaksi DESC";
$stmt_closing_available = $pdo->prepare($sql_closing_available);
$stmt_closing_available->bindParam(':nama_cabang', $user_nama_cabang);
$stmt_closing_available->execute();
$available_closing = $stmt_closing_available->fetchAll(PDO::FETCH_ASSOC);

// Ambil kode_karyawan dan nama_cabang dari kasir_transactions
$sql_user_cabang = "SELECT kode_karyawan, nama_cabang FROM kasir_transactions WHERE kode_transaksi = :kode_transaksi";
$stmt_user_cabang = $pdo->prepare($sql_user_cabang);
$stmt_user_cabang->bindParam(':kode_transaksi', $pemasukan['kode_transaksi']);
$stmt_user_cabang->execute();
$user_cabang_data = $stmt_user_cabang->fetch(PDO::FETCH_ASSOC);

$kode_karyawan_display = $user_cabang_data['kode_karyawan'] ?? 'Tidak diketahui';
$cabang = $user_cabang_data['nama_cabang'] ?? 'Tidak diketahui';

// Ambil nama karyawan dan nama cabang berdasarkan kode_karyawan dari tabel users
$sql_nama_karyawan = "SELECT nama_karyawan, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan";
$stmt_nama_karyawan = $pdo->prepare($sql_nama_karyawan);
$stmt_nama_karyawan->bindParam(':kode_karyawan', $kode_karyawan_display);
$stmt_nama_karyawan->execute();
$nama_karyawan_data = $stmt_nama_karyawan->fetch(PDO::FETCH_ASSOC);

$nama_karyawan = $nama_karyawan_data['nama_karyawan'] ?? 'Tidak diketahui';
$nama_cabang = $nama_karyawan_data['nama_cabang'] ?? 'Tidak diketahui';
// Gabungkan kode_karyawan dan nama_karyawan
$karyawan_info = $kode_karyawan_display . ' - ' . ($nama_karyawan ?? 'Tidak diketahui');

$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$cabang_user = $_SESSION['nama_cabang'] ?? 'Unknown Cabang';

// Cek apakah ini transaksi "DARI CLOSING" berdasarkan data yang ada
$current_nama_transaksi = '';
foreach ($master_nama_transaksi as $mnt) {
    if ($mnt['kode_akun'] == $pemasukan['kode_akun']) {
        $current_nama_transaksi = $mnt['nama_transaksi'];
        break;
    }
}
$is_dari_closing = (strtoupper($current_nama_transaksi) === 'DARI CLOSING');

// PERBAIKAN: Ambil keterangan default untuk menampilkan sebagai hint
$current_keterangan_default = '';
foreach ($master_nama_transaksi as $mnt) {
    if ($mnt['kode_akun'] == $pemasukan['kode_akun']) {
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
    <title>Edit Pemasukan Kasir</title>
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
        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #20c997);
            color: white;
            box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
        }
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }
        .form-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
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
        .closing-info {
            background: #e3f2fd;
            border: 1px solid #1976d2;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            display: none;
        }
        .closing-info.show {
            display: block;
        }
        .closing-info h6 {
            color: #1976d2;
            margin-bottom: 8px;
            font-weight: 600;
        }
        .closing-info .closing-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }
        .closing-detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .closing-detail-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .closing-detail-value {
            font-weight: 600;
            color: var(--text-dark);
        }
        .closing-detail-value.amount {
            color: var(--success-color);
            font-size: 16px;
        }
        .required {
            color: var(--danger-color);
        }
        
        /* PERBAIKAN: Style untuk keterangan default */
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
            <a href="pemasukan_kasir.php?kode_transaksi=<?php echo htmlspecialchars($pemasukan['kode_transaksi']); ?>">Pemasukan Kasir</a>
            <i class="fas fa-chevron-right"></i>
            <span>Edit Pemasukan</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-edit"></i> Edit Pemasukan Kasir</h1>
            <p class="subtitle">Edit dan perbarui data pemasukan kas</p>
            <div class="transaction-code">
                <i class="fas fa-receipt"></i> <?php echo htmlspecialchars($pemasukan['kode_transaksi']); ?>
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
                <div class="label">ID Pemasukan</div>
                <div class="value">#<?php echo htmlspecialchars($id); ?></div>
            </div>
        </div>

        <!-- Form Section -->
        <div class="form-section">
            <form method="POST" action="" id="editPemasukanForm">
                <!-- Nama Transaksi dengan Auto Search - PERBAIKAN -->
                <div class="form-group">
                    <label for="nama_transaksi" class="form-label">
                        <i class="fas fa-search"></i> Nama Transaksi
                        <span class="form-hint">Nama transaksi saat ini: <strong><?php echo htmlspecialchars($current_nama_transaksi); ?></strong></span>
                    </label>
                    <div class="search-container">
                        <div class="input-group">
                            <input type="text" id="nama_transaksi" class="form-control valid" 
                                   value="<?php echo htmlspecialchars($current_nama_transaksi); ?>" 
                                   placeholder="Ketik untuk mencari transaksi pemasukan..." 
                                   autocomplete="off" readonly>
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

                <!-- Kolom Nomor Transaksi Closing -->
                <div class="form-group" id="closing_section" style="display: <?php echo $is_dari_closing ? 'block' : 'none'; ?>;">
                    <label for="nomor_transaksi_closing" class="form-label">
                        <i class="fas fa-list-ol"></i> Nomor Transaksi Closing <span class="required">*</span>
                        <span class="form-hint">Pilih transaksi closing dari cabang Anda yang sudah end proses.</span>
                    </label>
                    <select name="nomor_transaksi_closing" id="nomor_transaksi_closing" class="form-select">
                        <option value="">-- Pilih Transaksi Closing --</option>
                        <?php foreach ($available_closing as $closing): ?>
                            <option value="<?php echo htmlspecialchars($closing['kode_transaksi']); ?>" 
                                    data-setoran="<?php echo $closing['setoran_real']; ?>"
                                    data-tanggal="<?php echo date('d/m/Y', strtotime($closing['tanggal_transaksi'])); ?>"
                                    data-karyawan="<?php echo htmlspecialchars($closing['kode_karyawan']); ?>"
                                    <?php echo ($pemasukan['nomor_transaksi_closing'] == $closing['kode_transaksi']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($closing['kode_transaksi']); ?> - 
                                <?php echo date('d/m/Y', strtotime($closing['tanggal_transaksi'])); ?> - 
                                Kasir: <?php echo htmlspecialchars($closing['kode_karyawan']); ?> - 
                                Rp <?php echo number_format($closing['setoran_real'], 0, ',', '.'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Info Closing yang Dipilih -->
                <div class="closing-info <?php echo ($is_dari_closing && $pemasukan['nomor_transaksi_closing']) ? 'show' : ''; ?>" id="closing_info">
                    <h6><i class="fas fa-info-circle"></i> Informasi Transaksi Closing</h6>
                    <div class="closing-details">
                        <div class="closing-detail-item">
                            <span class="closing-detail-label">Kode Transaksi:</span>
                            <span class="closing-detail-value" id="closing_kode"><?php echo htmlspecialchars($pemasukan['nomor_transaksi_closing'] ?? '-'); ?></span>
                        </div>
                        <div class="closing-detail-item">
                            <span class="closing-detail-label">Tanggal:</span>
                            <span class="closing-detail-value" id="closing_tanggal">-</span>
                        </div>
                        <div class="closing-detail-item">
                            <span class="closing-detail-label">Kasir Closing:</span>
                            <span class="closing-detail-value" id="closing_karyawan">-</span>
                        </div>
                        <div class="closing-detail-item">
                            <span class="closing-detail-label">Nilai Setoran:</span>
                            <span class="closing-detail-value amount" id="closing_setoran">-</span>
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
                            <option value="<?php echo $akun['kode_akun']; ?>" <?php if ($akun['kode_akun'] == $pemasukan['kode_akun']) echo 'selected'; ?>>
                                <?php echo $akun['kode_akun'] . ' - ' . $akun['arti']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="jumlah" class="form-label">
                        <i class="fas fa-money-bill-wave"></i> Jumlah (Rp) <span class="required">*</span>
                        <span class="form-hint">Masukkan jumlah dalam rupiah (bilangan bulat).</span>
                    </label>
                    <input type="number" name="jumlah" id="jumlah" class="form-control" value="<?php echo $pemasukan['jumlah']; ?>" step="1" required>
                </div>

                <div class="form-group">
                    <label for="keterangan_transaksi" class="form-label">
                        <i class="fas fa-sticky-note"></i> Keterangan Transaksi <span class="required">*</span>
                        <span class="form-hint">Keterangan wajib diisi untuk melengkapi data transaksi.</span>
                    </label>
                    <!-- PERBAIKAN: Tampilkan keterangan default di atas input tanpa auto-fill -->
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
                    <input type="text" name="keterangan_transaksi" id="keterangan_transaksi" class="form-control" value="<?php echo htmlspecialchars($pemasukan['keterangan_transaksi']); ?>" oninput="this.value = this.value.toUpperCase()" required>
                </div>

                <button type="submit" class="btn btn-success" style="width: 100%;" id="submit_btn">
                    <i class="fas fa-save"></i> Perbarui Data Pemasukan
                </button>
            </form>
        </div>
    </div>

    <script>
        // Master data transaksi untuk auto search (PEMASUKAN)
        const masterNamaTransaksi = <?php echo json_encode($master_nama_transaksi); ?>;
        const masterAkun = <?php echo json_encode($master_akun); ?>;
        const isDariClosing = <?php echo $is_dari_closing ? 'true' : 'false'; ?>;

        let selectedTransaksi = null;
        let currentHighlightIndex = -1;
        let filteredResults = [];
        let isValidSelection = true; // Track if current selection is valid

        // Initialize selected transaction based on current data
        if (isDariClosing) {
            selectedTransaksi = masterNamaTransaksi.find(item => item.nama_transaksi.toUpperCase() === 'DARI CLOSING');
        } else {
            selectedTransaksi = masterNamaTransaksi.find(item => item.kode_akun === '<?php echo $pemasukan['kode_akun']; ?>');
        }

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
                this.placeholder = "Ketik untuk mencari transaksi pemasukan...";
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

        // PERBAIKAN: Function untuk validasi input saat ini
        function validateCurrentInput() {
            const currentValue = namaTransaksiInput.value.trim();
            const isValid = masterNamaTransaksi.some(item => 
                item.nama_transaksi.toLowerCase() === currentValue.toLowerCase()
            );
            
            if (isValid) {
                const matchedItem = masterNamaTransaksi.find(item => 
                    item.nama_transaksi.toLowerCase() === currentValue.toLowerCase()
                );
                selectTransaksi(matchedItem, false); // false = tidak dari dropdown click
            } else {
                // Reset ke nilai sebelumnya jika tidak valid
                if (selectedTransaksi) {
                    namaTransaksiInput.value = selectedTransaksi.nama_transaksi;
                    updateValidationState(true);
                } else {
                    namaTransaksiInput.value = '';
                    updateValidationState(false);
                }
            }
            
            // Set kembali ke readonly mode
            namaTransaksiInput.readOnly = true;
            namaTransaksiInput.placeholder = "Klik untuk mengubah nama transaksi";
            searchResults.classList.remove('show');
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
            
            // PERBAIKAN: Tampilkan keterangan default di atas input, tidak auto-fill ke input yang sudah ada
            const keteranganDefault = document.getElementById('keterangan_default');
            const defaultText = document.getElementById('default_text');
            
            if (item.keterangan_default) {
                defaultText.textContent = item.keterangan_default;
                keteranganDefault.classList.add('show');
            } else {
                keteranganDefault.classList.remove('show');
            }
            
            // Show/hide closing section for "DARI CLOSING"
            const closingSection = document.getElementById('closing_section');
            const closingInfo = document.getElementById('closing_info');
            
            if (item.nama_transaksi.toUpperCase() === 'DARI CLOSING') {
                closingSection.style.display = 'block';
                document.getElementById('nomor_transaksi_closing').required = true;
            } else {
                closingSection.style.display = 'none';
                closingInfo.classList.remove('show');
                document.getElementById('nomor_transaksi_closing').required = false;
                document.getElementById('nomor_transaksi_closing').value = '';
            }
            
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

        // Handle closing transaction selection
        document.getElementById('nomor_transaksi_closing').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const closingInfo = document.getElementById('closing_info');
            
            if (this.value) {
                const setoran = selectedOption.dataset.setoran;
                const tanggal = selectedOption.dataset.tanggal;
                const karyawan = selectedOption.dataset.karyawan;
                const kode = this.value;
                
                document.getElementById('closing_kode').textContent = kode;
                document.getElementById('closing_tanggal').textContent = tanggal;
                document.getElementById('closing_karyawan').textContent = karyawan;
                document.getElementById('closing_setoran').textContent = 'Rp ' + parseInt(setoran).toLocaleString('id-ID');
                
                closingInfo.classList.add('show');
                
                // Validate current jumlah input
                validateJumlahClosing();
            } else {
                closingInfo.classList.remove('show');
            }
        });

        // Validate jumlah for "DARI CLOSING" transactions
        document.getElementById('jumlah').addEventListener('input', function() {
            if (selectedTransaksi && selectedTransaksi.nama_transaksi.toUpperCase() === 'DARI CLOSING') {
                validateJumlahClosing();
            }
        });

        function validateJumlahClosing() {
            const nomorClosing = document.getElementById('nomor_transaksi_closing').value;
            const jumlah = parseInt(document.getElementById('jumlah').value) || 0;
            
            if (nomorClosing && jumlah > 0) {
                const selectedOption = document.getElementById('nomor_transaksi_closing').options[document.getElementById('nomor_transaksi_closing').selectedIndex];
                const setoranClosing = parseInt(selectedOption.dataset.setoran) || 0;
                
                if (jumlah > setoranClosing) {
                    alert(`Jumlah pemasukan (${jumlah.toLocaleString('id-ID')}) tidak boleh lebih besar dari nilai setoran transaksi closing (${setoranClosing.toLocaleString('id-ID')}). Silakan cek kembali jumlah pemasukan atau nomor transaksi closing yang dipilih.`);
                    document.getElementById('submit_btn').disabled = true;
                    document.getElementById('jumlah').style.borderColor = 'var(--danger-color)';
                } else {
                    document.getElementById('submit_btn').disabled = false;
                    document.getElementById('jumlah').style.borderColor = 'var(--border-color)';
                }
            }
        }

        // PERBAIKAN: Form validation dengan pengecekan lebih ketat
        document.getElementById('editPemasukanForm').addEventListener('submit', function(e) {
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
            
            const namaTransaksi = selectedTransaksi.nama_transaksi;
            const jumlah = parseInt(document.getElementById('jumlah').value);
            const nomorClosing = document.getElementById('nomor_transaksi_closing').value;
            
            if (namaTransaksi.toUpperCase() === 'DARI CLOSING') {
                if (!nomorClosing) {
                    e.preventDefault();
                    alert('Nomor Transaksi Closing wajib dipilih untuk transaksi DARI CLOSING!');
                    return false;
                }
                
                const selectedOption = document.getElementById('nomor_transaksi_closing').options[document.getElementById('nomor_transaksi_closing').selectedIndex];
                const setoranClosing = parseInt(selectedOption.dataset.setoran);
                
                if (jumlah > setoranClosing) {
                    e.preventDefault();
                    alert(`Jumlah pemasukan (${jumlah.toLocaleString('id-ID')}) tidak boleh lebih besar dari nilai setoran transaksi closing (${setoranClosing.toLocaleString('id-ID')}). Silakan cek kembali jumlah pemasukan atau nomor transaksi closing yang dipilih.`);
                    return false;
                }
            }
            
            // Validate keterangan is filled
            const keterangan = document.getElementById('keterangan_transaksi').value.trim();
            if (!keterangan) {
                e.preventDefault();
                alert('Keterangan transaksi wajib diisi!');
                document.getElementById('keterangan_transaksi').focus();
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

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Set initial validation state
            updateValidationState(true);
            
            // Set initial closing info if this is a DARI CLOSING transaction
            if (isDariClosing) {
                const closingSelect = document.getElementById('nomor_transaksi_closing');
                if (closingSelect.value) {
                    // Trigger change event to populate closing info
                    closingSelect.dispatchEvent(new Event('change'));
                }
                closingSelect.required = true;
            }
        });
    </script>
</body>
</html>