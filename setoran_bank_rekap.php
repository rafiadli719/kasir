<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Jakarta');

// Initialize PDO connection
$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Check session and role
if (!isset($_SESSION['kode_karyawan']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

$is_super_admin = false;
$is_admin = false;
$kode_karyawan = $_SESSION['kode_karyawan'];

$query = "SELECT role FROM users WHERE kode_karyawan = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$kode_karyawan]);
$user = $stmt->fetch();

if ($user) {
    if ($user['role'] === 'super_admin') {
        $is_super_admin = true;
    } elseif ($user['role'] === 'admin') {
        $is_admin = true;
    }
} else {
    echo "Pengguna tidak ditemukan";
}

$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$kode_karyawan = $_SESSION['kode_karyawan'];

// Fetch filter parameters
$tanggal_awal = $_POST['tanggal_awal'] ?? $_GET['tanggal_awal'] ?? '';
$tanggal_akhir = $_POST['tanggal_akhir'] ?? $_GET['tanggal_akhir'] ?? '';
$cabang = $_POST['cabang'] ?? $_GET['cabang'] ?? 'all';
$rekening_filter = $_POST['rekening_filter'] ?? $_GET['rekening_filter'] ?? 'all';

// Fetch cabang list for filter dropdown
$sql_cabang = "SELECT DISTINCT nama_cabang FROM setoran_keuangan WHERE nama_cabang IS NOT NULL AND nama_cabang != '' ORDER BY nama_cabang";
$stmt_cabang = $pdo->query($sql_cabang);
$cabang_list = $stmt_cabang->fetchAll(PDO::FETCH_COLUMN);

// PERBAIKAN: Fetch rekening list dengan ekstraksi nomor rekening yang benar
$sql_rekening = "
    SELECT 
        CASE 
            WHEN sb.rekening_tujuan REGEXP '[0-9]{10,}' THEN
                REGEXP_SUBSTR(sb.rekening_tujuan, '[0-9]{10,}')
            ELSE sb.rekening_tujuan
        END as rekening_number,
        sb.rekening_tujuan as rekening_full,
        GROUP_CONCAT(DISTINCT sk.nama_cabang ORDER BY sk.nama_cabang SEPARATOR ' & ') as nama_cabang_combined,
        COUNT(DISTINCT sk.nama_cabang) as jumlah_cabang,
        COUNT(DISTINCT sb.id) as jumlah_transaksi
    FROM setoran_ke_bank sb
    JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
    JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
    WHERE sb.rekening_tujuan IS NOT NULL 
        AND sb.rekening_tujuan != '' 
        AND TRIM(sb.rekening_tujuan) != ''
    GROUP BY CASE 
        WHEN sb.rekening_tujuan REGEXP '[0-9]{10,}' THEN
            REGEXP_SUBSTR(sb.rekening_tujuan, '[0-9]{10,}')
        ELSE sb.rekening_tujuan
    END
    ORDER BY rekening_number
";

// Fallback jika REGEXP_SUBSTR tidak tersedia (untuk MySQL versi lama)
try {
    $stmt_rekening = $pdo->query($sql_rekening);
    $rekening_list = $stmt_rekening->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback: gunakan PHP untuk ekstraksi nomor rekening
    $sql_rekening_fallback = "
        SELECT 
            sb.rekening_tujuan,
            GROUP_CONCAT(DISTINCT sk.nama_cabang ORDER BY sk.nama_cabang SEPARATOR ' & ') as nama_cabang_combined,
            COUNT(DISTINCT sk.nama_cabang) as jumlah_cabang,
            COUNT(DISTINCT sb.id) as jumlah_transaksi
        FROM setoran_ke_bank sb
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        WHERE sb.rekening_tujuan IS NOT NULL 
            AND sb.rekening_tujuan != '' 
            AND TRIM(sb.rekening_tujuan) != ''
        GROUP BY sb.rekening_tujuan
        ORDER BY sb.rekening_tujuan
    ";
    
    $stmt_rekening = $pdo->query($sql_rekening_fallback);
    $rekening_raw = $stmt_rekening->fetchAll(PDO::FETCH_ASSOC);
    
    // Post-process dengan PHP untuk menggabungkan rekening yang sama
    $rekening_grouped = [];
    foreach ($rekening_raw as $row) {
        // Ekstrak nomor rekening menggunakan regex PHP
        preg_match('/(\d{10,})/', $row['rekening_tujuan'], $matches);
        $rekening_number = isset($matches[1]) ? $matches[1] : $row['rekening_tujuan'];
        
        if (!isset($rekening_grouped[$rekening_number])) {
            $rekening_grouped[$rekening_number] = [
                'rekening_number' => $rekening_number,
                'rekening_full' => $row['rekening_tujuan'],
                'nama_cabang_combined' => $row['nama_cabang_combined'],
                'jumlah_cabang' => $row['jumlah_cabang'],
                'jumlah_transaksi' => $row['jumlah_transaksi']
            ];
        } else {
            // Gabungkan data
            $existing_cabang = explode(' & ', $rekening_grouped[$rekening_number]['nama_cabang_combined']);
            $new_cabang = explode(' & ', $row['nama_cabang_combined']);
            $all_cabang = array_unique(array_merge($existing_cabang, $new_cabang));
            sort($all_cabang);
            
            $rekening_grouped[$rekening_number]['nama_cabang_combined'] = implode(' & ', $all_cabang);
            $rekening_grouped[$rekening_number]['jumlah_cabang'] = count($all_cabang);
            $rekening_grouped[$rekening_number]['jumlah_transaksi'] += $row['jumlah_transaksi'];
        }
    }
    
    $rekening_list = array_values($rekening_grouped);
}

// PERBAIKAN: Query untuk grouped bank deposits dengan ekstraksi nomor rekening
$sql_rekening_summary = "
    SELECT 
        CASE 
            WHEN sb.rekening_tujuan REGEXP '[0-9]{10,}' THEN
                REGEXP_SUBSTR(sb.rekening_tujuan, '[0-9]{10,}')
            ELSE sb.rekening_tujuan
        END as rekening_number,
        GROUP_CONCAT(DISTINCT sk.nama_cabang ORDER BY sk.nama_cabang) as nama_cabang_combined,
        COUNT(DISTINCT sb.id) as total_setoran_bank,
        COUNT(DISTINCT sbd.setoran_keuangan_id) as total_paket_setoran,
        SUM(COALESCE(sb.total_setoran, 0)) as total_nominal,
        MIN(kt.tanggal_transaksi) as tanggal_awal,
        MAX(kt.tanggal_transaksi) as tanggal_akhir,
        COUNT(DISTINCT sk.nama_cabang) as jumlah_cabang
    FROM setoran_ke_bank sb
    JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
    JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
    LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
    WHERE 1=1
";

$params = [];
if ($tanggal_awal && $tanggal_akhir) {
    $sql_rekening_summary .= " AND kt.tanggal_transaksi BETWEEN ? AND ?";
    $params[] = $tanggal_awal;
    $params[] = $tanggal_akhir;
}
if ($cabang !== 'all') {
    $sql_rekening_summary .= " AND sk.nama_cabang = ?";
    $params[] = $cabang;
}
// PERBAIKAN: Filter berdasarkan nomor rekening yang diekstrak
if ($rekening_filter !== 'all') {
    $sql_rekening_summary .= " AND (CASE 
        WHEN sb.rekening_tujuan REGEXP '[0-9]{10,}' THEN
            REGEXP_SUBSTR(sb.rekening_tujuan, '[0-9]{10,}')
        ELSE sb.rekening_tujuan
    END) = ?";
    $params[] = $rekening_filter;
}

$sql_rekening_summary .= " GROUP BY CASE 
    WHEN sb.rekening_tujuan REGEXP '[0-9]{10,}' THEN
        REGEXP_SUBSTR(sb.rekening_tujuan, '[0-9]{10,}')
    ELSE sb.rekening_tujuan
END ORDER BY rekening_number";

// Fallback untuk MySQL versi lama
try {
    $stmt_rekening_summary = $pdo->prepare($sql_rekening_summary);
    $stmt_rekening_summary->execute($params);
    $rekening_summary = $stmt_rekening_summary->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback tanpa REGEXP_SUBSTR
    $sql_rekening_summary_fallback = "
        SELECT 
            sb.rekening_tujuan,
            GROUP_CONCAT(DISTINCT sk.nama_cabang ORDER BY sk.nama_cabang) as nama_cabang_combined,
            COUNT(DISTINCT sb.id) as total_setoran_bank,
            COUNT(DISTINCT sbd.setoran_keuangan_id) as total_paket_setoran,
            SUM(COALESCE(sb.total_setoran, 0)) as total_nominal,
            MIN(kt.tanggal_transaksi) as tanggal_awal,
            MAX(kt.tanggal_transaksi) as tanggal_akhir,
            COUNT(DISTINCT sk.nama_cabang) as jumlah_cabang
        FROM setoran_ke_bank sb
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
        WHERE 1=1
    ";
    
    $params_fallback = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_rekening_summary_fallback .= " AND kt.tanggal_transaksi BETWEEN ? AND ?";
        $params_fallback[] = $tanggal_awal;
        $params_fallback[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_rekening_summary_fallback .= " AND sk.nama_cabang = ?";
        $params_fallback[] = $cabang;
    }
    
    $sql_rekening_summary_fallback .= " GROUP BY sb.rekening_tujuan ORDER BY sb.rekening_tujuan";
    
    $stmt_rekening_summary = $pdo->prepare($sql_rekening_summary_fallback);
    $stmt_rekening_summary->execute($params_fallback);
    $rekening_summary_raw = $stmt_rekening_summary->fetchAll(PDO::FETCH_ASSOC);
    
    // Post-process untuk menggabungkan rekening yang sama
    $rekening_summary_grouped = [];
    foreach ($rekening_summary_raw as $row) {
        // Skip jika filter rekening tidak cocok
        preg_match('/(\d{10,})/', $row['rekening_tujuan'], $matches);
        $rekening_number = isset($matches[1]) ? $matches[1] : $row['rekening_tujuan'];
        
        if ($rekening_filter !== 'all' && $rekening_number !== $rekening_filter) {
            continue;
        }
        
        if (!isset($rekening_summary_grouped[$rekening_number])) {
            $rekening_summary_grouped[$rekening_number] = [
                'rekening_number' => $rekening_number,
                'nama_cabang_combined' => $row['nama_cabang_combined'],
                'total_setoran_bank' => $row['total_setoran_bank'],
                'total_paket_setoran' => $row['total_paket_setoran'],
                'total_nominal' => $row['total_nominal'],
                'tanggal_awal' => $row['tanggal_awal'],
                'tanggal_akhir' => $row['tanggal_akhir'],
                'jumlah_cabang' => $row['jumlah_cabang']
            ];
        } else {
            // Gabungkan data
            $existing_cabang = explode(', ', $rekening_summary_grouped[$rekening_number]['nama_cabang_combined']);
            $new_cabang = explode(', ', $row['nama_cabang_combined']);
            $all_cabang = array_unique(array_merge($existing_cabang, $new_cabang));
            sort($all_cabang);
            
            $rekening_summary_grouped[$rekening_number]['nama_cabang_combined'] = implode(', ', $all_cabang);
            $rekening_summary_grouped[$rekening_number]['total_setoran_bank'] += $row['total_setoran_bank'];
            $rekening_summary_grouped[$rekening_number]['total_paket_setoran'] += $row['total_paket_setoran'];
            $rekening_summary_grouped[$rekening_number]['total_nominal'] += $row['total_nominal'];
            $rekening_summary_grouped[$rekening_number]['jumlah_cabang'] = count($all_cabang);
            
            // Update tanggal range
            if ($row['tanggal_awal'] < $rekening_summary_grouped[$rekening_number]['tanggal_awal']) {
                $rekening_summary_grouped[$rekening_number]['tanggal_awal'] = $row['tanggal_awal'];
            }
            if ($row['tanggal_akhir'] > $rekening_summary_grouped[$rekening_number]['tanggal_akhir']) {
                $rekening_summary_grouped[$rekening_number]['tanggal_akhir'] = $row['tanggal_akhir'];
            }
        }
    }
    
    $rekening_summary = array_values($rekening_summary_grouped);
}

// PERBAIKAN: Query untuk grand total dengan ekstraksi nomor rekening
$sql_grand_total = "
    SELECT 
        COUNT(DISTINCT sb.id) as total_setoran_bank,
        COUNT(DISTINCT sbd.setoran_keuangan_id) as total_paket_setoran,
        SUM(COALESCE(sb.total_setoran, 0)) as total_nominal,
        COUNT(DISTINCT sk.nama_cabang) as total_cabang
    FROM setoran_ke_bank sb
    JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
    JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
    LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
    WHERE 1=1
";

$params_grand_total = [];
if ($tanggal_awal && $tanggal_akhir) {
    $sql_grand_total .= " AND kt.tanggal_transaksi BETWEEN ? AND ?";
    $params_grand_total[] = $tanggal_awal;
    $params_grand_total[] = $tanggal_akhir;
}
if ($cabang !== 'all') {
    $sql_grand_total .= " AND sk.nama_cabang = ?";
    $params_grand_total[] = $cabang;
}
if ($rekening_filter !== 'all') {
    try {
        $sql_grand_total .= " AND (CASE 
            WHEN sb.rekening_tujuan REGEXP '[0-9]{10,}' THEN
                REGEXP_SUBSTR(sb.rekening_tujuan, '[0-9]{10,}')
            ELSE sb.rekening_tujuan
        END) = ?";
        $params_grand_total[] = $rekening_filter;
    } catch (PDOException $e) {
        // Fallback: akan difilter di PHP
    }
}

$stmt_grand_total = $pdo->prepare($sql_grand_total);
$stmt_grand_total->execute($params_grand_total);
$grand_total_raw = $stmt_grand_total->fetch(PDO::FETCH_ASSOC);

// Jika ada filter rekening dan tidak menggunakan REGEXP_SUBSTR, filter manual
if ($rekening_filter !== 'all' && !isset($grand_total_raw['total_setoran_bank'])) {
    // Fallback: hitung dari data rekening_summary yang sudah difilter
    $grand_total = [
        'total_setoran_bank' => array_sum(array_column($rekening_summary, 'total_setoran_bank')),
        'total_paket_setoran' => array_sum(array_column($rekening_summary, 'total_paket_setoran')),
        'total_nominal' => array_sum(array_column($rekening_summary, 'total_nominal')),
        'total_cabang' => count(array_unique(array_merge(...array_map(function($row) {
            return explode(', ', $row['nama_cabang_combined']);
        }, $rekening_summary))))
    ];
} else {
    $grand_total = $grand_total_raw;
}

// PERBAIKAN: Set default values untuk mencegah null
$grand_total['total_setoran_bank'] = $grand_total['total_setoran_bank'] ?? 0;
$grand_total['total_paket_setoran'] = $grand_total['total_paket_setoran'] ?? 0;
$grand_total['total_nominal'] = $grand_total['total_nominal'] ?? 0;
$grand_total['total_cabang'] = $grand_total['total_cabang'] ?? 0;

// Handle detail view for specific rekening
$rekening_detail = null;
$rekening_detail_data = [];
$detail_total = ['total_nominal_closing' => 0, 'total_nominal_setor' => 0];
if (isset($_GET['rekening_detail'])) {
    $rekening_tujuan = urldecode($_GET['rekening_detail']);
    
    // PERBAIKAN: Query detail dengan filter berdasarkan nomor rekening yang diekstrak
    $sql_detail = "
        SELECT 
            kt.tanggal_transaksi,
            sk.nama_cabang,
            SUM(CASE WHEN sk.status = 'closing' THEN COALESCE(sk.jumlah_diterima, 0) ELSE 0 END) as nominal_closing,
            SUM(COALESCE(sk.jumlah_diterima, 0)) as nominal_setor,
            COUNT(DISTINCT sk.kode_setoran) as jumlah_kode_setoran,
            GROUP_CONCAT(DISTINCT kt.kode_transaksi ORDER BY kt.kode_transaksi SEPARATOR ', ') as kode_transaksi_list
        FROM setoran_ke_bank sb
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
        WHERE (CASE 
            WHEN sb.rekening_tujuan REGEXP '[0-9]{10,}' THEN
                REGEXP_SUBSTR(sb.rekening_tujuan, '[0-9]{10,}')
            ELSE sb.rekening_tujuan
        END) = ?
    ";

    $params_detail = [$rekening_tujuan];
    
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_detail .= " AND kt.tanggal_transaksi BETWEEN ? AND ?";
        $params_detail[] = $tanggal_awal;
        $params_detail[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_detail .= " AND sk.nama_cabang = ?";
        $params_detail[] = $cabang;
    }

    $sql_detail .= " GROUP BY kt.tanggal_transaksi, sk.nama_cabang ORDER BY kt.tanggal_transaksi DESC, sk.nama_cabang";

    // Fallback untuk MySQL versi lama
    try {
        $stmt_detail = $pdo->prepare($sql_detail);
        $stmt_detail->execute($params_detail);
        $rekening_detail_data = $stmt_detail->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback tanpa REGEXP_SUBSTR
        $sql_detail_fallback = "
            SELECT 
                kt.tanggal_transaksi,
                sk.nama_cabang,
                sb.rekening_tujuan,
                SUM(CASE WHEN sk.status = 'closing' THEN COALESCE(sk.jumlah_diterima, 0) ELSE 0 END) as nominal_closing,
                SUM(COALESCE(sk.jumlah_diterima, 0)) as nominal_setor,
                COUNT(DISTINCT sk.kode_setoran) as jumlah_kode_setoran,
                GROUP_CONCAT(DISTINCT kt.kode_transaksi ORDER BY kt.kode_transaksi SEPARATOR ', ') as kode_transaksi_list
            FROM setoran_ke_bank sb
            JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
            JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
            LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
            WHERE 1=1
        ";
        
        $params_detail_fallback = [];
        if ($tanggal_awal && $tanggal_akhir) {
            $sql_detail_fallback .= " AND kt.tanggal_transaksi BETWEEN ? AND ?";
            $params_detail_fallback[] = $tanggal_awal;
            $params_detail_fallback[] = $tanggal_akhir;
        }
        if ($cabang !== 'all') {
            $sql_detail_fallback .= " AND sk.nama_cabang = ?";
            $params_detail_fallback[] = $cabang;
        }
        
        $sql_detail_fallback .= " GROUP BY kt.tanggal_transaksi, sk.nama_cabang, sb.rekening_tujuan ORDER BY kt.tanggal_transaksi DESC, sk.nama_cabang";
        
        $stmt_detail = $pdo->prepare($sql_detail_fallback);
        $stmt_detail->execute($params_detail_fallback);
        $rekening_detail_raw = $stmt_detail->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter berdasarkan nomor rekening yang diekstrak
        $rekening_detail_data = [];
        foreach ($rekening_detail_raw as $row) {
            preg_match('/(\d{10,})/', $row['rekening_tujuan'], $matches);
            $rekening_number = isset($matches[1]) ? $matches[1] : $row['rekening_tujuan'];
            
            if ($rekening_number === $rekening_tujuan) {
                $rekening_detail_data[] = [
                    'tanggal_transaksi' => $row['tanggal_transaksi'],
                    'nama_cabang' => $row['nama_cabang'],
                    'nominal_closing' => $row['nominal_closing'],
                    'nominal_setor' => $row['nominal_setor'],
                    'jumlah_kode_setoran' => $row['jumlah_kode_setoran']
                ];
            }
        }
    }

    // Calculate total for the detail
    $sql_detail_total = "
        SELECT 
            SUM(CASE WHEN sk.status = 'closing' THEN COALESCE(sk.jumlah_diterima, 0) ELSE 0 END) as total_nominal_closing,
            SUM(COALESCE(sk.jumlah_diterima, 0)) as total_nominal_setor
        FROM setoran_ke_bank sb
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
        WHERE (CASE 
            WHEN sb.rekening_tujuan REGEXP '[0-9]{10,}' THEN
                REGEXP_SUBSTR(sb.rekening_tujuan, '[0-9]{10,}')
            ELSE sb.rekening_tujuan
        END) = ?
    ";
    $params_total = [$rekening_tujuan];
    
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_detail_total .= " AND kt.tanggal_transaksi BETWEEN ? AND ?";
        $params_total[] = $tanggal_awal;
        $params_total[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_detail_total .= " AND sk.nama_cabang = ?";
        $params_total[] = $cabang;
    }

    try {
        $stmt_total = $pdo->prepare($sql_detail_total);
        $stmt_total->execute($params_total);
        $detail_total = $stmt_total->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback: calculate from filtered data
        $detail_total = [
            'total_nominal_closing' => array_sum(array_column($rekening_detail_data, 'nominal_closing')),
            'total_nominal_setor' => array_sum(array_column($rekening_detail_data, 'nominal_setor'))
        ];
    }

    // PERBAIKAN: Set default values untuk detail_total
    $detail_total['total_nominal_closing'] = $detail_total['total_nominal_closing'] ?? 0;
    $detail_total['total_nominal_setor'] = $detail_total['total_nominal_setor'] ?? 0;

    $rekening_detail = $rekening_tujuan;
}

// NEW: Handle transaction detail view for specific date and branch
$transaction_detail = null;
$transaction_detail_data = [];
if (isset($_GET['transaction_detail']) && isset($_GET['tanggal_detail']) && isset($_GET['cabang_detail']) && isset($_GET['rekening_detail_trans'])) {
    $tanggal_detail = urldecode($_GET['tanggal_detail']);
    $cabang_detail = urldecode($_GET['cabang_detail']);
    $rekening_detail_trans = urldecode($_GET['rekening_detail_trans']);
    
    // Query untuk mendapatkan detail transaksi per tanggal dengan kode transaksi
    $sql_transaction_detail = "
        SELECT 
            sk.kode_setoran,
            sk.tanggal_setoran,
            sk.tanggal_closing,
            sk.jumlah_setoran,
            sk.jumlah_diterima,
            sk.selisih_setoran,
            sk.nama_pengantar,
            sk.status,
            kt.kode_transaksi,
            kt.setoran_real,
            kt.omset,
            kt.selisih_setoran as selisih_kasir,
            kt.kode_karyawan,
            mk.nama_karyawan,
            kt.tanggal_transaksi,
            ROW_NUMBER() OVER (PARTITION BY sk.nama_cabang, kt.tanggal_transaksi ORDER BY kt.kode_transaksi) as nomor_urut_transaksi
        FROM setoran_ke_bank sb
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
        LEFT JOIN masterkeys mk ON kt.kode_karyawan = mk.kode_karyawan
        WHERE (CASE 
            WHEN sb.rekening_tujuan REGEXP '[0-9]{10,}' THEN
                REGEXP_SUBSTR(sb.rekening_tujuan, '[0-9]{10,}')
            ELSE sb.rekening_tujuan
        END) = ?
        AND kt.tanggal_transaksi = ?
        AND sk.nama_cabang = ?
        ORDER BY kt.kode_transaksi, sk.kode_setoran
    ";
    
    try {
        $stmt_transaction_detail = $pdo->prepare($sql_transaction_detail);
        $stmt_transaction_detail->execute([$rekening_detail_trans, $tanggal_detail, $cabang_detail]);
        $transaction_detail_data = $stmt_transaction_detail->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback tanpa REGEXP_SUBSTR
        $sql_transaction_detail_fallback = "
            SELECT 
                sk.kode_setoran,
                sk.tanggal_setoran,
                sk.tanggal_closing,
                sk.jumlah_setoran,
                sk.jumlah_diterima,
                sk.selisih_setoran,
                sk.nama_pengantar,
                sk.status,
                kt.kode_transaksi,
                kt.setoran_real,
                kt.omset,
                kt.selisih_setoran as selisih_kasir,
                kt.kode_karyawan,
                mk.nama_karyawan,
                kt.tanggal_transaksi,
                sb.rekening_tujuan
            FROM setoran_ke_bank sb
            JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
            JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
            LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
            LEFT JOIN masterkeys mk ON kt.kode_karyawan = mk.kode_karyawan
            WHERE kt.tanggal_transaksi = ?
            AND sk.nama_cabang = ?
            ORDER BY kt.kode_transaksi, sk.kode_setoran
        ";
        
        $stmt_transaction_detail = $pdo->prepare($sql_transaction_detail_fallback);
        $stmt_transaction_detail->execute([$tanggal_detail, $cabang_detail]);
        $transaction_detail_raw = $stmt_transaction_detail->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter berdasarkan nomor rekening
        $transaction_detail_data = [];
        foreach ($transaction_detail_raw as $row) {
            preg_match('/(\d{10,})/', $row['rekening_tujuan'], $matches);
            $rekening_number = isset($matches[1]) ? $matches[1] : $row['rekening_tujuan'];
            
            if ($rekening_number === $rekening_detail_trans) {
                unset($row['rekening_tujuan']); // Remove this field from output
                $transaction_detail_data[] = $row;
            }
        }
    }
    
    $transaction_detail = [
        'tanggal' => $tanggal_detail,
        'cabang' => $cabang_detail,
        'rekening' => $rekening_detail_trans
    ];
}

// PERBAIKAN: Function formatRupiah dengan handling null values
function formatRupiah($angka) {
    if ($angka === null || $angka === '') {
        return 'Rp 0';
    }
    return 'Rp ' . number_format(floatval($angka), 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Setoran Bank per Rekening</title>
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
        }

        .info-tag {
            background: var(--background-light);
            padding: 8px 12px;
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-dark);
        }

        .filter-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .form-inline {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 14px;
        }

        .form-control {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            background: white;
            min-width: 120px;
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
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
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
            background-color: #117a8b;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .content-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .content-header {
            background: var(--background-light);
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .content-header h3 {
            margin: 0;
            color: var(--text-dark);
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .content-body {
            padding: 24px;
        }

        .table-enhanced {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .table th {
            background: linear-gradient(135deg, var(--primary-color), #0056b3);
            color: white;
            font-weight: 600;
            padding: 12px 16px;
            text-align: left;
            font-size: 13px;
            border: none;
        }

        .table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background: rgba(0,123,255,0.05);
        }

        .closing-summary {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }

        .closing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .closing-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .closing-label {
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .closing-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .closing-value.amount {
            color: var(--success-color);
            font-size: 18px;
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal.show {
            display: flex;
        }

        .modal-dialog {
            background: white;
            border-radius: 16px;
            max-width: 95%;
            max-height: 95%;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
            margin: 0;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
            padding: 0;
            margin-left: 10px;
            text-decoration: none;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
            font-style: italic;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 6px;
            text-transform: uppercase;
        }

        .badge-info {
            background-color: var(--info-color);
            color: white;
        }

        .badge-success {
            background-color: var(--success-color);
            color: white;
        }

        .table-responsive {
            overflow-x: auto;
        }

        @media (max-width: 768px) {
            .sidebar.active {
                transform: translateX(0);
            }

            .form-inline {
                flex-direction: column;
                align-items: stretch;
            }

            .form-group {
                width: 100%;
            }

            .closing-grid {
                grid-template-columns: 1fr;
            }

            .modal-dialog {
                max-width: 95%;
                margin: 10px;
            }

            .table th,
            .table td {
                padding: 8px;
                font-size: 12px;
            }
        }

        @media print {
            body * {
                visibility: hidden;
            }
            .content-card, .content-card * {
                visibility: visible;
            }
            .content-card {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                box-shadow: none;
                border: none;
            }
            .no-print {
                display: none !important;
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
                <p style="color: var(--text-muted); font-size: 12px;">Super Admin</p>
            </div>
        </div>

        <div class="welcome-card">
            <h1><i class="fas fa-university"></i> Rekap Setoran Bank per Rekening</h1>
            <p style="color: var(--text-muted); margin-bottom: 0;">Rekapitulasi setoran ke bank yang dikelompokkan berdasarkan rekening tujuan dan cabang dengan detail transaksi per tanggal.</p>
            <div class="info-tags">
                <div class="info-tag">User: <?php echo htmlspecialchars($username); ?></div>
                <div class="info-tag">Role: Super Admin</div>
                <div class="info-tag">Tanggal: <?php echo date('d M Y'); ?></div>
            </div>
        </div>

        <!-- Filter -->
        <div class="filter-card">
            <form action="" method="POST" class="form-inline">
                <div class="form-group">
                    <label class="form-label">Tanggal Awal:</label>
                    <input type="date" name="tanggal_awal" class="form-control" value="<?php echo htmlspecialchars($tanggal_awal); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Tanggal Akhir:</label>
                    <input type="date" name="tanggal_akhir" class="form-control" value="<?php echo htmlspecialchars($tanggal_akhir); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Cabang:</label>
                    <select name="cabang" class="form-control">
                        <option value="all">Semua Cabang</option>
                        <?php foreach ($cabang_list as $nama_cabang): ?>
                            <option value="<?php echo htmlspecialchars($nama_cabang); ?>" <?php echo $cabang == $nama_cabang ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($nama_cabang)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- PERBAIKAN: Filter Rekening dengan ekstraksi nomor rekening -->
                <div class="form-group">
                    <label class="form-label">Rekening:</label>
                    <select name="rekening_filter" class="form-control">
                        <option value="all">Semua Rekening</option>
                        <?php foreach ($rekening_list as $rekening): ?>
                            <option value="<?php echo htmlspecialchars($rekening['rekening_number']); ?>" 
                                    <?php echo $rekening_filter == $rekening['rekening_number'] ? 'selected' : ''; ?>>
                                <?php 
                                    echo htmlspecialchars($rekening['rekening_number']);
                                    echo ' (' . $rekening['nama_cabang_combined'] . ')';
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="setoran_keuangan.php?tab=bank_history" class="btn btn-secondary no-print">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
            </form>
        </div>

        <!-- Grand Total Summary -->
        <div class="closing-summary">
            <h4><i class="fas fa-calculator"></i> Total Rekapitulasi Keseluruhan</h4>
            <div class="closing-grid">
                <div class="closing-item">
                    <div class="closing-label">Total Setoran Bank</div>
                    <div class="closing-value"><?php echo $grand_total['total_setoran_bank']; ?> transaksi</div>
                </div>
                <div class="closing-item">
                    <div class="closing-label">Total Paket Setoran</div>
                    <div class="closing-value"><?php echo $grand_total['total_paket_setoran']; ?> paket</div>
                </div>
                <div class="closing-item">
                    <div class="closing-label">Total Nominal</div>
                    <div class="closing-value amount"><?php echo formatRupiah($grand_total['total_nominal']); ?></div>
                </div>
                <div class="closing-item">
                    <div class="closing-label">Jumlah Cabang</div>
                    <div class="closing-value"><?php echo $grand_total['total_cabang']; ?> cabang</div>
                </div>
            </div>
        </div>

        <!-- Rekening Summary -->
        <div class="content-card">
            <div class="content-header">
                <h3><i class="fas fa-university"></i> Rekap Setoran per Rekening</h3>
                <div class="export-buttons no-print">
                    <button onclick="exportToExcel('rekening_summary')" class="btn btn-success btn-sm">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                </div>
            </div>
            <div class="content-body">
                <div class="table-enhanced">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Rekening Tujuan</th>
                                <th>Periode</th>
                                <th>Cabang</th>
                                <th>Jumlah Transaksi</th>
                                <th>Jumlah Paket</th>
                                <th>Total Nominal</th>
                                <th class="no-print">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rekening_summary): ?>
                                <?php foreach ($rekening_summary as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['rekening_number']); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($row['tanggal_awal'])); ?> - <?php echo date('d/m/Y', strtotime($row['tanggal_akhir'])); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($row['nama_cabang_combined']); ?>
                                            <?php if ($row['jumlah_cabang'] > 1): ?>
                                                <span style="color: var(--info-color); font-size: 12px;">(<?php echo $row['jumlah_cabang']; ?> cabang)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;"><?php echo $row['total_setoran_bank']; ?> transaksi</td>
                                        <td style="text-align: center;"><?php echo $row['total_paket_setoran']; ?> paket</td>
                                        <td style="text-align: right; font-weight: 600; color: var(--success-color);"><?php echo formatRupiah($row['total_nominal']); ?></td>
                                        <td class="no-print">
                                            <a href="?rekening_detail=<?php echo urlencode($row['rekening_number']); ?>&tanggal_awal=<?php echo $tanggal_awal; ?>&tanggal_akhir=<?php echo $tanggal_akhir; ?>&cabang=<?php echo $cabang; ?>&rekening_filter=<?php echo $rekening_filter; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye"></i> Detail
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="no-data">
                                        <i class="fas fa-file-alt"></i><br>
                                        Tidak ada data rekap setoran ditemukan
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Detail Modal for Specific Rekening -->
        <?php if ($rekening_detail): ?>
            <div class="modal show">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-university"></i> Detail Setoran - <?php echo htmlspecialchars($rekening_detail); ?>
                                <span style="color: var(--info-color); font-size: 14px;">(Semua Cabang)</span>
                            </h5>
                            <a href="?" class="btn-close">×</a>
                        </div>
                        <div class="modal-body">
                            <div style="margin-bottom: 20px; font-weight: bold;">
                                DETAIL SETORAN CLOSING<br>
                                REKENING: <?php echo htmlspecialchars($rekening_detail); ?><br>
                                TAMPILAN: Gabungan Semua Cabang untuk Rekening Ini<br>
                                TANGGAL AWAL: <?php echo $tanggal_awal ? date('d-M-Y', strtotime($tanggal_awal)) : 'N/A'; ?>    
                                TANGGAL AKHIR: <?php echo $tanggal_akhir ? date('d-M-Y', strtotime($tanggal_akhir)) : 'N/A'; ?>    
                                CABANG FILTER: <?php echo $cabang !== 'all' ? htmlspecialchars(ucfirst($cabang)) : 'ALL'; ?><br>
                                REKENING FILTER: <?php echo $rekening_filter !== 'all' ? htmlspecialchars($rekening_filter) : 'ALL'; ?>
                            </div>
                            <div class="table-enhanced">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Cabang</th>
                                            <th>Jml Kode Setoran</th>
                                            <th>Kode Transaksi Setoran</th>
                                            <th>Nominal Closing</th>
                                            <th>Nominal Setor</th>
                                            <th class="no-print">Detail Transaksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($rekening_detail_data): ?>
                                            <?php
                                            // Kelompokkan data berdasarkan cabang
                                            $grouped_by_cabang = [];
                                            foreach ($rekening_detail_data as $row) {
                                                $grouped_by_cabang[$row['nama_cabang']][] = $row;
                                            }
                                            // Urutkan nama cabang secara alfabetis
                                            ksort($grouped_by_cabang, SORT_NATURAL | SORT_FLAG_CASE);
                                            ?>
                                            <?php foreach ($grouped_by_cabang as $cabang_nama => $rows): ?>
                                                <!-- Header per Cabang -->
                                                <tr>
                                                    <td colspan="7" style="background: var(--background-light); font-weight: 600; color: var(--text-dark);">
                                                        Cabang: <?php echo htmlspecialchars($cabang_nama); ?>
                                                    </td>
                                                </tr>
                                                <?php
                                                // Urutkan per tanggal transaksi desc dalam cabang
                                                $sub_jumlah_paket = 0;
                                                $sub_nominal_closing = 0;
                                                $sub_nominal_setor = 0;
                                                usort($rows, function($a, $b) {
                                                    return strcmp($b['tanggal_transaksi'], $a['tanggal_transaksi']);
                                                });
                                                ?>
                                                <?php foreach ($rows as $detail): ?>
                                                    <?php
                                                        $sub_jumlah_paket += (int)($detail['jumlah_kode_setoran'] ?? 0);
                                                        $sub_nominal_closing += (float)($detail['nominal_closing'] ?? 0);
                                                        $sub_nominal_setor += (float)($detail['nominal_setor'] ?? 0);
                                                    ?>
                                                    <tr>
                                                        <td><?php echo date('d-M-Y', strtotime($detail['tanggal_transaksi'])); ?></td>
                                                        <td><?php echo htmlspecialchars($detail['nama_cabang']); ?></td>
                                                        <td style="text-align: center;">
                                                            <span class="badge badge-info"><?php echo $detail['jumlah_kode_setoran']; ?> paket</span>
                                                        </td>
                                                        <td style="font-size: 12px; max-width: 200px; word-wrap: break-word;">
                                                            <?php 
                                                            $kode_list = $detail['kode_transaksi_list'] ?? '';
                                                            if (strlen($kode_list) > 50) {
                                                                echo '<span title="' . htmlspecialchars($kode_list) . '">' . htmlspecialchars(substr($kode_list, 0, 47)) . '...</span>';
                                                            } else {
                                                                echo htmlspecialchars($kode_list);
                                                            }
                                                            ?>
                                                        </td>
                                                        <td style="text-align: right;"><?php echo formatRupiah($detail['nominal_closing']); ?></td>
                                                        <td style="text-align: right;"><?php echo formatRupiah($detail['nominal_setor']); ?></td>
                                                        <td class="no-print">
                                                            <a href="?transaction_detail=1&tanggal_detail=<?php echo urlencode($detail['tanggal_transaksi']); ?>&cabang_detail=<?php echo urlencode($detail['nama_cabang']); ?>&rekening_detail_trans=<?php echo urlencode($rekening_detail); ?>&tanggal_awal=<?php echo $tanggal_awal; ?>&tanggal_akhir=<?php echo $tanggal_akhir; ?>&cabang=<?php echo $cabang; ?>&rekening_filter=<?php echo $rekening_filter; ?>" class="btn btn-info btn-sm">
                                                                <i class="fas fa-list"></i> Lihat Transaksi
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <!-- Subtotal per Cabang -->
                                                <tr style="font-weight: 600; background-color: #eef6ff;">
                                                    <td colspan="2" style="text-align: right;">Subtotal Cabang</td>
                                                    <td style="text-align: center;"><span class="badge badge-primary"><?php echo $sub_jumlah_paket; ?> paket</span></td>
                                                    <td style="text-align: center; font-style: italic; color: var(--text-muted);">Total dari semua transaksi</td>
                                                    <td style="text-align: right;"><?php echo formatRupiah($sub_nominal_closing); ?></td>
                                                    <td style="text-align: right;"><?php echo formatRupiah($sub_nominal_setor); ?></td>
                                                    <td class="no-print"></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <!-- Total Row -->
                                            <tr style="font-weight: bold; background-color: #f8f9fa;">
                                                <td colspan="4">Total Keseluruhan</td>
                                                <td style="text-align: right;"><?php echo formatRupiah($detail_total['total_nominal_closing']); ?></td>
                                                <td style="text-align: right;"><?php echo formatRupiah($detail_total['total_nominal_setor']); ?></td>
                                                <td class="no-print"></td>
                                            </tr>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="no-data">Tidak ada data detail setoran ditemukan</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button onclick="exportToExcel('rekening_detail', 'rekening_detail=<?php echo urlencode($rekening_detail); ?>')" class="btn btn-success btn-sm">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                            <a href="?" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Tutup
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- NEW: Transaction Detail Modal -->
        <?php if ($transaction_detail): ?>
            <div class="modal show">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-list"></i> Detail Transaksi
                                <span style="color: var(--info-color); font-size: 14px;">
                                    - <?php echo date('d-M-Y', strtotime($transaction_detail['tanggal'])); ?> 
                                    | <?php echo htmlspecialchars($transaction_detail['cabang']); ?>
                                    | Rek: <?php echo htmlspecialchars($transaction_detail['rekening']); ?>
                                </span>
                            </h5>
                            <a href="?rekening_detail=<?php echo urlencode($transaction_detail['rekening']); ?>&tanggal_awal=<?php echo $tanggal_awal; ?>&tanggal_akhir=<?php echo $tanggal_akhir; ?>&cabang=<?php echo $cabang; ?>&rekening_filter=<?php echo $rekening_filter; ?>" class="btn-close">×</a>
                        </div>
                        <div class="modal-body">
                            <div style="margin-bottom: 20px; font-weight: bold;">
                                DETAIL TRANSAKSI PER TANGGAL<br>
                                TANGGAL: <?php echo date('d-M-Y', strtotime($transaction_detail['tanggal'])); ?><br>
                                CABANG: <?php echo htmlspecialchars($transaction_detail['cabang']); ?><br>
                                REKENING: <?php echo htmlspecialchars($transaction_detail['rekening']); ?>
                            </div>
                            
                            <?php if ($transaction_detail_data): ?>
                                <!-- Display transactions with sequential numbering per branch -->
                                <div class="table-responsive">
                                    <table class="table" style="margin-bottom: 0;">
                                        <thead>
                                            <tr style="background: var(--primary-color); color: white;">
                                                <th>No Transaksi</th>
                                                <th>Kode Transaksi</th>
                                                <th>Nama User</th>
                                                <th>Cabang</th>
                                                <th>Tanggal</th>
                                                <th>Status</th>
                                                <th>Status Setoran/Serah Terima</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $no_transaksi = 1;
                                            foreach ($transaction_detail_data as $trans): 
                                            ?>
                                                <tr>
                                                    <td style="text-align: center;">
                                                        <span class="badge badge-info"><?php echo $no_transaksi; ?></span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($trans['kode_transaksi'] ?? 'N/A'); ?></strong>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($trans['nama_karyawan'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($cabang_detail); ?></td>
                                                    <td><?php echo date('Y-m-d', strtotime($trans['tanggal_transaksi'])); ?></td>
                                                    <td>
                                                        <span class="badge badge-success">✓ end proses</span>
                                                    </td>
                                                    <td>
                                                        <span style="color: #ffc107;">Belum Disetor</span>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-secondary btn-sm" style="padding: 4px 8px; font-size: 11px;">View</button>
                                                    </td>
                                                </tr>
                                            <?php 
                                            $no_transaksi++; 
                                            endforeach; 
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Detail breakdown by kode_setoran (collapsed by default) -->
                                <div style="margin-top: 20px;">
                                    <h6 style="cursor: pointer; background: var(--background-light); padding: 10px; border-radius: 5px;" onclick="toggleDetails()">
                                        <i class="fas fa-chevron-down" id="toggleIcon"></i> Detail Breakdown per Kode Setoran
                                    </h6>
                                    <div id="detailBreakdown" style="display: none; margin-top: 15px;">
                                        <?php 
                                        // Group by kode_setoran for detailed breakdown
                                        $grouped_transactions = [];
                                        foreach ($transaction_detail_data as $trans) {
                                            $grouped_transactions[$trans['kode_setoran']][] = $trans;
                                        }
                                        ?>
                                        
                                        <?php foreach ($grouped_transactions as $kode_setoran => $transactions): ?>
                                            <div style="margin-bottom: 20px; border: 1px solid var(--border-color); border-radius: 8px; padding: 15px;">
                                                <h6 style="background: var(--info-color); color: white; padding: 8px 12px; margin: -15px -15px 15px -15px; border-radius: 8px 8px 0 0;">
                                                    <i class="fas fa-receipt"></i> Kode Setoran: <?php echo htmlspecialchars($kode_setoran); ?>
                                                </h6>
                                                
                                                <div style="margin-bottom: 15px;">
                                                    <?php $first_trans = $transactions[0]; ?>
                                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 10px;">
                                                        <div><strong>Tanggal Setoran:</strong> <?php echo date('d-M-Y', strtotime($first_trans['tanggal_setoran'])); ?></div>
                                                        <div><strong>Tanggal Closing:</strong> <?php echo $first_trans['tanggal_closing'] ? date('d-M-Y', strtotime($first_trans['tanggal_closing'])) : 'N/A'; ?></div>
                                                        <div><strong>Pengantar:</strong> <?php echo htmlspecialchars($first_trans['nama_pengantar']); ?></div>
                                                        <div><strong>Status:</strong> <span class="badge badge-success"><?php echo htmlspecialchars($first_trans['status']); ?></span></div>
                                                    </div>
                                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                                                        <div><strong>Jumlah Setoran:</strong> <?php echo formatRupiah($first_trans['jumlah_setoran']); ?></div>
                                                        <div><strong>Jumlah Diterima:</strong> <?php echo formatRupiah($first_trans['jumlah_diterima']); ?></div>
                                                        <div><strong>Selisih:</strong> <?php echo formatRupiah($first_trans['selisih_setoran']); ?></div>
                                                    </div>
                                                </div>
                                                
                                                <div class="table-responsive">
                                                    <table class="table" style="margin-bottom: 0;">
                                                        <thead>
                                                            <tr style="background: var(--background-light);">
                                                                <th>Kode Transaksi</th>
                                                                <th>Nama Karyawan</th>
                                                                <th>Setoran Real</th>
                                                                <th>Omset</th>
                                                                <th>Selisih Kasir</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($transactions as $trans): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($trans['kode_transaksi'] ?? 'N/A'); ?></td>
                                                                    <td><?php echo htmlspecialchars($trans['nama_karyawan'] ?? 'N/A'); ?></td>
                                                                    <td style="text-align: right;"><?php echo formatRupiah($trans['setoran_real'] ?? 0); ?></td>
                                                                    <td style="text-align: right;"><?php echo formatRupiah($trans['omset'] ?? 0); ?></td>
                                                                    <td style="text-align: right;"><?php echo formatRupiah($trans['selisih_kasir'] ?? 0); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="no-data">
                                    <i class="fas fa-file-alt"></i><br>
                                    Tidak ada detail transaksi ditemukan untuk tanggal dan cabang ini
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button onclick="exportToExcel('transaction_detail', 'transaction_detail=1&tanggal_detail=<?php echo urlencode($transaction_detail['tanggal']); ?>&cabang_detail=<?php echo urlencode($transaction_detail['cabang']); ?>&rekening_detail_trans=<?php echo urlencode($transaction_detail['rekening']); ?>')" class="btn btn-success btn-sm">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                            <a href="?rekening_detail=<?php echo urlencode($transaction_detail['rekening']); ?>&tanggal_awal=<?php echo $tanggal_awal; ?>&tanggal_akhir=<?php echo $tanggal_akhir; ?>&cabang=<?php echo $cabang; ?>&rekening_filter=<?php echo $rekening_filter; ?>" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Kembali ke Detail Rekening
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Currency formatter utility
        function formatCurrency(amount) {
            return 'Rp ' + amount.toLocaleString('id-ID');
        }

        // Export functionality
        function exportToExcel(type, additionalParams = '') {
            let url = `export_excel_rekap_bank.php?type=${type}`;
            if (additionalParams) {
                url += '&' + additionalParams;
            }
            const urlParams = new URLSearchParams(window.location.search);
            const relevantParams = ['tanggal_awal', 'tanggal_akhir', 'cabang', 'rekening_filter'];
            relevantParams.forEach(param => {
                if (urlParams.has(param)) {
                    url += `&${param}=${urlParams.get(param)}`;
                }
            });
            window.open(url, '_blank');
        }

        function exportToCSV(type, additionalParams = '') {
            let url = `export_csv.php?type=${type}`;
            if (additionalParams) {
                url += '&' + additionalParams;
            }
            const urlParams = new URLSearchParams(window.location.search);
            const relevantParams = ['tanggal_awal', 'tanggal_akhir', 'cabang', 'rekening_filter'];
            relevantParams.forEach(param => {
                if (urlParams.has(param)) {
                    url += `&${param}=${urlParams.get(param)}`;
                }
            });
            window.open(url, '_blank');
        }

        // Close modal on outside click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    if (window.location.search.includes('transaction_detail')) {
                        // If transaction detail modal, go back to rekening detail
                        const urlParams = new URLSearchParams(window.location.search);
                        const rekening = urlParams.get('rekening_detail_trans');
                        const tanggal_awal = urlParams.get('tanggal_awal');
                        const tanggal_akhir = urlParams.get('tanggal_akhir');
                        const cabang = urlParams.get('cabang');
                        const rekening_filter = urlParams.get('rekening_filter');
                        window.location.href = `?rekening_detail=${rekening}&tanggal_awal=${tanggal_awal}&tanggal_akhir=${tanggal_akhir}&cabang=${cabang}&rekening_filter=${rekening_filter}`;
                    } else {
                        window.location.href = '?';
                    }
                }
            });
        });

        // Table search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tables = document.querySelectorAll('.table');
            tables.forEach((table, index) => {
                const rows = table.querySelectorAll('tbody tr');
                if (rows.length > 10) {
                    const searchBox = document.createElement('input');
                    searchBox.type = 'text';
                    searchBox.placeholder = 'Cari dalam tabel...';
                    searchBox.className = 'form-control';
                    searchBox.style.marginBottom = '15px';
                    searchBox.style.maxWidth = '300px';
                    searchBox.addEventListener('input', function() {
                        const searchTerm = this.value.toLowerCase();
                        rows.forEach(row => {
                            const text = row.textContent.toLowerCase();
                            row.style.display = text.includes(searchTerm) ? '' : 'none';
                        });
                    });
                    table.parentNode.insertBefore(searchBox, table);
                }
            });
        });

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

            const minWidth = 200;
            sidebar.style.width = maxWidth > minWidth ? `${maxWidth + 20}px` : `${minWidth}px`;
            document.querySelector('.main-content').style.marginLeft = sidebar.style.width;
        }

        // Run on page load and window resize
        window.addEventListener('load', adjustSidebarWidth);
        window.addEventListener('resize', adjustSidebarWidth);

        // Toggle details function
        function toggleDetails() {
            const detailDiv = document.getElementById('detailBreakdown');
            const icon = document.getElementById('toggleIcon');
            
            if (detailDiv.style.display === 'none' || detailDiv.style.display === '') {
                detailDiv.style.display = 'block';
                icon.className = 'fas fa-chevron-up';
            } else {
                detailDiv.style.display = 'none';
                icon.className = 'fas fa-chevron-down';
            }
        }
    </script>
</body>
</html>