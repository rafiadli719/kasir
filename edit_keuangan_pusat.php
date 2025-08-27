<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'config.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    die("Akses ditolak. Hanya untuk admin dan super_admin.");
}

$is_super_admin = $_SESSION['role'] === 'super_admin';
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';

// Check if user is super admin
if (!isset($_SESSION['kode_karyawan']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../../login_dashboard/login.php');
    exit();
}

// Get parameters
$id = $_GET['id'] ?? null;
$jenis = $_GET['jenis'] ?? null;

if (!$id || !in_array($jenis, ['pemasukan', 'pengeluaran'])) {
    header('Location: keuangan_pusat.php?tab=riwayat');
    exit();
}

// Get all branches for dropdown
$query_cabang = "SELECT DISTINCT nama_cabang FROM users WHERE nama_cabang IS NOT NULL AND nama_cabang != ''";
$result_cabang = $pdo->query($query_cabang);
$branches = $result_cabang->fetchAll(PDO::FETCH_ASSOC);

// Get master akun based on jenis
if ($jenis === 'pemasukan') {
    $query_akun = "SELECT * FROM master_akun WHERE jenis_akun = 'pemasukan'";
} else {
    $query_akun = "SELECT * FROM master_akun WHERE jenis_akun = 'pengeluaran'";
}
$result_akun = $pdo->query($query_akun);
$master_akun = $result_akun->fetchAll(PDO::FETCH_ASSOC);

// Retrieve master nama transaksi based on jenis
if ($jenis === 'pemasukan') {
    $query_nama_transaksi = "SELECT mnt.*, ma.arti 
                            FROM master_nama_transaksi mnt 
                            JOIN master_akun ma ON mnt.kode_akun = ma.kode_akun 
                            WHERE mnt.status = 'active' AND ma.jenis_akun = 'pemasukan'
                            ORDER BY mnt.nama_transaksi";
} else {
    $query_nama_transaksi = "SELECT mnt.*, ma.arti 
                            FROM master_nama_transaksi mnt 
                            JOIN master_akun ma ON mnt.kode_akun = ma.kode_akun 
                            WHERE mnt.status = 'active' AND ma.jenis_akun = 'pengeluaran'
                            ORDER BY mnt.nama_transaksi";
}
$result_nama_transaksi = $pdo->query($query_nama_transaksi);
$master_nama_transaksi = $result_nama_transaksi->fetchAll(PDO::FETCH_ASSOC);

// Get transaction data
if ($jenis === 'pemasukan') {
    $query = "SELECT * FROM pemasukan_pusat WHERE id = ?";
} else {
    $query = "SELECT * FROM pengeluaran_pusat WHERE id = ?";
}
$stmt = $pdo->prepare($query);
$stmt->execute([$id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    header('Location: keuangan_pusat.php?tab=riwayat');
    exit();
}

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tanggal = $_POST['tanggal'];
    $cabang = $_POST['cabang'];
    $kode_akun = $_POST['kode_akun'];
    $jumlah = $_POST['jumlah'];
    $keterangan = $_POST['keterangan'];
    $umur_pakai = isset($_POST['umur_pakai']) ? intval($_POST['umur_pakai']) : 0;
    
    // Validate inputs
    if (!is_numeric($jumlah) || $jumlah <= 0) {
        $error_message = "Jumlah harus berupa angka positif!";
    } elseif (empty($tanggal)) {
        $error_message = "Tanggal harus diisi!";
    } elseif (empty(trim($keterangan))) {
        $error_message = "Keterangan transaksi wajib diisi!";
    } else {
        if ($jenis === 'pemasukan') {
            // Update pemasukan
            $query = "UPDATE pemasukan_pusat SET 
                      cabang = ?, 
                      kode_akun = ?, 
                      jumlah = ?, 
                      keterangan = ?, 
                      tanggal = ?,
                      updated_at = CURRENT_TIMESTAMP
                      WHERE id = ?";
            $stmt = $pdo->prepare($query);
            
            if ($stmt->execute([$cabang, $kode_akun, $jumlah, $keterangan, $tanggal, $id])) {
                $success_message = "Pemasukan berhasil diupdate!";
                // Refresh transaction data
                $query = "SELECT * FROM pemasukan_pusat WHERE id = ?";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$id]);
                $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $error_message = "Gagal mengupdate pemasukan: " . implode(", ", $stmt->errorInfo());
            }
        } else {
            // Validate umur pakai for pengeluaran
            $query_validasi_umur = "SELECT require_umur_pakai, min_umur_pakai, kategori FROM master_akun WHERE kode_akun = ?";
            $stmt_validasi_umur = $pdo->prepare($query_validasi_umur);
            $stmt_validasi_umur->execute([$kode_akun]);
            $validasi_umur_data = $stmt_validasi_umur->fetch(PDO::FETCH_ASSOC);
            
            if ($validasi_umur_data && $validasi_umur_data['require_umur_pakai'] == 1) {
                if ($umur_pakai < $validasi_umur_data['min_umur_pakai']) {
                    $error_message = "Umur pakai minimal " . $validasi_umur_data['min_umur_pakai'] . " bulan untuk kode akun ini!";
                } else {
                    // Update pengeluaran
                    $kategori = $validasi_umur_data['kategori'];
                    $query = "UPDATE pengeluaran_pusat SET 
                              cabang = ?, 
                              kode_akun = ?, 
                              jumlah = ?, 
                              keterangan = ?, 
                              umur_pakai = ?, 
                              kategori = ?, 
                              tanggal = ?,
                              updated_at = CURRENT_TIMESTAMP
                              WHERE id = ?";
                    $stmt = $pdo->prepare($query);
                    
                    if ($stmt->execute([$cabang, $kode_akun, $jumlah, $keterangan, $umur_pakai, $kategori, $tanggal, $id])) {
                        $success_message = "Pengeluaran berhasil diupdate!";
                        // Refresh transaction data
                        $query = "SELECT * FROM pengeluaran_pusat WHERE id = ?";
                        $stmt = $pdo->prepare($query);
                        $stmt->execute([$id]);
                        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
                    } else {
                        $error_message = "Gagal mengupdate pengeluaran: " . implode(", ", $stmt->errorInfo());
                    }
                }
            } else {
                // Update pengeluaran without umur pakai validation
                $kategori = $validasi_umur_data['kategori'] ?? '';
                $query = "UPDATE pengeluaran_pusat SET 
                          cabang = ?, 
                          kode_akun = ?, 
                          jumlah = ?, 
                          keterangan = ?, 
                          umur_pakai = ?, 
                          kategori = ?, 
                          tanggal = ?,
                          updated_at = CURRENT_TIMESTAMP
                          WHERE id = ?";
                $stmt = $pdo->prepare($query);
                
                if ($stmt->execute([$cabang, $kode_akun, $jumlah, $keterangan, $umur_pakai, $kategori, $tanggal, $id])) {
                    $success_message = "Pengeluaran berhasil diupdate!";
                    // Refresh transaction data
                    $query = "SELECT * FROM pengeluaran_pusat WHERE id = ?";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([$id]);
                    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $error_message = "Gagal mengupdate pengeluaran: " . implode(", ", $stmt->errorInfo());
                }
            }
        }
    }
}

// Cek nama transaksi saat ini berdasarkan kode akun
$current_nama_transaksi = '';
foreach ($master_nama_transaksi as $mnt) {
    if ($mnt['kode_akun'] == $transaction['kode_akun']) {
        $current_nama_transaksi = $mnt['nama_transaksi'];
        break;
    }
}

// Ambil keterangan default untuk menampilkan sebagai hint
$current_keterangan_default = '';
foreach ($master_nama_transaksi as $mnt) {
    if ($mnt['kode_akun'] == $transaction['kode_akun']) {
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
    <title>Edit <?php echo ucfirst($jenis); ?> Pusat - Super Admin</title>
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
            padding: 30px;
            min-height: 100vh;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .header-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .header-card h1 {
            font-size: 24px;
            margin-bottom: 15px;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
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
        .form-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            margin-bottom: 24px;
        }
        .form-label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-left: 10px;
            font-style: italic;
        }
        .form-control, .form-select {
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            background: white;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
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
            background: linear-gradient(135deg, var(--success-color), #20c997);
            color: white;
            box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
        }
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
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
        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
        }
        .btn-secondary:hover {
            background-color: #545b62;
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
        .text-required {
            color: var(--danger-color);
        }
        .form-text {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .transaction-info {
            background: var(--background-light);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .transaction-info h4 {
            margin-bottom: 10px;
            color: var(--text-dark);
            font-size: 16px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        .info-item {
            font-size: 14px;
        }
        .info-label {
            font-weight: 500;
            color: var(--text-muted);
        }
        .info-value {
            color: var(--text-dark);
            font-family: 'Courier New', monospace;
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
            body {
                padding: 20px;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .btn-group {
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

<div class="container">
    <div class="header-card">
        <h1>
            <?php if ($jenis === 'pemasukan'): ?>
                <i class="fas fa-arrow-up" style="color: var(--success-color);"></i> Edit Pemasukan Pusat
            <?php else: ?>
                <i class="fas fa-arrow-down" style="color: var(--danger-color);"></i> Edit Pengeluaran Pusat
            <?php endif; ?>
        </h1>
        <div class="breadcrumb">
            <a href="keuangan_pusat.php"><i class="fas fa-wallet"></i> Keuangan Pusat</a>
            <i class="fas fa-chevron-right"></i>
            <a href="keuangan_pusat.php?tab=riwayat">Riwayat Transaksi</a>
            <i class="fas fa-chevron-right"></i>
            <span>Edit <?php echo ucfirst($jenis); ?></span>
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

    <!-- Transaction Info -->
    <div class="form-card">
        <div class="transaction-info">
            <h4><i class="fas fa-info-circle"></i> Informasi Transaksi</h4>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">ID Transaksi:</div>
                    <div class="info-value">#<?php echo $transaction['id']; ?></div>
                </div>
                <?php if ($jenis === 'pengeluaran' && $transaction['kode_transaksi']): ?>
                <div class="info-item">
                    <div class="info-label">Kode Transaksi:</div>
                    <div class="info-value"><?php echo htmlspecialchars($transaction['kode_transaksi']); ?></div>
                </div>
                <?php endif; ?>
                <div class="info-item">
                    <div class="info-label">Tanggal Dibuat:</div>
                    <div class="info-value"><?php echo date('d/m/Y H:i:s', strtotime($transaction['created_at'])); ?></div>
                </div>
                <?php if (isset($transaction['updated_at']) && $transaction['updated_at'] !== $transaction['created_at']): ?>
                <div class="info-item">
                    <div class="info-label">Terakhir Diupdate:</div>
                    <div class="info-value"><?php echo date('d/m/Y H:i:s', strtotime($transaction['updated_at'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Edit Form -->
        <form method="POST" action="" id="editForm">
            <!-- Nama Transaksi dengan Auto Search -->
            <div class="form-group">
                <label for="nama_transaksi" class="form-label">
                    <i class="fas fa-search"></i> Nama Transaksi
                    <span class="form-hint">Nama transaksi saat ini: <strong><?php echo htmlspecialchars($current_nama_transaksi); ?></strong></span>
                </label>
                <div class="search-container">
                    <input type="text" id="nama_transaksi" class="form-control" value="<?php echo htmlspecialchars($current_nama_transaksi); ?>" readonly onclick="showDropdown()">
                    <div class="search-results" id="search_results">
                        <!-- Results will be populated by JavaScript -->
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="tanggal" class="form-label">
                        <i class="fas fa-calendar"></i> Tanggal Transaksi <span class="text-required">*</span>
                    </label>
                    <input type="date" name="tanggal" id="tanggal" class="form-control" 
                           value="<?php echo htmlspecialchars($transaction['tanggal']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="cabang" class="form-label">
                        <i class="fas fa-building"></i> Pilih Cabang <span class="text-required">*</span>
                    </label>
                    <select name="cabang" id="cabang" class="form-control" required>
                        <option value="">-- Pilih Cabang --</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo htmlspecialchars($branch['nama_cabang']); ?>"
                                <?php echo ($transaction['cabang'] === $branch['nama_cabang']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($branch['nama_cabang'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="kode_akun" class="form-label">
                    <i class="fas fa-tags"></i> Kode Akun <span class="text-required">*</span>
                    <span class="form-hint">Otomatis terisi sesuai dengan nama transaksi yang dipilih.</span>
                </label>
                <select name="kode_akun" id="kode_akun" class="form-control" readonly style="pointer-events: none; background: var(--background-light); cursor: not-allowed;" required 
                        <?php echo ($jenis === 'pengeluaran') ? 'onchange="setKategoriAkuntype()"' : ''; ?>>
                    <option value="">-- Otomatis terisi berdasarkan nama transaksi --</option>
                    <?php foreach ($master_akun as $akun): ?>
                        <option value="<?php echo $akun['kode_akun']; ?>"
                            <?php echo ($transaction['kode_akun'] === $akun['kode_akun']) ? 'selected' : ''; ?>
                            <?php if ($jenis === 'pengeluaran'): ?>
                                data-kategori="<?php echo htmlspecialchars($akun['kategori'] ?? ''); ?>"
                                data-require-umur="<?php echo $akun['require_umur_pakai'] ?? 0; ?>"
                                data-min-umur="<?php echo $akun['min_umur_pakai'] ?? 0; ?>"
                            <?php endif; ?>>
                            <?php echo $akun['kode_akun'] . ' - ' . $akun['arti']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($jenis === 'pengeluaran'): ?>
            <div class="form-group">
                <label for="kategori" class="form-label">
                    <i class="fas fa-list"></i> Kategori Akun
                    <span class="form-hint">Otomatis terisi berdasarkan kode akun yang dipilih.</span>
                </label>
                <input type="text" name="kategori" id="kategori" class="form-control" 
                       value="<?php echo htmlspecialchars($transaction['kategori'] ?? ''); ?>" readonly>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="keterangan" class="form-label">
                    <i class="fas fa-comment"></i> Keterangan <span class="text-required">*</span>
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
                <input type="text" name="keterangan" id="keterangan" class="form-control" 
                       value="<?php echo htmlspecialchars($transaction['keterangan']); ?>"
                       oninput="this.value = this.value.toUpperCase()" required placeholder="Masukkan keterangan">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="jumlah" class="form-label">
                        <i class="fas fa-money-bill-wave"></i> Jumlah (Rp) <span class="text-required">*</span>
                    </label>
                    <input type="number" name="jumlah" id="jumlah" class="form-control" 
                           value="<?php echo $transaction['jumlah']; ?>"
                           step="1" min="1" required placeholder="Masukkan jumlah">
                </div>

                <?php if ($jenis === 'pengeluaran'): ?>
                <div class="form-group">
                    <label for="umur_pakai" class="form-label">
                        <i class="fas fa-clock"></i> Umur Pakai (Bulan)
                        <span class="form-hint">Akan aktif jika kode akun memerlukan umur pakai.</span>
                    </label>
                    <input type="number" name="umur_pakai" id="umur_pakai" class="form-control" 
                           value="<?php echo $transaction['umur_pakai'] ?? 0; ?>"
                           min="0" placeholder="Masukkan umur pakai dalam bulan">
                </div>
                <?php endif; ?>
            </div>

            <div class="btn-group">
                <?php if ($jenis === 'pemasukan'): ?>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Update Pemasukan
                    </button>
                <?php else: ?>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-save"></i> Update Pengeluaran
                    </button>
                <?php endif; ?>
                <a href="keuangan_pusat.php?tab=riwayat" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Kembali ke Riwayat
                </a>
            </div>
        </form>
    </div>
</div>

<script>
    // Master data transaksi untuk auto search
    const masterNamaTransaksi = <?php echo json_encode($master_nama_transaksi); ?>;
    const masterAkun = <?php echo json_encode($master_akun); ?>;
    const jenis = '<?php echo $jenis; ?>';

    let selectedTransaksi = null;

    // Initialize selected transaction based on current data
    selectedTransaksi = masterNamaTransaksi.find(item => item.kode_akun === '<?php echo $transaction['kode_akun']; ?>');

    // Show dropdown when clicking on nama_transaksi field
    function showDropdown() {
        const resultsContainer = document.getElementById('search_results');
        populateDropdown('');
        resultsContainer.style.display = 'block';
    }

    // Populate dropdown with filtered results
    function populateDropdown(query) {
        const resultsContainer = document.getElementById('search_results');
        
        const filteredResults = masterNamaTransaksi.filter(item => 
            item.nama_transaksi.toLowerCase().includes(query.toLowerCase())
        );
        
        resultsContainer.innerHTML = '';
        filteredResults.forEach(item => {
            const div = document.createElement('div');
            div.className = 'search-result-item';
            div.innerHTML = `
                <div class="nama">${item.nama_transaksi}</div>
                <div class="kode">${item.kode_akun} - ${item.arti}</div>
            `;
            div.addEventListener('click', function() {
                selectTransaksi(item);
            });
            resultsContainer.appendChild(div);
        });
    }

    function selectTransaksi(item) {
        selectedTransaksi = item;
        
        // Set nama transaksi
        document.getElementById('nama_transaksi').value = item.nama_transaksi;
        
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
        
        // Set kategori dan umur pakai untuk pengeluaran
        if (jenis === 'pengeluaran') {
            setKategoriAkuntype();
        }
        
        // Hide search results
        document.getElementById('search_results').style.display = 'none';
    }

    <?php if ($jenis === 'pengeluaran'): ?>
    function setKategoriAkuntype() {
        var kodeAkun = document.getElementById("kode_akun");
        var kategoriInput = document.getElementById("kategori");
        var umurPakaiInput = document.getElementById("umur_pakai");
        
        // Set kategori berdasarkan master data
        const masterItem = masterAkun.find(item => item.kode_akun === kodeAkun.value);
        if (masterItem) {
            kategoriInput.value = masterItem.kategori || '';
            
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

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        setKategoriAkuntype();
    });
    <?php endif; ?>

    // Form validation
    document.getElementById('editForm').addEventListener('submit', function(e) {
        if (!selectedTransaksi) {
            e.preventDefault();
            alert('Pilih nama transaksi terlebih dahulu!');
            return false;
        }
        
        // Validate keterangan is filled
        const keterangan = document.getElementById('keterangan').value.trim();
        if (!keterangan) {
            e.preventDefault();
            alert('Keterangan transaksi wajib diisi!');
            document.getElementById('keterangan').focus();
            return false;
        }
        
        // Validate jumlah is positive number
        const jumlah = document.getElementById('jumlah').value;
        if (!jumlah || isNaN(jumlah) || parseFloat(jumlah) <= 0) {
            e.preventDefault();
            alert('Jumlah harus berupa angka positif!');
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

    // Auto-format number inputs
    document.addEventListener('DOMContentLoaded', function() {
        const numberInputs = document.querySelectorAll('input[type="number"]');
        numberInputs.forEach(input => {
            if (input.name === 'jumlah') {
                input.addEventListener('input', function() {
                    // Remove any non-numeric characters except decimal point
                    this.value = this.value.replace(/[^0-9]/g, '');
                });
            }
        });
    });
</script>

</body>
</html>