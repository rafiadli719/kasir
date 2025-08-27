<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    die("Akses ditolak. Hanya untuk admin dan super_admin.");
}

$is_super_admin = $_SESSION['role'] === 'super_admin';
$is_admin = $_SESSION['role'] === 'admin';
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User';

// Koneksi ke database
$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Set variabel untuk filter dan sorting
$tanggal_awal = $_GET['tanggal_awal'] ?? null;
$tanggal_akhir = $_GET['tanggal_akhir'] ?? null;
$cabang = $_GET['cabang'] ?? null;

// Variabel untuk sorting
$sort_by = $_GET['sort_by'] ?? 'tanggal_transaksi';
$sort_order = $_GET['sort_order'] ?? 'DESC';

// Validasi sort_by untuk keamanan
$allowed_sort_columns = [
    'tanggal_transaksi', 'nama_cabang', 'nama_karyawan', 
    'omset_penjualan', 'omset_servis_selisih', 'total_omset'
];

if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'tanggal_transaksi';
}

// Validasi sort_order
if (!in_array(strtoupper($sort_order), ['ASC', 'DESC'])) {
    $sort_order = 'DESC';
}

// Function to generate sort URL
function getSortUrl($column, $current_sort_by, $current_sort_order) {
    $params = $_GET;
    $params['sort_by'] = $column;
    
    // Toggle sort order if clicking on the same column
    if ($column === $current_sort_by) {
        $params['sort_order'] = ($current_sort_order === 'ASC') ? 'DESC' : 'ASC';
    } else {
        $params['sort_order'] = 'ASC';
    }
    
    return '?' . http_build_query($params);
}

// Function to get sort icon
function getSortIcon($column, $current_sort_by, $current_sort_order) {
    if ($column === $current_sort_by) {
        return ($current_sort_order === 'ASC') ? '▲' : '▼';
    }
    return '';
}

// Query untuk mendapatkan daftar cabang
$cabang_list = [];
try {
    $sql_cabang = "SELECT DISTINCT nama_cabang FROM kasir_transactions WHERE nama_cabang IS NOT NULL ORDER BY nama_cabang";
    $stmt_cabang = $pdo->query($sql_cabang);
    $cabang_list = $stmt_cabang->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error silently
}

// Query untuk mendapatkan data omset dengan perhitungan selisih closing
$query = "
    SELECT 
        kt.tanggal_transaksi,
        kt.nama_cabang,
        COALESCE(mk.nama_karyawan, 'Unknown') as nama_karyawan,
        COALESCE(dp.jumlah_penjualan, 0) as omset_penjualan,
        COALESCE(ds.jumlah_servis, 0) as omset_servis_base,
        -- Hitung selisih dari pemasukan_kasir closing
        COALESCE(
            (SELECT SUM(pk.jumlah) 
             FROM pemasukan_kasir pk 
             WHERE pk.nomor_transaksi_closing = kt.kode_transaksi 
             AND pk.jumlah < 0), 0
        ) as selisih_closing,
        -- Total omset servis + selisih closing
        (COALESCE(ds.jumlah_servis, 0) + 
         COALESCE(
            (SELECT SUM(pk.jumlah) 
             FROM pemasukan_kasir pk 
             WHERE pk.nomor_transaksi_closing = kt.kode_transaksi 
             AND pk.jumlah < 0), 0
        )) as omset_servis_selisih,
        -- Total omset keseluruhan
        (COALESCE(dp.jumlah_penjualan, 0) + 
         COALESCE(ds.jumlah_servis, 0) + 
         COALESCE(
            (SELECT SUM(pk.jumlah) 
             FROM pemasukan_kasir pk 
             WHERE pk.nomor_transaksi_closing = kt.kode_transaksi 
             AND pk.jumlah < 0), 0
        )) as total_omset
    FROM kasir_transactions kt
    LEFT JOIN data_penjualan dp ON kt.kode_transaksi = dp.kode_transaksi
    LEFT JOIN data_servis ds ON kt.kode_transaksi = ds.kode_transaksi
    LEFT JOIN masterkeys mk ON kt.kode_karyawan = mk.kode_karyawan
    WHERE kt.status = 'end proses'
";

// Tambahkan filter
$params = [];
if ($tanggal_awal && $tanggal_akhir) {
    $query .= " AND kt.tanggal_transaksi BETWEEN :tanggal_awal AND :tanggal_akhir";
    $params[':tanggal_awal'] = $tanggal_awal;
    $params[':tanggal_akhir'] = $tanggal_akhir;
}
if ($cabang) {
    $query .= " AND kt.nama_cabang = :cabang";
    $params[':cabang'] = $cabang;
}

// Tambahkan sorting
$query .= " ORDER BY {$sort_by} " . strtoupper($sort_order);

// Add secondary sort if not sorting by tanggal_transaksi
if ($sort_by !== 'tanggal_transaksi') {
    $query .= ", tanggal_transaksi " . strtoupper($sort_order);
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$omset_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_records = count($omset_data);
$total_omset_penjualan = array_sum(array_column($omset_data, 'omset_penjualan'));
$total_omset_servis_selisih = array_sum(array_column($omset_data, 'omset_servis_selisih'));
$total_omset_keseluruhan = array_sum(array_column($omset_data, 'total_omset'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Omset - Admin Dashboard</title>
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--primary-color);
        }
        
        .stats-card.success {
            border-left-color: var(--success-color);
        }
        
        .stats-card.info {
            border-left-color: var(--info-color);
        }
        
        .stats-card.danger {
            border-left-color: var(--danger-color);
        }
        
        .stats-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .stats-info h4 {
            margin: 0 0 5px 0;
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 500;
        }
        
        .stats-info .stats-number {
            font-size: 18px;
            font-weight: bold;
            margin: 0;
            color: var(--text-dark);
        }
        
        .stats-icon {
            font-size: 28px;
            opacity: 0.7;
            color: var(--primary-color);
        }
        
        .stats-card.success .stats-icon {
            color: var(--success-color);
        }
        
        .stats-card.info .stats-icon {
            color: var(--info-color);
        }
        
        .stats-card.danger .stats-icon {
            color: var(--danger-color);
        }
        
        .filter-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        
        .filter-card h3 {
            margin-bottom: 20px;
            color: var(--text-dark);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        
        .filter-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid var(--border-color);
        }
        
        .table-header {
            background: var(--background-light);
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        
        .sort-info {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .table-wrapper {
            overflow-x: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--border-color) transparent;
        }
        
        .table-wrapper::-webkit-scrollbar {
            height: 8px;
        }
        
        .table-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        .table-wrapper::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 4px;
        }
        
        .table-wrapper::-webkit-scrollbar-thumb:hover {
            background: var(--secondary-color);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        .table th {
            background: var(--background-light);
            padding: 14px 12px;
            text-align: left;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 12px;
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s ease;
            position: relative;
            vertical-align: middle;
        }
        
        .table th:hover {
            background: #e2e8f0;
        }
        
        .table th.sortable {
            padding-right: 25px;
        }
        
        .table th .sort-icon {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            color: var(--primary-color);
            font-weight: bold;
        }
        
        .table th.active {
            background: rgba(0,123,255,0.1);
            color: var(--primary-color);
        }
        
        .table td {
            padding: 12px 12px;
            border-bottom: 1px solid var(--border-color);
            font-size: 12px;
            white-space: nowrap;
            vertical-align: middle;
        }
        
        .table tbody tr {
            transition: background-color 0.2s ease;
            cursor: pointer;
        }
        
        .table tbody tr:nth-child(even) {
            background-color: rgba(248, 249, 250, 0.5);
        }
        
        .table tbody tr:hover {
            background-color: rgba(0, 123, 255, 0.05) !important;
            transform: scale(1.001);
        }
        
        .table tbody tr.selected {
            background-color: rgba(0, 123, 255, 0.1) !important;
            border-left: 3px solid var(--primary-color);
        }
        
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .table tfoot {
            background: var(--background-light);
            font-weight: 600;
        }
        
        .amount-cell {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            text-align: right;
            font-size: 11px;
        }
        
        .amount-penjualan {
            color: var(--success-color);
        }
        
        .amount-servis {
            color: var(--info-color);
        }
        
        .amount-selisih {
            color: var(--warning-color);
        }
        
        .amount-selisih.negative {
            color: var(--danger-color);
        }
        
        .amount-total {
            color: var(--primary-color);
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border: 1px solid transparent;
        }
        
        .alert-info {
            background: rgba(23,162,184,0.1);
            color: var(--info-color);
            border-color: rgba(23,162,184,0.2);
        }
        
        .alert-success {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
            border-color: rgba(40,167,69,0.2);
        }
        
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1002;
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        
        .mobile-menu-toggle:hover {
            background: #0056b3;
            transform: scale(1.05);
        }
        
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }
        
        @media (max-width: 768px) {
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .sidebar-overlay.active {
                display: block;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .info-tags {
                flex-direction: column;
                gap: 10px;
            }
            
            .filter-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .table-header {
                flex-direction: column;
                gap: 10px;
                align-items: stretch;
                padding: 15px;
            }
            
            .mobile-menu-toggle {
                display: block;
            }
            
            .welcome-card h1 {
                font-size: 20px;
            }
            
            .stats-card {
                padding: 15px;
            }
            
            .stats-info .stats-number {
                font-size: 16px;
            }
            
            .table th, .table td {
                padding: 8px 6px;
                font-size: 11px;
            }
            
            .amount-cell {
                font-size: 10px;
            }
        }
    </style>
</head>
<body>

<!-- Mobile Menu Toggle -->
<button class="mobile-menu-toggle" id="mobileMenuToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar Overlay for Mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

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
        <h1><i class="fas fa-chart-line"></i> Detail Omset Penjualan & Servis</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Monitor dan analisis data omset penjualan dan servis termasuk selisih dari closing dengan data yang akurat</p>
        <div class="info-tags">
            <div class="info-tag"><i class="fas fa-user"></i> User: <?php echo htmlspecialchars($username); ?></div>
            <div class="info-tag"><i class="fas fa-shield-alt"></i> Role: <?php echo htmlspecialchars(ucfirst($role)); ?></div>
            <div class="info-tag"><i class="fas fa-calendar-day"></i> Tanggal: <?php echo date('d M Y'); ?></div>
            <div class="info-tag"><i class="fas fa-sort"></i> Sort: <?php echo ucfirst($sort_by) . ' ' . $sort_order; ?></div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stats-card">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Total Transaksi</h4>
                    <p class="stats-number"><?php echo number_format($total_records); ?></p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-list"></i>
                </div>
            </div>
        </div>
        <div class="stats-card success">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Total Omset Penjualan</h4>
                    <p class="stats-number">Rp <?php echo number_format($total_omset_penjualan, 0, ',', '.'); ?></p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-shopping-cart"></i>
                </div>
            </div>
        </div>
        <div class="stats-card info">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Total Omset Servis + Selisih</h4>
                    <p class="stats-number">Rp <?php echo number_format($total_omset_servis_selisih, 0, ',', '.'); ?></p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-tools"></i>
                </div>
            </div>
        </div>
        <div class="stats-card danger">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Total Omset Keseluruhan</h4>
                    <p class="stats-number">Rp <?php echo number_format($total_omset_keseluruhan, 0, ',', '.'); ?></p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-chart-pie"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Sort Order Alert -->
    <?php if (count($omset_data) > 0): ?>
        <div class="alert alert-success">
            <i class="fas fa-sort"></i>
            <strong>Sorting Aktif:</strong> Data diurutkan berdasarkan <strong><?php echo ucfirst($sort_by); ?></strong> 
            secara <strong><?php echo ($sort_order === 'ASC') ? 'Ascending (A-Z, Kecil-Besar)' : 'Descending (Z-A, Besar-Kecil)'; ?></strong>.
            Klik header kolom untuk mengubah urutan.
        </div>
    <?php endif; ?>

    <!-- Filter Card -->
    <div class="filter-card">
        <h3><i class="fas fa-filter"></i> Filter Data Omset</h3>
        <form method="GET" action="">
            <!-- Preserve sorting parameters -->
            <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sort_by); ?>">
            <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sort_order); ?>">
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="tanggal_awal" class="form-label">
                        <i class="fas fa-calendar-alt"></i> Tanggal Awal Transaksi
                    </label>
                    <input type="date"
                           name="tanggal_awal"
                           id="tanggal_awal"
                           value="<?php echo htmlspecialchars($tanggal_awal ?? '', ENT_QUOTES); ?>"
                           class="form-control">
                </div>
                <div class="form-group">
                    <label for="tanggal_akhir" class="form-label">
                        <i class="fas fa-calendar-alt"></i> Tanggal Akhir Transaksi
                    </label>
                    <input type="date"
                           name="tanggal_akhir"
                           id="tanggal_akhir"
                           value="<?php echo htmlspecialchars($tanggal_akhir ?? '', ENT_QUOTES); ?>"
                           class="form-control">
                </div>
                <div class="form-group">
                    <label for="cabang" class="form-label">
                        <i class="fas fa-building"></i> Nama Cabang
                    </label>
                    <select name="cabang" id="cabang" class="form-control">
                        <option value="">-- Semua Cabang --</option>
                        <?php foreach ($cabang_list as $cabang_item): ?>
                            <option value="<?php echo htmlspecialchars($cabang_item['nama_cabang']); ?>"
                                 <?php echo isset($_GET['cabang']) && $_GET['cabang'] == $cabang_item['nama_cabang'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($cabang_item['nama_cabang'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Cari Data
                </button>
                <a href="detail_omset.php" class="btn btn-secondary">
                    <i class="fas fa-refresh"></i> Reset Filter
                </a>
                <?php if (count($omset_data) > 0): ?>
                    <a href="export_excel_omset.php?<?php echo http_build_query($_GET); ?>" 
                       class="btn btn-success excel-download-btn"
                       id="downloadExcelBtn"
                       data-record-count="<?php echo $total_records; ?>"
                       title="Download data omset dalam format Excel">
                        <i class="fas fa-file-excel"></i> Unduh Excel
                        <span class="download-info">(<?php echo number_format($total_records); ?> transaksi)</span>
                    </a>
                <?php else: ?>
                    <button type="button" class="btn btn-success" disabled style="opacity: 0.5; cursor: not-allowed;">
                        <i class="fas fa-file-excel"></i> Unduh Excel
                        <span class="download-info">(Tidak ada data)</span>
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Data Table -->
    <?php if (count($omset_data) > 0): ?>
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-table"></i> Data Omset Penjualan & Servis</h3>
                <div style="display: flex; gap: 15px; align-items: center;">
                    <div class="sort-info">
                        <i class="fas fa-sort"></i> 
                        <?php echo ucfirst($sort_by) . ' ' . (($sort_order === 'ASC') ? '▲' : '▼'); ?>
                    </div>
                    <div style="font-size: 14px; color: var(--text-muted);">
                        Menampilkan <?php echo number_format(count($omset_data)); ?> transaksi
                    </div>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th class="sortable <?php echo ($sort_by === 'tanggal_transaksi') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('tanggal_transaksi', $sort_by, $sort_order); ?>'">
                                Tanggal Transaksi
                                <span class="sort-icon"><?php echo getSortIcon('tanggal_transaksi', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'nama_cabang') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('nama_cabang', $sort_by, $sort_order); ?>'">
                                Nama Cabang
                                <span class="sort-icon"><?php echo getSortIcon('nama_cabang', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'nama_karyawan') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('nama_karyawan', $sort_by, $sort_order); ?>'">
                                Nama Karyawan
                                <span class="sort-icon"><?php echo getSortIcon('nama_karyawan', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'omset_penjualan') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('omset_penjualan', $sort_by, $sort_order); ?>'">
                                Omset Penjualan (Rp)
                                <span class="sort-icon"><?php echo getSortIcon('omset_penjualan', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'omset_servis_selisih') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('omset_servis_selisih', $sort_by, $sort_order); ?>'">
                                Omset Servis + Selisih Closing (Rp)
                                <span class="sort-icon"><?php echo getSortIcon('omset_servis_selisih', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'total_omset') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('total_omset', $sort_by, $sort_order); ?>'">
                                Total Omset (Rp)
                                <span class="sort-icon"><?php echo getSortIcon('total_omset', $sort_by, $sort_order); ?></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($omset_data as $index => $data): ?>
                            <tr>
                                <td><strong><?php echo $index + 1; ?></strong></td>
                                <td><?php echo date('d/m/Y', strtotime($data['tanggal_transaksi'])); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($data['nama_cabang']), ENT_QUOTES); ?></td>
                                <td>
                                    <?php if ($data['nama_karyawan'] && $data['nama_karyawan'] !== 'Unknown'): ?>
                                        <?php echo htmlspecialchars($data['nama_karyawan'], ENT_QUOTES); ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-style: italic;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="amount-cell amount-penjualan">
                                    Rp <?php echo number_format($data['omset_penjualan'], 0, ',', '.'); ?>
                                </td>
                                <td class="amount-cell amount-servis">
                                    Rp <?php echo number_format($data['omset_servis_selisih'], 0, ',', '.'); ?>
                                    <?php if ($data['selisih_closing'] != 0): ?>
                                        <br><small class="amount-selisih <?php echo $data['selisih_closing'] < 0 ? 'negative' : ''; ?>">
                                            (Base: Rp <?php echo number_format($data['omset_servis_base'], 0, ',', '.'); ?>
                                            + Selisih: Rp <?php echo number_format($data['selisih_closing'], 0, ',', '.'); ?>)
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td class="amount-cell amount-total">
                                    <strong>Rp <?php echo number_format($data['total_omset'], 0, ',', '.'); ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" style="text-align: right;"><strong>Total Keseluruhan:</strong></td>
                            <td class="amount-cell amount-penjualan">
                                <strong>Rp <?php echo number_format($total_omset_penjualan, 0, ',', '.'); ?></strong>
                            </td>
                            <td class="amount-cell amount-servis">
                                <strong>Rp <?php echo number_format($total_omset_servis_selisih, 0, ',', '.'); ?></strong>
                            </td>
                            <td class="amount-cell amount-total">
                                <strong>Rp <?php echo number_format($total_omset_keseluruhan, 0, ',', '.'); ?></strong>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-table"></i> Data Omset Penjualan & Servis</h3>
            </div>
            <div class="no-data">
                <i class="fas fa-search"></i><br>
                <strong>Tidak ada data omset</strong><br>
                untuk filter yang dipilih
            </div>
        </div>
    <?php endif; ?>

    <?php if (count($omset_data) > 0): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Keterangan Perhitungan Omset:</strong>
                <div style="margin-top: 8px; font-size: 13px; line-height: 1.4;">
                    <div><strong>• Omset Penjualan:</strong> Data yang diisikan oleh CS dari transaksi penjualan</div>
                    <div><strong>• Omset Servis + Selisih Closing:</strong> Data servis + selisih dari pemasukan closing (bisa negatif)</div>
                    <div><strong>• Total Omset:</strong> Penjualan + Servis + Selisih Closing (untuk perhitungan rugi laba)</div>
                    <div><strong>• Selisih Closing:</strong> Pengambilan uang dari closing yang tercatat di pemasukan kasir</div>
                </div>
                <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid rgba(23,162,184,0.2); font-size: 12px;">
                    💡 Klik pada header kolom untuk mengurutkan data. Klik baris data untuk memilih transaksi.
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<script>
    // Mobile menu toggle
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    
    if (mobileMenuToggle && sidebar && sidebarOverlay) {
        mobileMenuToggle.addEventListener('click', function() {
            const isActive = sidebar.classList.contains('active');
            
            if (isActive) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                this.innerHTML = '<i class="fas fa-bars"></i>';
            } else {
                sidebar.classList.add('active');
                sidebarOverlay.classList.add('active');
                this.innerHTML = '<i class="fas fa-times"></i>';
            }
        });
        
        // Close sidebar when clicking overlay
        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
            mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && 
                !sidebar.contains(e.target) && 
                !mobileMenuToggle.contains(e.target) && 
                sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });
    }

    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const tanggalAwal = document.getElementById('tanggal_awal').value;
        const tanggalAkhir = document.getElementById('tanggal_akhir').value;
        
        if (tanggalAwal && tanggalAkhir && new Date(tanggalAwal) > new Date(tanggalAkhir)) {
            e.preventDefault();
            alert('Tanggal awal tidak boleh lebih besar dari tanggal akhir!');
            return false;
        }
    });

    // Add hover effect for sortable columns and table interactions
    document.addEventListener('DOMContentLoaded', function() {
        const sortableHeaders = document.querySelectorAll('.table th.sortable');
        
        sortableHeaders.forEach(header => {
            header.style.cursor = 'pointer';
            
            header.addEventListener('mouseenter', function() {
                if (!this.classList.contains('active')) {
                    this.style.backgroundColor = '#e2e8f0';
                }
            });
            
            header.addEventListener('mouseleave', function() {
                if (!this.classList.contains('active')) {
                    this.style.backgroundColor = '';
                }
            });
        });
        
        // Initialize tooltips for truncated text
        const cells = document.querySelectorAll('.table td');
        cells.forEach(cell => {
            if (cell.scrollWidth > cell.clientWidth) {
                cell.title = cell.textContent;
            }
        });

        // Enhanced table interactions
        const tableRows = document.querySelectorAll('.table tbody tr');
        tableRows.forEach(row => {
            row.addEventListener('click', function() {
                // Remove previous selections
                tableRows.forEach(r => r.classList.remove('selected'));
                // Add selection to current row
                this.classList.add('selected');
            });
        });
    });
</script>

</body>
</html>