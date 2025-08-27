<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'config.php';

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Check if user is super admin
if (!isset($_SESSION['kode_karyawan']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../../login_dashboard/login.php');
    exit();
}

$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';

// Get filter parameters from GET or SESSION
$tanggal_awal = $_GET['tanggal_awal'] ?? $_SESSION['filter_tanggal_awal'] ?? date('Y-m-d', strtotime('-30 days'));
$tanggal_akhir = $_GET['tanggal_akhir'] ?? $_SESSION['filter_tanggal_akhir'] ?? date('Y-m-d');
$cabang = $_GET['cabang'] ?? $_SESSION['filter_cabang'] ?? 'all';
$jenis = $_GET['jenis'] ?? $_SESSION['filter_jenis'] ?? 'all';

// Query for pemasukan
if ($jenis == 'all' || $jenis == 'pemasukan') {
    $query_pemasukan = "SELECT pp.*, u.nama_karyawan, ma.arti as nama_akun 
                       FROM pemasukan_pusat pp 
                       JOIN users u ON pp.kode_karyawan = u.kode_karyawan 
                       JOIN master_akun ma ON pp.kode_akun = ma.kode_akun 
                       WHERE pp.tanggal BETWEEN ? AND ?";
    
    $params_pemasukan = [$tanggal_awal, $tanggal_akhir];
    
    if ($cabang && $cabang !== 'all') {
        $query_pemasukan .= " AND pp.cabang = ?";
        $params_pemasukan[] = $cabang;
    }
    
    $query_pemasukan .= " ORDER BY pp.tanggal DESC, pp.waktu DESC";
    $stmt_pemasukan = $pdo->prepare($query_pemasukan);
    $stmt_pemasukan->execute($params_pemasukan);
    $result_pemasukan = $stmt_pemasukan->fetchAll(PDO::FETCH_ASSOC);
}

// Query for pengeluaran
if ($jenis == 'all' || $jenis == 'pengeluaran') {
    $query_pengeluaran = "SELECT pp.*, u.nama_karyawan, ma.arti as nama_akun 
                         FROM pengeluaran_pusat pp 
                         JOIN users u ON pp.kode_karyawan = u.kode_karyawan 
                         JOIN master_akun ma ON pp.kode_akun = ma.kode_akun 
                         WHERE pp.tanggal BETWEEN ? AND ?";
    
    $params_pengeluaran = [$tanggal_awal, $tanggal_akhir];
    
    if ($cabang && $cabang !== 'all') {
        $query_pengeluaran .= " AND pp.cabang = ?";
        $params_pengeluaran[] = $cabang;
    }
    
    $query_pengeluaran .= " ORDER BY pp.tanggal DESC, pp.waktu DESC";
    $stmt_pengeluaran = $pdo->prepare($query_pengeluaran);
    $stmt_pengeluaran->execute($params_pengeluaran);
    $result_pengeluaran = $stmt_pengeluaran->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate totals
$total_pemasukan = 0;
$total_pengeluaran = 0;

if (isset($result_pemasukan)) {
    foreach ($result_pemasukan as $row) {
        $total_pemasukan += $row['jumlah'];
    }
}

if (isset($result_pengeluaran)) {
    foreach ($result_pengeluaran as $row) {
        $total_pengeluaran += $row['jumlah'];
    }
}

$saldo = $total_pemasukan - $total_pengeluaran;

// Create filename
$filename = 'Laporan_Keuangan_Pusat_' . str_replace('-', '', $tanggal_awal) . '_' . str_replace('-', '', $tanggal_akhir) . '_' . date('YmdHis') . '.xls';

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Cache-Control: max-age=1');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: cache, must-revalidate');
header('Pragma: public');

// Function to escape Excel special characters
function excelEscape($value) {
    if (is_numeric($value)) {
        return $value;
    }
    return '"' . str_replace('"', '""', $value) . '"';
}

// Start Excel content with XML declaration for better Excel compatibility
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
echo ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
echo ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";

// Styles
echo '<Styles>' . "\n";
echo '<Style ss:ID="Header">' . "\n";
echo '<Font ss:Bold="1" ss:Size="16"/>' . "\n";
echo '<Alignment ss:Horizontal="Center"/>' . "\n";
echo '</Style>' . "\n";
echo '<Style ss:ID="SubHeader">' . "\n";
echo '<Font ss:Bold="1" ss:Size="12"/>' . "\n";
echo '</Style>' . "\n";
echo '<Style ss:ID="TableHeader">' . "\n";
echo '<Font ss:Bold="1"/>' . "\n";
echo '<Interior ss:Color="#E0E0E0" ss:Pattern="Solid"/>' . "\n";
echo '<Borders>' . "\n";
echo '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
echo '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
echo '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
echo '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
echo '</Borders>' . "\n";
echo '</Style>' . "\n";
echo '<Style ss:ID="Currency">' . "\n";
echo '<NumberFormat ss:Format="#,##0"/>' . "\n";
echo '<Borders>' . "\n";
echo '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
echo '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
echo '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
echo '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
echo '</Borders>' . "\n";
echo '</Style>' . "\n";
echo '<Style ss:ID="TableData">' . "\n";
echo '<Borders>' . "\n";
echo '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
echo '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
echo '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
echo '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' . "\n";
echo '</Borders>' . "\n";
echo '</Style>' . "\n";
echo '</Styles>' . "\n";

// Worksheet
echo '<Worksheet ss:Name="Laporan Keuangan Pusat">' . "\n";
echo '<Table>' . "\n";

$row = 1;

// Title
echo '<Row ss:Index="' . $row . '">' . "\n";
echo '<Cell ss:MergeAcross="10" ss:StyleID="Header"><Data ss:Type="String">LAPORAN KEUANGAN PUSAT</Data></Cell>' . "\n";
echo '</Row>' . "\n";
$row += 2;

// Report Info
echo '<Row ss:Index="' . $row . '">' . "\n";
echo '<Cell><Data ss:Type="String">Periode:</Data></Cell>' . "\n";
echo '<Cell><Data ss:Type="String">' . date('d/m/Y', strtotime($tanggal_awal)) . ' - ' . date('d/m/Y', strtotime($tanggal_akhir)) . '</Data></Cell>' . "\n";
echo '</Row>' . "\n";
$row++;

echo '<Row ss:Index="' . $row . '">' . "\n";
echo '<Cell><Data ss:Type="String">Cabang:</Data></Cell>' . "\n";
echo '<Cell><Data ss:Type="String">' . ($cabang === 'all' ? 'Semua Cabang' : ucfirst($cabang)) . '</Data></Cell>' . "\n";
echo '</Row>' . "\n";
$row++;

echo '<Row ss:Index="' . $row . '">' . "\n";
echo '<Cell><Data ss:Type="String">Jenis:</Data></Cell>' . "\n";
echo '<Cell><Data ss:Type="String">' . ($jenis === 'all' ? 'Semua Jenis' : ucfirst($jenis)) . '</Data></Cell>' . "\n";
echo '</Row>' . "\n";
$row++;

echo '<Row ss:Index="' . $row . '">' . "\n";
echo '<Cell><Data ss:Type="String">Tanggal Export:</Data></Cell>' . "\n";
echo '<Cell><Data ss:Type="String">' . date('d/m/Y H:i:s') . '</Data></Cell>' . "\n";
echo '</Row>' . "\n";
$row++;

echo '<Row ss:Index="' . $row . '">' . "\n";
echo '<Cell><Data ss:Type="String">Export oleh:</Data></Cell>' . "\n";
echo '<Cell><Data ss:Type="String">' . htmlspecialchars($username) . ' (Super Admin)</Data></Cell>' . "\n";
echo '</Row>' . "\n";
$row += 2;

// Summary
echo '<Row ss:Index="' . $row . '">' . "\n";
echo '<Cell ss:StyleID="SubHeader"><Data ss:Type="String">RINGKASAN:</Data></Cell>' . "\n";
echo '</Row>' . "\n";
$row++;

if ($jenis == 'all' || $jenis == 'pemasukan') {
    echo '<Row ss:Index="' . $row . '">' . "\n";
    echo '<Cell><Data ss:Type="String">Total Pemasukan:</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">Rp ' . number_format($total_pemasukan, 0, ',', '.') . '</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">' . (isset($result_pemasukan) ? count($result_pemasukan) : 0) . ' transaksi</Data></Cell>' . "\n";
    echo '</Row>' . "\n";
    $row++;
}

if ($jenis == 'all' || $jenis == 'pengeluaran') {
    echo '<Row ss:Index="' . $row . '">' . "\n";
    echo '<Cell><Data ss:Type="String">Total Pengeluaran:</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">Rp ' . number_format($total_pengeluaran, 0, ',', '.') . '</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">' . (isset($result_pengeluaran) ? count($result_pengeluaran) : 0) . ' transaksi</Data></Cell>' . "\n";
    echo '</Row>' . "\n";
    $row++;
}

if ($jenis == 'all') {
    echo '<Row ss:Index="' . $row . '">' . "\n";
    echo '<Cell><Data ss:Type="String">Saldo:</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">Rp ' . number_format($saldo, 0, ',', '.') . '</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">' . ($saldo >= 0 ? 'Surplus' : 'Defisit') . '</Data></Cell>' . "\n";
    echo '</Row>' . "\n";
    $row++;
}

$row += 2;

// Pemasukan Data
if (($jenis == 'all' || $jenis == 'pemasukan') && isset($result_pemasukan) && count($result_pemasukan) > 0) {
    echo '<Row ss:Index="' . $row . '">' . "\n";
    echo '<Cell ss:StyleID="SubHeader"><Data ss:Type="String">DATA PEMASUKAN:</Data></Cell>' . "\n";
    echo '</Row>' . "\n";
    $row++;
    
    // Headers
    echo '<Row ss:Index="' . $row . '">' . "\n";
    $headers = ['No', 'Tanggal', 'Waktu', 'Cabang', 'Kode Akun', 'Nama Akun', 'Jumlah', 'Keterangan', 'Input Oleh'];
    foreach ($headers as $header) {
        echo '<Cell ss:StyleID="TableHeader"><Data ss:Type="String">' . $header . '</Data></Cell>' . "\n";
    }
    echo '</Row>' . "\n";
    $row++;
    
    // Data
    $no = 1;
    foreach ($result_pemasukan as $data) {
        echo '<Row ss:Index="' . $row . '">' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="Number">' . $no++ . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . date('d/m/Y', strtotime($data['tanggal'])) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars($data['waktu']) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars(ucfirst($data['cabang'])) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars($data['kode_akun']) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars($data['nama_akun']) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="Currency"><Data ss:Type="Number">' . $data['jumlah'] . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars($data['keterangan']) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars($data['nama_karyawan']) . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
        $row++;
    }
    $row += 2;
}

// Pengeluaran Data
if (($jenis == 'all' || $jenis == 'pengeluaran') && isset($result_pengeluaran) && count($result_pengeluaran) > 0) {
    echo '<Row ss:Index="' . $row . '">' . "\n";
    echo '<Cell ss:StyleID="SubHeader"><Data ss:Type="String">DATA PENGELUARAN:</Data></Cell>' . "\n";
    echo '</Row>' . "\n";
    $row++;
    
    // Headers
    echo '<Row ss:Index="' . $row . '">' . "\n";
    $headers = ['No', 'Tanggal', 'Waktu', 'Cabang', 'Kode Akun', 'Nama Akun', 'Kategori', 'Jumlah', 'Keterangan', 'Umur Pakai', 'Input Oleh'];
    foreach ($headers as $header) {
        echo '<Cell ss:StyleID="TableHeader"><Data ss:Type="String">' . $header . '</Data></Cell>' . "\n";
    }
    echo '</Row>' . "\n";
    $row++;
    
    // Data
    $no = 1;
    foreach ($result_pengeluaran as $data) {
        echo '<Row ss:Index="' . $row . '">' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="Number">' . $no++ . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . date('d/m/Y', strtotime($data['tanggal'])) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars($data['waktu']) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars(ucfirst($data['cabang'])) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars($data['kode_akun']) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars($data['nama_akun']) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars($data['kategori']) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="Currency"><Data ss:Type="Number">' . $data['jumlah'] . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars($data['keterangan']) . '</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars($data['umur_pakai']) . ' bulan</Data></Cell>' . "\n";
        echo '<Cell ss:StyleID="TableData"><Data ss:Type="String">' . htmlspecialchars($data['nama_karyawan']) . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
        $row++;
    }
}

echo '</Table>' . "\n";
echo '</Worksheet>' . "\n";
echo '</Workbook>' . "\n";
exit;
?>