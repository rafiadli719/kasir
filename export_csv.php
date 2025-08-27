<?php
session_start();

if (!isset($_SESSION['kode_karyawan']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$type = $_GET['type'] ?? '';
$tab = $_GET['tab'] ?? '';
$rekening_filter = $_GET['rekening_filter'] ?? 'all';
$tanggal_awal = $_GET['tanggal_awal'] ?? '';
$tanggal_akhir = $_GET['tanggal_akhir'] ?? '';
$cabang = $_GET['cabang'] ?? 'all';

function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Set CSV headers
$filename = '';
$data = [];
$headers = [];

if ($type == 'receipt') {
    // Receipt export
    $receipt_data = json_decode(base64_decode($_GET['data']), true);
    
    $filename = 'Bukti_Penerimaan_Setoran_' . date('Y-m-d_H-i-s') . '.csv';
    $headers = ['Kode Setoran', 'Cabang', 'Tanggal Setoran', 'Pengantar', 'Status'];
    
    foreach ($receipt_data as $item) {
        $data[] = [
            $item['kode_setoran'],
            $item['nama_cabang'],
            date('d/m/Y', strtotime($item['tanggal_setoran'])),
            $item['nama_pengantar'],
            'Diterima'
        ];
    }

} elseif ($type == 'terima') {
    // Terima setoran export
    $sql = "SELECT sk.*, COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan
            FROM setoran_keuangan sk
            LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
            WHERE sk.status = 'Sedang Dibawa Kurir'
            ORDER BY sk.tanggal_setoran DESC";
    
    $stmt = $pdo->query($sql);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filename = 'Setoran_Menunggu_Penerimaan_' . date('Y-m-d_H-i-s') . '.csv';
    $headers = ['Tanggal', 'Kode Setoran', 'Cabang', 'Kasir', 'Pengantar', 'Status', 'Dibuat'];
    
    foreach ($result as $row) {
        $data[] = [
            date('d/m/Y', strtotime($row['tanggal_setoran'])),
            $row['kode_setoran'],
            ucfirst($row['nama_cabang']),
            $row['nama_karyawan'],
            $row['nama_pengantar'],
            'Sedang Dibawa Kurir',
            date('d/m/Y H:i', strtotime($row['created_at']))
        ];
    }

} elseif ($type == 'setor_bank') {
    // Setor bank export
    $sql = "SELECT sk.*, COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan
            FROM setoran_keuangan sk
            LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
            WHERE sk.status = 'Validasi Keuangan OK'";
    
    $params = [];
    if ($rekening_filter !== 'all') {
        $sql .= " AND sk.kode_cabang = (SELECT kode_cabang FROM master_rekening_cabang WHERE id = ?)";
        $params[] = $rekening_filter;
    }
    
    $sql .= " ORDER BY sk.tanggal_setoran DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filename = 'Setoran_Siap_Setor_Bank_' . date('Y-m-d_H-i-s') . '.csv';
    $headers = ['Tanggal', 'Kode Setoran', 'Cabang', 'Nominal Sistem', 'Jumlah Diterima', 'Selisih', 'Status', 'Kasir'];
    
    foreach ($result as $row) {
        $jumlah_diterima = $row['jumlah_diterima'] ?? $row['jumlah_setoran'];
        $selisih = $row['selisih_setoran'] ?? 0;
        
        $data[] = [
            date('d/m/Y', strtotime($row['tanggal_setoran'])),
            $row['kode_setoran'],
            ucfirst($row['nama_cabang']),
            formatRupiah($row['jumlah_setoran']),
            formatRupiah($jumlah_diterima),
            formatRupiah($selisih),
            'Validasi Keuangan OK',
            $row['nama_karyawan']
        ];
    }

} elseif ($type == 'validasi') {
    // Validasi fisik export
    $sql = "SELECT kt.*, sk.nama_cabang, sk.tanggal_setoran, sk.nama_pengantar, 
                   COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan
            FROM kasir_transactions kt
            LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
            LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
            WHERE kt.deposit_status = 'Diterima Staff Keuangan'
            ORDER BY sk.tanggal_setoran DESC, kt.tanggal_transaksi DESC";
    
    $stmt = $pdo->query($sql);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filename = 'Transaksi_Perlu_Validasi_' . date('Y-m-d_H-i-s') . '.csv';
    $headers = ['Tanggal', 'Kode Transaksi', 'Kode Setoran', 'Cabang', 'Kasir', 'Nominal Transaksi', 'Status', 'Pengantar'];
    
    foreach ($result as $row) {
        $data[] = [
            date('d/m/Y', strtotime($row['tanggal_transaksi'])),
            $row['kode_transaksi'],
            $row['kode_setoran'],
            ucfirst($row['nama_cabang']),
            $row['nama_karyawan'],
            formatRupiah($row['setoran_real']),
            'Diterima Staff Keuangan',
            $row['nama_pengantar']
        ];
    }

} elseif ($type == 'validasi_selisih') {
    // Validasi selisih export
    $sql = "SELECT kt.*, sk.nama_cabang, sk.tanggal_setoran, sk.nama_pengantar, 
                   COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan
            FROM kasir_transactions kt
            LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
            LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
            WHERE kt.deposit_status = 'Validasi Keuangan SELISIH'
            ORDER BY sk.tanggal_setoran DESC, kt.tanggal_transaksi DESC";
    
    $stmt = $pdo->query($sql);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if validation columns exist
    $validation_columns_exist = false;
    try {
        $stmt_check = $pdo->query("SHOW COLUMNS FROM kasir_transactions LIKE 'jumlah_diterima_fisik'");
        $validation_columns_exist = $stmt_check->rowCount() > 0;
    } catch (Exception $e) {
        // Column doesn't exist
    }
    
    $filename = 'Transaksi_Selisih_Validasi_' . date('Y-m-d_H-i-s') . '.csv';
    $headers = ['Tanggal', 'Kode Transaksi', 'Kode Setoran', 'Cabang', 'Kasir', 'Nominal Sistem', 'Diterima Fisik', 'Selisih', 'Catatan'];
    
    foreach ($result as $row) {
        $diterima_fisik = ($validation_columns_exist && isset($row['jumlah_diterima_fisik'])) 
            ? $row['jumlah_diterima_fisik'] 
            : $row['setoran_real'];
        $selisih = ($validation_columns_exist && isset($row['selisih_fisik'])) 
            ? $row['selisih_fisik'] 
            : 0;
        
        $data[] = [
            date('d/m/Y', strtotime($row['tanggal_transaksi'])),
            $row['kode_transaksi'],
            $row['kode_setoran'],
            ucfirst($row['nama_cabang']),
            $row['nama_karyawan'],
            formatRupiah($row['setoran_real']),
            formatRupiah($diterima_fisik),
            formatRupiah($selisih),
            $row['catatan_validasi'] ?? ''
        ];
    }

} elseif ($type == 'bank_history') {
    // Bank history export
    $sql = "SELECT sb.*, 
                   GROUP_CONCAT(DISTINCT c.nama_cabang) as cabang_names,
                   COUNT(sbd.setoran_keuangan_id) as total_setoran_count,
                   u.nama_karyawan as created_by_name
            FROM setoran_ke_bank sb
            JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
            JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
            JOIN cabang c ON sk.kode_cabang = c.kode_cabang
            LEFT JOIN users u ON sb.created_by = u.kode_karyawan
            WHERE 1=1";
    
    $params = [];
    
    if ($tanggal_awal && $tanggal_akhir) {
        $sql .= " AND sb.tanggal_setoran BETWEEN ? AND ?";
        $params[] = $tanggal_awal;
        $params[] = $tanggal_akhir;
    }
    
    if ($cabang !== 'all') {
        $sql .= " AND c.nama_cabang = ?";
        $params[] = $cabang;
    }
    
    $sql .= " GROUP BY sb.id ORDER BY sb.tanggal_setoran DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filename = 'Riwayat_Setoran_Bank_' . date('Y-m-d_H-i-s') . '.csv';
    $headers = ['Tanggal Setor', 'Kode Setoran Bank', 'Cabang Terkait', 'Rekening Tujuan', 'Total Setoran', 'Jumlah Paket', 'Disetor Oleh', 'Metode'];
    
    foreach ($result as $row) {
        $data[] = [
            date('d/m/Y', strtotime($row['tanggal_setoran'])),
            $row['kode_setoran'],
            $row['cabang_names'],
            $row['rekening_tujuan'],
            formatRupiah($row['total_setoran']),
            $row['total_setoran_count'] . ' paket',
            $row['created_by_name'],
            $row['metode_setoran']
        ];
    }

} else {
    die('Invalid export type');
}

// Generate CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Output CSV with BOM for Excel compatibility
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// Write headers
fputcsv($output, $headers, ';');

// Write data
foreach ($data as $row) {
    fputcsv($output, $row, ';');
}

// Add footer
fputcsv($output, [], ';');
fputcsv($output, ['Generated on: ' . date('d/m/Y H:i:s')], ';');
fputcsv($output, ['By: ' . ($_SESSION['nama_karyawan'] ?? 'System')], ';');

fclose($output);
exit;