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
$jenis_data = $_GET['jenis_data'] ?? 'semua'; // Default ke semua (kasir + pusat)

// TAMBAHAN: Variabel untuk sorting
$sort_by = $_GET['sort_by'] ?? 'tanggal';
$sort_order = $_GET['sort_order'] ?? 'DESC';

// Debug: Log semua parameter GET
error_log("DEBUG - All GET params: " . print_r($_GET, true));
error_log("DEBUG - Sort by: " . $sort_by . ", Sort order: " . $sort_order);

// Validasi sort_by untuk keamanan - UPDATED column names to match Excel format
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
    // For PMK format: PMK-20240115-USR001001 (pemasukan pusat)
    if (preg_match('/PMK-(\d{4})(\d{2})(\d{2})-/', $kode_transaksi, $matches)) {
        return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
    }
    // For TRX format: TRX-20240115- (kasir)
    if (preg_match('/TRX-(\d{4})(\d{2})(\d{2})-/', $kode_transaksi, $matches)) {
        return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
    }
    return '-';
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

// Query untuk mendapatkan daftar cabang dari semua sumber
$cabang_list = [];
try {
    if ($jenis_data === 'semua' || $jenis_data === 'kasir') {
        $sql_cabang_kasir = "SELECT DISTINCT nama_cabang FROM view_pemasukan_kasir WHERE nama_cabang IS NOT NULL";
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
    // Fallback ke query original jika VIEW belum dibuat
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

// Query berdasarkan jenis data dengan sorting dinamis
$pemasukan = [];

if ($jenis_data === 'semua') {
    // UNION query untuk menggabungkan data kasir dan pusat
    try {
        $query_kasir = "SELECT 
                        kode_transaksi,
                        nama_cabang,
                        tanggal,
                        waktu,
                        kode_akun,
                        nama_akun,
                        jenis_akun as kategori_akun,
                        jumlah,
                        keterangan_akun,
                        'kasir' as jenis_sumber,
                        tanggal_transaksi,
                        datetime_input
                      FROM view_pemasukan_kasir
                      WHERE 1 = 1";
        
        $query_pusat = "SELECT 
                        NULL as kode_transaksi,
                        cabang as nama_cabang,
                        tanggal,
                        waktu,
                        pp.kode_akun as kode_akun,
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
        
        // Add filters to both queries
        $filter_conditions = [];
        if ($tanggal_awal && $tanggal_akhir) {
            $filter_conditions[] = " AND tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
        }
        if ($cabang) {
            $filter_conditions[] = " AND nama_cabang = :cabang";
        }
        
        $filter_string = implode('', $filter_conditions);
        $query_kasir .= $filter_string;
        
        // For pusat query, adjust column names
        $filter_string_pusat = str_replace('nama_cabang', 'cabang', $filter_string);
        $query_pusat .= $filter_string_pusat;
        
        // Simple UNION approach - No ORDER BY, akan di-sort dengan PHP
        $query = "({$query_kasir}) UNION ALL ({$query_pusat})";
        
    } catch (PDOException $e) {
        // FIXED: Fallback to original queries WITH PROPER ALIASES
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
        
        // Add filters
        $filter_conditions_kasir = [];
        $filter_conditions_pusat = [];
        
        if ($tanggal_awal && $tanggal_akhir) {
            $filter_conditions_kasir[] = " AND p.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
            $filter_conditions_pusat[] = " AND pp.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
        }
        if ($cabang) {
            $filter_conditions_kasir[] = " AND k.nama_cabang = :cabang";
            $filter_conditions_pusat[] = " AND pp.cabang = :cabang";
        }
        
        $query_kasir .= implode('', $filter_conditions_kasir);
        $query_pusat .= implode('', $filter_conditions_pusat);
        
        // Adjust sort column for fallback
        $fallback_sort_by = $sort_by;
        if ($sort_by === 'nama_cabang') {
            $fallback_sort_by = 'nama_cabang';
        } elseif ($sort_by === 'kategori_akun') {
            $fallback_sort_by = 'kategori_akun';
        } elseif ($sort_by === 'kode_akun') {
            $fallback_sort_by = 'kode_akun';
        }
        
        // Simple UNION fallback approach - No ORDER BY
        $query = "({$query_kasir}) UNION ALL ({$query_pusat})";
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
                        pp.kode_akun as kode_akun,
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
            
        } else {
            $query = "SELECT 
                        kode_transaksi,
                        nama_cabang,
                        tanggal,
                        waktu,
                        kode_akun,
                        nama_akun,
                        jenis_akun as kategori_akun,
                        jumlah,
                        keterangan_akun,
                        'kasir' as jenis_sumber,
                        tanggal_transaksi,
                        datetime_input
                      FROM view_pemasukan_kasir
                      WHERE 1 = 1";
                      
            if ($tanggal_awal && $tanggal_akhir) {
                $query .= " AND tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
            }
            if ($cabang) {
                $query .= " AND nama_cabang = :cabang";
            }
        }
        
        // No ORDER BY - akan di-sort dengan PHP
        
    } catch (PDOException $e) {
        // FIXED: Fallback queries WITH PROPER ALIASES
        if ($jenis_data === 'pusat') {
            $query = "SELECT 
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
                $query .= " AND pp.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
            }
            if ($cabang) {
                $query .= " AND pp.cabang = :cabang";
            }
            
            // Fixed sort column mapping for pusat
            $fallback_sort_by = $sort_by;
            if ($sort_by === 'nama_cabang') $fallback_sort_by = 'pp.cabang';
            elseif ($sort_by === 'kategori_akun') $fallback_sort_by = 'ma.jenis_akun';
            elseif ($sort_by === 'kode_akun') $fallback_sort_by = 'pp.kode_akun';
            elseif ($sort_by === 'nama_akun') $fallback_sort_by = 'ma.arti';
            elseif ($sort_by === 'keterangan_akun') $fallback_sort_by = 'pp.keterangan';
            elseif (in_array($sort_by, ['tanggal', 'waktu', 'jumlah'])) $fallback_sort_by = 'pp.' . $sort_by;
            
            // No ORDER BY - akan di-sort dengan PHP
            
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
            
            // Fixed sort column mapping for kasir
            $fallback_sort_by = $sort_by;
            if ($sort_by === 'nama_cabang') $fallback_sort_by = 'k.nama_cabang';
            elseif ($sort_by === 'kategori_akun') $fallback_sort_by = 'm.jenis_akun';
            elseif ($sort_by === 'kode_akun') $fallback_sort_by = 'p.kode_akun';
            elseif ($sort_by === 'nama_akun') $fallback_sort_by = 'm.arti';
            elseif ($sort_by === 'keterangan_akun') $fallback_sort_by = 'p.keterangan_transaksi';
            elseif ($sort_by === 'tanggal_transaksi') $fallback_sort_by = 'k.tanggal_transaksi';
            elseif (in_array($sort_by, ['tanggal', 'waktu', 'jumlah', 'kode_transaksi'])) $fallback_sort_by = 'p.' . $sort_by;
            
            // No ORDER BY - akan di-sort dengan PHP
        }
    }
}

try {
    // Debug logging untuk melihat query yang dijalankan
    error_log("Sorting Debug - Sort by: {$sort_by}, Sort order: {$sort_order}");
    error_log("Query to execute: " . substr($query, 0, 500) . "...");
    
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
    
    // SORTING MENGGUNAKAN PHP
    if (!empty($pemasukan)) {
        // Function untuk sorting berdasarkan kolom dan order
        function sortData($data, $column, $order) {
            usort($data, function($a, $b) use ($column, $order) {
                $valueA = $a[$column] ?? '';
                $valueB = $b[$column] ?? '';
                
                // Handle specific data types
                if ($column === 'jumlah') {
                    // Numeric sorting for amount
                    $result = floatval($valueA) <=> floatval($valueB);
                } elseif ($column === 'tanggal' || $column === 'tanggal_transaksi') {
                    // Date sorting
                    $dateA = strtotime($valueA);
                    $dateB = strtotime($valueB);
                    $result = $dateA <=> $dateB;
                } elseif ($column === 'waktu') {
                    // Time sorting
                    $timeA = strtotime("1970-01-01 " . $valueA);
                    $timeB = strtotime("1970-01-01 " . $valueB);
                    $result = $timeA <=> $timeB;
                } elseif (is_numeric($valueA) && is_numeric($valueB)) {
                    // General numeric sorting
                    $result = $valueA <=> $valueB;
                } else {
                    // String sorting (case insensitive)
                    $result = strcmp(strtolower($valueA), strtolower($valueB));
                }
                
                return ($order === 'ASC') ? $result : -$result;
            });
            return $data;
        }
        
        // Sort data sesuai parameter
        $pemasukan = sortData($pemasukan, $sort_by, $sort_order);
        
        // Debug: Log hasil sorting
        $first_row = $pemasukan[0];
        $last_row = end($pemasukan);
        error_log("DEBUG PHP SORT - Sort by: {$sort_by} {$sort_order}");
        error_log("DEBUG PHP SORT - First row {$sort_by}: " . ($first_row[$sort_by] ?? 'NULL'));
        error_log("DEBUG PHP SORT - Last row {$sort_by}: " . ($last_row[$sort_by] ?? 'NULL'));
        error_log("DEBUG PHP SORT - Total records: " . count($pemasukan));
    }
} catch (PDOException $e) {
    // Log error for debugging
    error_log("Database error in detail_pemasukan.php: " . $e->getMessage());
    error_log("Query: " . $query);
    
    // Final fallback to simple query with sorting support
    if ($jenis_data === 'semua') {
        // Simple UNION query for final fallback with basic sorting
        $final_kasir = "SELECT 
                            p.kode_transaksi,
                            k.nama_cabang,
                            p.tanggal,
                            p.waktu,
                            p.kode_akun,
                            COALESCE(m.arti, 'Unknown') AS nama_akun,
                            COALESCE(m.jenis_akun, 'tidak_diketahui') as kategori_akun,
                            p.jumlah,
                            COALESCE(p.keterangan_transaksi, '-') AS keterangan_akun,
                            'kasir' as jenis_sumber,
                            k.tanggal_transaksi,
                            CONCAT(p.tanggal, ' ', p.waktu) as datetime_input
                          FROM pemasukan_kasir p
                          JOIN kasir_transactions k ON p.kode_transaksi = k.kode_transaksi
                          LEFT JOIN master_akun m ON p.kode_akun = m.kode_akun
                          WHERE 1 = 1";
        
        $final_pusat = "SELECT 
                            NULL as kode_transaksi,
                            pp.cabang as nama_cabang,
                            pp.tanggal,
                            pp.waktu,
                            pp.kode_akun,
                            COALESCE(ma.arti, 'Unknown') AS nama_akun,
                            COALESCE(ma.jenis_akun, 'tidak_diketahui') as kategori_akun,
                            pp.jumlah,
                            COALESCE(pp.keterangan, '-') AS keterangan_akun,
                            'pusat' as jenis_sumber,
                            pp.tanggal as tanggal_transaksi,
                            CONCAT(pp.tanggal, ' ', pp.waktu) as datetime_input
                          FROM pemasukan_pusat pp
                          LEFT JOIN master_akun ma ON pp.kode_akun = ma.kode_akun
                          WHERE 1 = 1";
        
        if ($tanggal_awal && $tanggal_akhir) {
            $final_kasir .= " AND p.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
            $final_pusat .= " AND pp.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
        }
        if ($cabang) {
            $final_kasir .= " AND k.nama_cabang = :cabang";
            $final_pusat .= " AND pp.cabang = :cabang";
        }
        
        // Create simple UNION - No ORDER BY
        $fallback_query = "({$final_kasir}) UNION ALL ({$final_pusat})";
        
    } else if ($jenis_data === 'kasir') {
        $fallback_query = "SELECT 
                            p.kode_transaksi,
                            k.nama_cabang,
                            p.tanggal,
                            p.waktu,
                            p.kode_akun,
                            COALESCE(m.arti, 'Unknown') AS nama_akun,
                            COALESCE(m.jenis_akun, 'tidak_diketahui') as kategori_akun,
                            p.jumlah,
                            COALESCE(p.keterangan_transaksi, '-') AS keterangan_akun,
                            'kasir' as jenis_sumber,
                            k.tanggal_transaksi,
                            CONCAT(p.tanggal, ' ', p.waktu) as datetime_input
                          FROM pemasukan_kasir p
                          JOIN kasir_transactions k ON p.kode_transaksi = k.kode_transaksi
                          LEFT JOIN master_akun m ON p.kode_akun = m.kode_akun
                          WHERE 1 = 1";
        
        if ($tanggal_awal && $tanggal_akhir) {
            $fallback_query .= " AND p.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
        }
        if ($cabang) {
            $fallback_query .= " AND k.nama_cabang = :cabang";
        }
        
        // No ORDER BY - akan di-sort dengan PHP
        
    } else {
        $fallback_query = "SELECT 
                            NULL as kode_transaksi,
                            pp.cabang as nama_cabang,
                            pp.tanggal,
                            pp.waktu,
                            pp.kode_akun,
                            COALESCE(ma.arti, 'Unknown') AS nama_akun,
                            COALESCE(ma.jenis_akun, 'tidak_diketahui') as kategori_akun,
                            pp.jumlah,
                            COALESCE(pp.keterangan, '-') AS keterangan_akun,
                            'pusat' as jenis_sumber,
                            pp.tanggal as tanggal_transaksi,
                            CONCAT(pp.tanggal, ' ', pp.waktu) as datetime_input
                          FROM pemasukan_pusat pp
                          LEFT JOIN master_akun ma ON pp.kode_akun = ma.kode_akun
                          WHERE 1 = 1";
        
        if ($tanggal_awal && $tanggal_akhir) {
            $fallback_query .= " AND pp.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
        }
        if ($cabang) {
            $fallback_query .= " AND pp.cabang = :cabang";
        }
        
        // No ORDER BY - akan di-sort dengan PHP
    }
    
    $stmt = $pdo->prepare($fallback_query);
    if ($tanggal_awal && $tanggal_akhir) {
        $stmt->bindParam(':tanggal_awal', $tanggal_awal);
        $stmt->bindParam(':tanggal_akhir', $tanggal_akhir);
    }
    if ($cabang) {
        $stmt->bindParam(':cabang', $cabang);
    }
    $stmt->execute();
    $pemasukan = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // SORTING MENGGUNAKAN PHP untuk fallback query
    if (!empty($pemasukan)) {
        // Function untuk sorting berdasarkan kolom dan order (fallback)
        function sortDataFallback($data, $column, $order) {
            usort($data, function($a, $b) use ($column, $order) {
                $valueA = $a[$column] ?? '';
                $valueB = $b[$column] ?? '';
                
                // Handle specific data types
                if ($column === 'jumlah') {
                    // Numeric sorting for amount
                    $result = floatval($valueA) <=> floatval($valueB);
                } elseif ($column === 'tanggal' || $column === 'tanggal_transaksi') {
                    // Date sorting
                    $dateA = strtotime($valueA);
                    $dateB = strtotime($valueB);
                    $result = $dateA <=> $dateB;
                } elseif ($column === 'waktu') {
                    // Time sorting
                    $timeA = strtotime("1970-01-01 " . $valueA);
                    $timeB = strtotime("1970-01-01 " . $valueB);
                    $result = $timeA <=> $timeB;
                } elseif (is_numeric($valueA) && is_numeric($valueB)) {
                    // General numeric sorting
                    $result = $valueA <=> $valueB;
                } else {
                    // String sorting (case insensitive)
                    $result = strcmp(strtolower($valueA), strtolower($valueB));
                }
                
                return ($order === 'ASC') ? $result : -$result;
            });
            return $data;
        }
        
        // Sort data sesuai parameter
        $pemasukan = sortDataFallback($pemasukan, $sort_by, $sort_order);
        
        error_log("DEBUG FALLBACK SORT - Sorted by: {$sort_by} {$sort_order}");
    }
    
    // Show warning to user
    $error_message = "Data berhasil dimuat dengan query fallback karena ada masalah dengan sorting. Silakan hubungi administrator.";
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        
        .stats-card.warning {
            border-left-color: var(--warning-color);
        }
        
        .stats-card.success {
            border-left-color: var(--success-color);
        }
        
        .stats-card.info {
            border-left-color: var(--info-color);
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
            font-size: 20px;
            font-weight: bold;
            margin: 0;
            color: var(--text-dark);
        }
        
        .stats-icon {
            font-size: 28px;
            opacity: 0.7;
            color: var(--primary-color);
        }
        
        .stats-card.warning .stats-icon {
            color: var(--warning-color);
        }
        
        .stats-card.success .stats-icon {
            color: var(--success-color);
        }
        
        .stats-card.info .stats-icon {
            color: var(--info-color);
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
        
        .btn-info {
            background-color: var(--info-color);
            color: white;
        }
        
        .btn-info:hover {
            background-color: #138496;
        }
        
        .btn-warning {
            background-color: var(--warning-color);
            color: #212529;
        }
        
        .btn-warning:hover {
            background-color: #e0a800;
        }
        
        .btn-tab {
            margin-right: 10px;
            margin-bottom: 10px;
            border: 2px solid transparent;
        }
        
        .btn-tab.active {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
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
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
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
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1400px;
        }
        
        .table th {
            background: var(--background-light);
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 13px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s ease;
            position: relative;
        }
        
        .table th:hover {
            background: #e2e8f0;
        }
        
        .table th.sortable {
            padding-right: 30px;
        }
        
        .table th .sort-icon {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 12px;
            color: var(--primary-color);
            font-weight: bold;
        }
        
        .table th.active {
            background: rgba(0,123,255,0.1);
            color: var(--primary-color);
        }
        
        .table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
            white-space: nowrap;
        }
        
        .table tbody tr:hover {
            background: var(--background-light);
        }
        
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .jenis-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .jenis-pemasukan {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
        }
        
        .sumber-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .sumber-kasir {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
        }
        
        .sumber-pusat {
            background: rgba(0,123,255,0.1);
            color: var(--primary-color);
        }
        
        .amount-cell {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: var(--success-color);
            text-align: right;
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
            align-items: center;
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
        
        .alert-warning {
            background: rgba(255,193,7,0.1);
            color: var(--warning-color);
            border-color: rgba(255,193,7,0.2);
        }
        
        @media (max-width: 768px) {
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
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
            }
        }
    </style>
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
        <h1><i class="fas fa-hand-holding-usd"></i> Detail Pemasukan Terpadu</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Monitor dan analisis data pemasukan dari kasir dan pusat dengan sorting dinamis dan performa yang dioptimasi</p>
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
                    <h4>Total Record</h4>
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
                    <h4>Total Pemasukan</h4>
                    <p class="stats-number">Rp <?php echo number_format($total_amount, 0, ',', '.'); ?></p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
            </div>
        </div>
        <?php if (isset($stats_by_source['kasir'])): ?>
        <div class="stats-card info">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Pemasukan Kasir</h4>
                    <p class="stats-number">Rp <?php echo number_format($stats_by_source['kasir']['total'], 0, ',', '.'); ?></p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-cash-register"></i>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (isset($stats_by_source['pusat'])): ?>
        <div class="stats-card warning">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Pemasukan Pusat</h4>
                    <p class="stats-number">Rp <?php echo number_format($stats_by_source['pusat']['total'], 0, ',', '.'); ?></p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-building"></i>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Debug Info -->
    <?php if (isset($_GET['debug'])): ?>
        <div class="alert alert-info">
            <i class="fas fa-bug"></i>
            <strong>Debug Info:</strong><br>
            Sort By: <?php echo htmlspecialchars($sort_by); ?><br>
            Sort Order: <?php echo htmlspecialchars($sort_order); ?><br>
            GET Params: <?php echo htmlspecialchars(http_build_query($_GET)); ?><br>
            Total Records: <?php echo count($pemasukan); ?><br>
            <?php if (!empty($pemasukan)): ?>
                First Record <?php echo $sort_by; ?>: <?php echo htmlspecialchars($pemasukan[0][$sort_by] ?? 'NULL'); ?><br>
                Last Record <?php echo $sort_by; ?>: <?php echo htmlspecialchars(end($pemasukan)[$sort_by] ?? 'NULL'); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Error Message Alert -->
    <?php if (isset($error_message)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Peringatan:</strong> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>
    
    <!-- Sort Order Alert -->
    <?php if (count($pemasukan) > 0): ?>
        <div class="alert alert-success">
            <i class="fas fa-sort"></i>
            <strong>Sorting Aktif:</strong> Data diurutkan berdasarkan <strong><?php echo ucfirst($sort_by); ?></strong> 
            secara <strong><?php echo ($sort_order === 'ASC') ? 'Ascending (A-Z, Kecil-Besar)' : 'Descending (Z-A, Besar-Kecil)'; ?></strong>.
            Klik header kolom untuk mengubah urutan.
        </div>
    <?php endif; ?>

    <!-- Data Type Selection -->
    <div class="filter-card">
        <h3><i class="fas fa-toggle-on"></i> Pilih Jenis Data</h3>
        <div style="margin-bottom: 20px;">
            <a href="?jenis_data=semua&<?php echo http_build_query(array_filter(['tanggal_awal' => $tanggal_awal, 'tanggal_akhir' => $tanggal_akhir, 'cabang' => $cabang, 'sort_by' => $sort_by, 'sort_order' => $sort_order])); ?>"
               class="btn btn-warning btn-tab <?php echo $jenis_data === 'semua' ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i> Semua Data (Kasir + Pusat)
            </a>
            <a href="?jenis_data=kasir&<?php echo http_build_query(array_filter(['tanggal_awal' => $tanggal_awal, 'tanggal_akhir' => $tanggal_akhir, 'cabang' => $cabang, 'sort_by' => $sort_by, 'sort_order' => $sort_order])); ?>"
               class="btn btn-info btn-tab <?php echo $jenis_data === 'kasir' ? 'active' : ''; ?>">
                <i class="fas fa-cash-register"></i> Pemasukan Kasir
            </a>
            <?php if ($is_super_admin): ?>
                <a href="?jenis_data=pusat&<?php echo http_build_query(array_filter(['tanggal_awal' => $tanggal_awal, 'tanggal_akhir' => $tanggal_akhir, 'cabang' => $cabang, 'sort_by' => $sort_by, 'sort_order' => $sort_order])); ?>"
                   class="btn btn-primary btn-tab <?php echo $jenis_data === 'pusat' ? 'active' : ''; ?>">
                    <i class="fas fa-building"></i> Pemasukan Pusat
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="filter-card">
        <h3><i class="fas fa-filter"></i> Filter Data Pemasukan <?php echo ucfirst($jenis_data); ?></h3>
        <form method="GET" action="">
            <!-- Preserve sorting and data type parameters -->
            <input type="hidden" name="jenis_data" value="<?php echo htmlspecialchars($jenis_data); ?>">
            <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sort_by); ?>">
            <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sort_order); ?>">
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="tanggal_awal" class="form-label">
                        <i class="fas fa-calendar-alt"></i> Tanggal Awal
                    </label>
                    <input type="date"
                           name="tanggal_awal"
                           id="tanggal_awal"
                           value="<?php echo htmlspecialchars($tanggal_awal ?? '', ENT_QUOTES); ?>"
                           class="form-control">
                </div>
                <div class="form-group">
                    <label for="tanggal_akhir" class="form-label">
                        <i class="fas fa-calendar-alt"></i> Tanggal Akhir
                    </label>
                    <input type="date"
                           name="tanggal_akhir"
                           id="tanggal_akhir"
                           value="<?php echo htmlspecialchars($tanggal_akhir ?? '', ENT_QUOTES); ?>"
                           class="form-control">
                </div>
                <div class="form-group">
                    <label for="cabang" class="form-label">
                        <i class="fas fa-building"></i> Cabang
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
                <a href="detail_pemasukan.php?jenis_data=<?php echo $jenis_data; ?>" class="btn btn-secondary">
                    <i class="fas fa-refresh"></i> Reset Filter
                </a>
                <?php if (count($pemasukan) > 0): ?>
                    <a href="export_excel_pemasukan.php?<?php echo http_build_query($_GET); ?>" class="btn btn-success">
                        <i class="fas fa-file-excel"></i> Unduh Excel
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Data Table -->
    <?php if (count($pemasukan) > 0): ?>
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-table"></i> Data Pemasukan <?php echo ucfirst($jenis_data); ?></h3>
                <div style="display: flex; gap: 15px; align-items: center;">
                    <div class="sort-info">
                        <i class="fas fa-sort"></i> 
                        <?php echo ucfirst($sort_by) . ' ' . (($sort_order === 'ASC') ? '▲' : '▼'); ?>
                    </div>
                    <div style="font-size: 14px; color: var(--text-muted);">
                        Menampilkan <?php echo number_format(count($pemasukan)); ?> dari total data
                    </div>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th class="sortable <?php echo ($sort_by === 'tanggal') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('tanggal', $sort_by, $sort_order); ?>'">
                                Tanggal Input
                                <span class="sort-icon"><?php echo getSortIcon('tanggal', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'waktu') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('waktu', $sort_by, $sort_order); ?>'">
                                Waktu Input
                                <span class="sort-icon"><?php echo getSortIcon('waktu', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'kode_transaksi') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('kode_transaksi', $sort_by, $sort_order); ?>'">
                                Kode Transaksi
                                <span class="sort-icon"><?php echo getSortIcon('kode_transaksi', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'nama_cabang') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('nama_cabang', $sort_by, $sort_order); ?>'">
                                Nama Cabang
                                <span class="sort-icon"><?php echo getSortIcon('nama_cabang', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'jenis_sumber') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('jenis_sumber', $sort_by, $sort_order); ?>'">
                                Sumber
                                <span class="sort-icon"><?php echo getSortIcon('jenis_sumber', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'kategori_akun') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('kategori_akun', $sort_by, $sort_order); ?>'">
                                Kategori Akun
                                <span class="sort-icon"><?php echo getSortIcon('kategori_akun', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'nama_akun') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('nama_akun', $sort_by, $sort_order); ?>'">
                                Nama Akun
                                <span class="sort-icon"><?php echo getSortIcon('nama_akun', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'tanggal_transaksi') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('tanggal_transaksi', $sort_by, $sort_order); ?>'">
                                Tanggal Transaksi
                                <span class="sort-icon"><?php echo getSortIcon('tanggal_transaksi', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'kode_akun') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('kode_akun', $sort_by, $sort_order); ?>'">
                                Kode Akun
                                <span class="sort-icon"><?php echo getSortIcon('kode_akun', $sort_by, $sort_order); ?></span>
                            </th>
                            <th>Umur Pakai (Bulan)</th>
                            <th class="sortable <?php echo ($sort_by === 'keterangan_akun') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('keterangan_akun', $sort_by, $sort_order); ?>'">
                                Keterangan Akun
                                <span class="sort-icon"><?php echo getSortIcon('keterangan_akun', $sort_by, $sort_order); ?></span>
                            </th>
                            <th class="sortable <?php echo ($sort_by === 'jumlah') ? 'active' : ''; ?>" 
                                onclick="window.location.href='<?php echo getSortUrl('jumlah', $sort_by, $sort_order); ?>'">
                                Jumlah (Rp)
                                <span class="sort-icon"><?php echo getSortIcon('jumlah', $sort_by, $sort_order); ?></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pemasukan as $index => $data): ?>
                            <tr>
                                <td><strong><?php echo $index + 1; ?></strong></td>
                                <td><?php echo date('d/m/Y', strtotime($data['tanggal'])); ?></td>
                                <td><?php echo htmlspecialchars($data['waktu'], ENT_QUOTES); ?></td>
                                <td>
                                    <?php if ($data['kode_transaksi']): ?>
                                        <code><?php echo htmlspecialchars($data['kode_transaksi'], ENT_QUOTES); ?></code>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-style: italic;">Auto Generated</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(ucfirst($data['nama_cabang']), ENT_QUOTES); ?></td>
                                <td>
                                    <span class="sumber-badge sumber-<?php echo htmlspecialchars($data['jenis_sumber'] ?? 'unknown', ENT_QUOTES); ?>">
                                        <?php echo htmlspecialchars(ucfirst($data['jenis_sumber'] ?? 'Unknown'), ENT_QUOTES); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="jenis-badge jenis-<?php echo htmlspecialchars($data['kategori_akun'] ?? '', ENT_QUOTES); ?>">
                                        <?php echo htmlspecialchars(ucfirst($data['kategori_akun'] ?? 'tidak_diketahui'), ENT_QUOTES); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($data['nama_akun'] ?? '-', ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars(extractTransactionDate($data['kode_transaksi'] ?? ''), ENT_QUOTES); ?></td>
                                <td><code><?php echo htmlspecialchars($data['kode_akun'], ENT_QUOTES); ?></code></td>
                                <td><span style="color: var(--text-muted);">-</span></td>
                                <td><?php echo htmlspecialchars($data['keterangan_akun'] ?? '-', ENT_QUOTES); ?></td>
                                <td class="amount-cell">Rp <?php echo number_format($data['jumlah'], 0, ',', '.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-table"></i> Data Pemasukan <?php echo ucfirst($jenis_data); ?></h3>
            </div>
            <div class="no-data">
                <i class="fas fa-search"></i><br>
                <strong>Tidak ada data pemasukan</strong><br>
                untuk filter yang dipilih
            </div>
        </div>
    <?php endif; ?>

    <?php if (count($pemasukan) > 0): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <strong>Info:</strong> 
            Klik pada header kolom untuk mengurutkan data. Ikon ▲ menunjukkan urutan ascending (A-Z, kecil-besar), 
            ikon ▼ menunjukkan urutan descending (Z-A, besar-kecil). 
            Data saat ini diurutkan berdasarkan <strong><?php echo ucfirst($sort_by); ?></strong> 
            secara <strong><?php echo ($sort_order === 'ASC') ? 'Ascending' : 'Descending'; ?></strong>.
            <?php if ($jenis_data === 'semua'): ?>
                Menampilkan data gabungan dari pemasukan kasir dan pusat.
            <?php endif; ?>
        </div>
    <?php endif; ?>

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
        
        const minWidth = 280;
        sidebar.style.width = maxWidth > minWidth ? `${maxWidth + 40}px` : `${minWidth}px`;
        document.querySelector('.main-content').style.marginLeft = sidebar.style.width;
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

    // Add hover effect for sortable columns
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
    });

    // Run on page load and window resize
    window.addEventListener('load', adjustSidebarWidth);
    window.addEventListener('resize', adjustSidebarWidth);
</script>

</body>
</html>