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
$jenis_data = $_GET['jenis_data'] ?? 'semua';

// Variabel untuk sorting
$sort_by = $_GET['sort_by'] ?? 'tanggal';
$sort_order = $_GET['sort_order'] ?? 'DESC';

// Validasi sort_by untuk keamanan
$allowed_sort_columns = [
    'tanggal', 'waktu', 'kode_transaksi', 'nama_cabang', 'kategori_akun',
    'nama_akun', 'tanggal_transaksi', 'kode_akun', 'keterangan_akun', 'jumlah', 'jenis_sumber'
];

if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'tanggal';
}

// Validasi sort_order
if (!in_array(strtoupper($sort_order), ['ASC', 'DESC'])) {
    $sort_order = 'DESC';
}

// Function to extract transaction date from kode_transaksi
function extractTransactionDate($kode_transaksi) {
    if (preg_match('/PMK-(\d{4})(\d{2})(\d{2})-/', $kode_transaksi, $matches)) {
        return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
    }
    if (preg_match('/TRX-(\d{4})(\d{2})(\d{2})-/', $kode_transaksi, $matches)) {
        return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
    }
    return '-';
}

// Function to generate sort URL
function getSortUrl($column, $current_sort_by, $current_sort_order) {
    $params = $_GET;
    $params['sort_by'] = $column;
    
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
    if ($jenis_data === 'semua' || $jenis_data === 'kasir') {
        $sql_cabang_kasir = "SELECT DISTINCT nama_cabang FROM kasir_transactions WHERE nama_cabang IS NOT NULL";
        $stmt_cabang_kasir = $pdo->query($sql_cabang_kasir);
        $cabang_kasir = $stmt_cabang_kasir->fetchAll(PDO::FETCH_ASSOC);
        $cabang_list = array_merge($cabang_list, $cabang_kasir);
    }
    
    if (($jenis_data === 'semua' || $jenis_data === 'pusat') && $is_super_admin) {
        $sql_cabang_pusat = "SELECT DISTINCT cabang as nama_cabang FROM pemasukan_pusat WHERE cabang IS NOT NULL";
        $stmt_cabang_pusat = $pdo->query($sql_cabang_pusat);
        $cabang_pusat = $stmt_cabang_pusat->fetchAll(PDO::FETCH_ASSOC);
        $cabang_list = array_merge($cabang_list, $cabang_pusat);
    }
} catch (PDOException $e) {
    // Fallback ke query original
    if ($jenis_data === 'semua' || $jenis_data === 'kasir') {
        $sql_cabang_kasir = "SELECT DISTINCT nama_cabang FROM kasir_transactions WHERE nama_cabang IS NOT NULL";
        $stmt_cabang_kasir = $pdo->query($sql_cabang_kasir);
        $cabang_kasir = $stmt_cabang_kasir->fetchAll(PDO::FETCH_ASSOC);
        $cabang_list = array_merge($cabang_list, $cabang_kasir);
    }
    
    if (($jenis_data === 'semua' || $jenis_data === 'pusat') && $is_super_admin) {
        $sql_cabang_pusat = "SELECT DISTINCT cabang as nama_cabang FROM pemasukan_pusat WHERE cabang IS NOT NULL";
        $stmt_cabang_pusat = $pdo->query($sql_cabang_pusat);
        $cabang_pusat = $stmt_cabang_pusat->fetchAll(PDO::FETCH_ASSOC);
        $cabang_list = array_merge($cabang_list, $cabang_pusat);
    }
}

// Remove duplicates and sort
$cabang_list = array_unique($cabang_list, SORT_REGULAR);
usort($cabang_list, function($a, $b) {
    return strcmp($a['nama_cabang'], $b['nama_cabang']);
});

// Query berdasarkan jenis data dengan pendekatan yang lebih sederhana
$pemasukan = [];

if ($jenis_data === 'semua') {
    // Get data separately to avoid collation issues
    $pemasukan_kasir = [];
    $pemasukan_pusat = [];
    
    try {
        // Get kasir data
        $query_kasir = "SELECT 
                        p.kode_transaksi,
                        k.nama_cabang,
                        p.tanggal,
                        p.waktu,
                        p.kode_akun,
                        m.arti AS nama_akun,
                        m.jenis_akun as kategori_akun,
                        p.jumlah,
                        p.keterangan_transaksi AS keterangan_akun,
                        'kasir' as jenis_sumber,
                        k.tanggal_transaksi,
                        CONCAT(p.tanggal, ' ', p.waktu) as datetime_input
                      FROM pemasukan_kasir p
                      JOIN kasir_transactions k ON p.kode_transaksi = k.kode_transaksi
                      LEFT JOIN master_akun m ON p.kode_akun = m.kode_akun
                      WHERE 1 = 1";
        
        if ($tanggal_awal && $tanggal_akhir) {
            $query_kasir .= " AND p.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
        }
        if ($cabang) {
            $query_kasir .= " AND k.nama_cabang = :cabang";
        }
        
        $stmt_kasir = $pdo->prepare($query_kasir);
        if ($tanggal_awal && $tanggal_akhir) {
            $stmt_kasir->bindParam(':tanggal_awal', $tanggal_awal);
            $stmt_kasir->bindParam(':tanggal_akhir', $tanggal_akhir);
        }
        if ($cabang) {
            $stmt_kasir->bindParam(':cabang', $cabang);
        }
        $stmt_kasir->execute();
        $pemasukan_kasir = $stmt_kasir->fetchAll(PDO::FETCH_ASSOC);
        
        // Get pusat data if super admin
        if ($is_super_admin) {
            $query_pusat = "SELECT 
                            NULL as kode_transaksi,
                            pp.cabang as nama_cabang,
                            pp.tanggal,
                            pp.waktu,
                            pp.kode_akun,
                            ma.arti AS nama_akun,
                            ma.jenis_akun as kategori_akun,
                            pp.jumlah,
                            pp.keterangan AS keterangan_akun,
                            'pusat' as jenis_sumber,
                            pp.tanggal as tanggal_transaksi,
                            CONCAT(pp.tanggal, ' ', pp.waktu) as datetime_input
                          FROM pemasukan_pusat pp
                          LEFT JOIN master_akun ma ON pp.kode_akun = ma.kode_akun
                          WHERE 1 = 1";
            
            if ($tanggal_awal && $tanggal_akhir) {
                $query_pusat .= " AND pp.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
            }
            if ($cabang) {
                $query_pusat .= " AND pp.cabang = :cabang";
            }
            
            $stmt_pusat = $pdo->prepare($query_pusat);
            if ($tanggal_awal && $tanggal_akhir) {
                $stmt_pusat->bindParam(':tanggal_awal', $tanggal_awal);
                $stmt_pusat->bindParam(':tanggal_akhir', $tanggal_akhir);
            }
            if ($cabang) {
                $stmt_pusat->bindParam(':cabang', $cabang);
            }
            $stmt_pusat->execute();
            $pemasukan_pusat = $stmt_pusat->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Combine data in PHP
        $pemasukan = array_merge($pemasukan_kasir, $pemasukan_pusat);
        
        // Sort in PHP to avoid collation issues
        usort($pemasukan, function($a, $b) use ($sort_by, $sort_order) {
            $a_val = $a[$sort_by] ?? '';
            $b_val = $b[$sort_by] ?? '';
            
            if ($sort_order === 'ASC') {
                return strcmp($a_val, $b_val);
            } else {
                return strcmp($b_val, $a_val);
            }
        });
        
        // Add secondary sort if not sorting by tanggal
        if ($sort_by !== 'tanggal') {
            usort($pemasukan, function($a, $b) use ($sort_order) {
                $a_tanggal = $a['tanggal'] ?? '';
                $b_tanggal = $b['tanggal'] ?? '';
                
                if ($sort_order === 'ASC') {
                    return strcmp($a_tanggal, $b_tanggal);
                } else {
                    return strcmp($b_tanggal, $a_tanggal);
                }
            });
        }
        
    } catch (PDOException $e) {
        // Fallback to kasir data only
        $pemasukan = $pemasukan_kasir;
        $error_message = "Data pusat tidak dapat dimuat. Hanya menampilkan data kasir.";
    }
    
} else {
    // Single data source query
    try {
        if ($jenis_data === 'pusat') {
            $query = "SELECT 
                        NULL as kode_transaksi,
                        cabang as nama_cabang,
                        tanggal,
                        waktu,
                        pp.kode_akun,
                        ma.arti as nama_akun,
                        ma.jenis_akun as kategori_akun,
                        jumlah,
                        keterangan as keterangan_akun,
                        'pusat' as jenis_sumber,
                        tanggal as tanggal_transaksi,
                        CONCAT(tanggal, ' ', waktu) as datetime_input
                      FROM pemasukan_pusat pp
                      LEFT JOIN master_akun ma ON pp.kode_akun = ma.kode_akun
                      WHERE 1 = 1";
                       
            if ($tanggal_awal && $tanggal_akhir) {
                $query .= " AND tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
            }
            if ($cabang) {
                $query .= " AND cabang = :cabang";
            }
            
            $query .= " ORDER BY tanggal DESC, waktu DESC";
            
        } else {
            $query = "SELECT 
                        p.kode_transaksi,
                        k.nama_cabang,
                        p.tanggal,
                        p.waktu,
                        p.kode_akun,
                        m.arti AS nama_akun,
                        m.jenis_akun as kategori_akun,
                        p.jumlah,
                        p.keterangan_transaksi AS keterangan_akun,
                        'kasir' as jenis_sumber,
                        k.tanggal_transaksi,
                        CONCAT(p.tanggal, ' ', p.waktu) as datetime_input
                      FROM pemasukan_kasir p
                      JOIN kasir_transactions k ON p.kode_transaksi = k.kode_transaksi
                      LEFT JOIN master_akun m ON p.kode_akun = m.kode_akun
                      WHERE 1 = 1";
                       
            if ($tanggal_awal && $tanggal_akhir) {
                $query .= " AND p.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
            }
            if ($cabang) {
                $query .= " AND k.nama_cabang = :cabang";
            }
            
            $query .= " ORDER BY p.tanggal DESC, p.waktu DESC";
        }
        
        $stmt = $pdo->prepare($query);
        if ($tanggal_awal && $tanggal_akhir) {
            $stmt->bindParam(':tanggal_awal', $tanggal_awal);
            $stmt->bindParam(':tanggal_akhir', $tanggal_akhir);
        }
        if ($cabang) {
            $stmt->bindParam(':cabang', $cabang);
        }
        $stmt->execute();
        $pemasukan = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $pemasukan = [];
        $error_message = "Error loading data: " . $e->getMessage();
    }
}

// Calculate statistics
$total_records = count($pemasukan);
$total_amount = array_sum(array_column($pemasukan, 'jumlah'));

// Calculate statistics by source
$stats_by_source = [];
foreach ($pemasukan as $data) {
    $source = $data['jenis_sumber'] ?? 'unknown';
    if (!isset($stats_by_source[$source])) {
        $stats_by_source[$source] = ['count' => 0, 'total' => 0];
    }
    $stats_by_source[$source]['count']++;
    $stats_by_source[$source]['total'] += $data['jumlah'];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Pemasukan - Admin Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="includes/sidebar.css" rel="stylesheet">
    <style>
        /* CSS styles here - simplified for brevity */
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; }
        .main-content { margin-left: 280px; padding: 20px; }
        .alert { padding: 15px; margin: 10px 0; border-radius: 5px; }
        .alert-warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
        .alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        .table th { background: #f8f9fa; }
        .btn { padding: 10px 15px; text-decoration: none; border-radius: 5px; margin: 5px; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
    </style>
</head>
<body>

<?php include 'includes/sidebar.php'; ?>

<div class="main-content">
    <h1>Detail Pemasukan Terpadu</h1>
    
    <!-- Error Message Alert -->
    <?php if (isset($error_message)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Peringatan:</strong> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>
    
    <!-- Data Type Selection -->
    <div style="margin: 20px 0;">
        <a href="?jenis_data=semua" class="btn <?php echo $jenis_data === 'semua' ? 'btn-primary' : 'btn-secondary'; ?>">
            Semua Data
        </a>
        <a href="?jenis_data=kasir" class="btn <?php echo $jenis_data === 'kasir' ? 'btn-primary' : 'btn-secondary'; ?>">
            Pemasukan Kasir
        </a>
        <?php if ($is_super_admin): ?>
            <a href="?jenis_data=pusat" class="btn <?php echo $jenis_data === 'pusat' ? 'btn-primary' : 'btn-secondary'; ?>">
                Pemasukan Pusat
            </a>
        <?php endif; ?>
    </div>
    
    <!-- Filter Form -->
    <form method="GET" style="margin: 20px 0; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
        <input type="hidden" name="jenis_data" value="<?php echo htmlspecialchars($jenis_data); ?>">
        
        <label>Tanggal Awal: <input type="date" name="tanggal_awal" value="<?php echo htmlspecialchars($tanggal_awal ?? ''); ?>"></label>
        <label>Tanggal Akhir: <input type="date" name="tanggal_akhir" value="<?php echo htmlspecialchars($tanggal_akhir ?? ''); ?>"></label>
        <label>Cabang: 
            <select name="cabang">
                <option value="">Semua Cabang</option>
                <?php foreach ($cabang_list as $cabang_item): ?>
                    <option value="<?php echo htmlspecialchars($cabang_item['nama_cabang']); ?>"
                            <?php echo isset($_GET['cabang']) && $_GET['cabang'] == $cabang_item['nama_cabang'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(ucfirst($cabang_item['nama_cabang'])); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="detail_pemasukan_fixed.php?jenis_data=<?php echo $jenis_data; ?>" class="btn btn-secondary">Reset</a>
    </form>
    
    <!-- Statistics -->
    <div style="margin: 20px 0;">
        <h3>Statistik:</h3>
        <p>Total Record: <?php echo number_format($total_records); ?></p>
        <p>Total Pemasukan: Rp <?php echo number_format($total_amount, 0, ',', '.'); ?></p>
    </div>
    
    <!-- Data Table -->
    <?php if (count($pemasukan) > 0): ?>
        <table class="table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Tanggal</th>
                    <th>Waktu</th>
                    <th>Kode Transaksi</th>
                    <th>Nama Cabang</th>
                    <th>Sumber</th>
                    <th>Kategori Akun</th>
                    <th>Nama Akun</th>
                    <th>Kode Akun</th>
                    <th>Keterangan</th>
                    <th>Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pemasukan as $index => $data): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><?php echo date('d/m/Y', strtotime($data['tanggal'])); ?></td>
                        <td><?php echo htmlspecialchars($data['waktu']); ?></td>
                        <td><?php echo htmlspecialchars($data['kode_transaksi'] ?? 'Auto Generated'); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($data['nama_cabang'])); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($data['jenis_sumber'])); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($data['kategori_akun'])); ?></td>
                        <td><?php echo htmlspecialchars($data['nama_akun'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($data['kode_akun']); ?></td>
                        <td><?php echo htmlspecialchars($data['keterangan_akun'] ?? '-'); ?></td>
                        <td>Rp <?php echo number_format($data['jumlah'], 0, ',', '.'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div style="text-align: center; padding: 40px;">
            <p>Tidak ada data pemasukan untuk filter yang dipilih</p>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
