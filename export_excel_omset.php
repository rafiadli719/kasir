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

// Set variabel untuk filter (sama seperti di detail_omset.php)
$tanggal_awal = $_GET['tanggal_awal'] ?? null;
$tanggal_akhir = $_GET['tanggal_akhir'] ?? null;
$cabang = $_GET['cabang'] ?? null;
$sort_by = $_GET['sort_by'] ?? 'tanggal_transaksi';
$sort_order = $_GET['sort_order'] ?? 'DESC';

// Validasi sort_by untuk keamanan
$allowed_sort_columns = [
    'tanggal_transaksi', 'nama_cabang', 'kode_transaksi', 
    'omset_penjualan', 'omset_servis', 'total_omset'
];

if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'tanggal_transaksi';
}

if (!in_array(strtoupper($sort_order), ['ASC', 'DESC'])) {
    $sort_order = 'DESC';
}

// Query untuk mendapatkan data omset (sama seperti di detail_omset.php)
$query = "
    SELECT 
        kt.kode_transaksi,
        kt.tanggal_transaksi,
        kt.nama_cabang,
        kt.kode_karyawan,
        COALESCE(dp.jumlah_penjualan, 0) as omset_penjualan,
        COALESCE(ds.jumlah_servis, 0) as omset_servis_cs,
        COALESCE(kt.selisih_setoran, 0) as selisih_closing,
        (COALESCE(ds.jumlah_servis, 0) + COALESCE(kt.selisih_setoran, 0)) as omset_servis_final,
        (COALESCE(dp.jumlah_penjualan, 0) + COALESCE(ds.jumlah_servis, 0) + COALESCE(kt.selisih_setoran, 0)) as total_omset,
        kt.status,
        kt.tanggal_closing,
        kt.jam_closing
    FROM kasir_transactions kt
    LEFT JOIN data_penjualan dp ON kt.kode_transaksi = dp.kode_transaksi
    LEFT JOIN data_servis ds ON kt.kode_transaksi = ds.kode_transaksi
    WHERE 1 = 1
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

if ($sort_by !== 'tanggal_transaksi') {
    $query .= ", tanggal_transaksi " . strtoupper($sort_order);
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$omset_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_records = count($omset_data);
$total_omset_penjualan = array_sum(array_column($omset_data, 'omset_penjualan'));
$total_omset_servis = array_sum(array_column($omset_data, 'omset_servis_final'));
$total_omset_keseluruhan = array_sum(array_column($omset_data, 'total_omset'));
$total_selisih_closing = array_sum(array_column($omset_data, 'selisih_closing'));

// Generate filter description for filename and header
$filter_desc = [];
if ($tanggal_awal && $tanggal_akhir) {
    $filter_desc[] = "Periode " . date('d-m-Y', strtotime($tanggal_awal)) . " s/d " . date('d-m-Y', strtotime($tanggal_akhir));
}
if ($cabang) {
    $filter_desc[] = "Cabang " . ucfirst($cabang);
}

$filter_text = !empty($filter_desc) ? implode(", ", $filter_desc) : "Semua Data";

// Generate filename
$filename = "Detail_Omset_" . date('Y-m-d_H-i-s');
if ($tanggal_awal && $tanggal_akhir) {
    $filename .= "_" . date('dmY', strtotime($tanggal_awal)) . "-" . date('dmY', strtotime($tanggal_akhir));
}
if ($cabang) {
    $filename .= "_" . str_replace(' ', '_', ucfirst($cabang));
}
$filename .= ".xls";

// Set headers untuk download Excel
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Start output
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="ProgId" content="Excel.Sheet">
    <meta name="Generator" content="Microsoft Excel 11">
    <!--[if gte mso 9]>
    <xml>
        <x:ExcelWorkbook>
            <x:ExcelWorksheets>
                <x:ExcelWorksheet>
                    <x:Name>Detail Omset</x:Name>
                    <x:WorksheetOptions>
                        <x:DisplayGridlines/>
                        <x:Print>
                            <x:ValidPrinterInfo/>
                            <x:PaperSizeIndex>9</x:PaperSizeIndex>
                            <x:HorizontalResolution>600</x:HorizontalResolution>
                            <x:VerticalResolution>600</x:VerticalResolution>
                        </x:Print>
                    </x:WorksheetOptions>
                </x:ExcelWorksheet>
            </x:ExcelWorksheets>
        </x:ExcelWorkbook>
    </xml>
    <![endif]-->
    <style>
        .header { 
            font-weight: bold; 
            background-color: #4CAF50; 
            color: white; 
            text-align: center;
            border: 1px solid #000;
        }
        .subheader { 
            font-weight: bold; 
            background-color: #E8F5E8; 
            text-align: center;
            border: 1px solid #000;
        }
        .data { 
            border: 1px solid #000; 
            text-align: left;
        }
        .number { 
            border: 1px solid #000; 
            text-align: right;
            mso-number-format: "#,##0";
        }
        .currency { 
            border: 1px solid #000; 
            text-align: right;
            mso-number-format: "#,##0";
        }
        .center { 
            border: 1px solid #000; 
            text-align: center;
        }
        .total-row { 
            font-weight: bold; 
            background-color: #FFF2CC;
            border: 2px solid #000;
        }
        .summary-header {
            font-weight: bold; 
            background-color: #D4EDDA; 
            border: 1px solid #000;
            text-align: center;
        }
        .summary-data {
            background-color: #F8F9FA; 
            border: 1px solid #000;
            text-align: right;
            mso-number-format: "#,##0";
        }
    </style>
</head>
<body>
    <table border="1" cellpadding="2" cellspacing="0">
        <!-- Header Informasi -->
        <tr>
            <td colspan="12" class="header" style="font-size: 16px; height: 30px;">
                LAPORAN DETAIL OMSET PENJUALAN & SERVIS
            </td>
        </tr>
        <tr>
            <td colspan="12" class="subheader">
                PT. FITMOTOR INDONESIA - SISTEM MANAJEMEN KEUANGAN
            </td>
        </tr>
        <tr>
            <td colspan="12" class="center">
                Filter: <?php echo htmlspecialchars($filter_text); ?>
            </td>
        </tr>
        <tr>
            <td colspan="12" class="center">
                Diunduh oleh: <?php echo htmlspecialchars($username); ?> (<?php echo htmlspecialchars(ucfirst($role)); ?>) pada <?php echo date('d/m/Y H:i:s'); ?>
            </td>
        </tr>
        <tr>
            <td colspan="12" class="center">
                Sorting: <?php echo ucfirst($sort_by) . ' ' . $sort_order; ?>
            </td>
        </tr>
        
        <!-- Baris kosong -->
        <tr><td colspan="12"></td></tr>
        
        <!-- Summary Statistics -->
        <tr>
            <td colspan="12" class="summary-header">RINGKASAN DATA OMSET</td>
        </tr>
        <tr>
            <td colspan="3" class="summary-header">Total Transaksi</td>
            <td class="summary-data"><?php echo number_format($total_records); ?></td>
            <td colspan="3" class="summary-header">Total Omset Penjualan</td>
            <td class="summary-data">Rp <?php echo number_format($total_omset_penjualan, 0, ',', '.'); ?></td>
            <td colspan="2" class="summary-header">Total Omset Servis</td>
            <td class="summary-data">Rp <?php echo number_format($total_omset_servis, 0, ',', '.'); ?></td>
            <td></td>
        </tr>
        <tr>
            <td colspan="3" class="summary-header">Total Selisih Closing</td>
            <td class="summary-data">Rp <?php echo number_format($total_selisih_closing, 0, ',', '.'); ?></td>
            <td colspan="3" class="summary-header">Total Omset Keseluruhan</td>
            <td class="summary-data"><strong>Rp <?php echo number_format($total_omset_keseluruhan, 0, ',', '.'); ?></strong></td>
            <td colspan="4"></td>
        </tr>
        
        <!-- Baris kosong -->
        <tr><td colspan="12"></td></tr>
        
        <!-- Header Tabel -->
        <tr>
            <td class="header">No</td>
            <td class="header">Tanggal Transaksi</td>
            <td class="header">Kode Transaksi</td>
            <td class="header">Nama Cabang</td>
            <td class="header">Kode Karyawan</td>
            <td class="header">Omset Penjualan (Rp)</td>
            <td class="header">Omset Servis CS (Rp)</td>
            <td class="header">Selisih Closing (Rp)</td>
            <td class="header">Omset Servis Final (Rp)</td>
            <td class="header">Total Omset (Rp)</td>
            <td class="header">Status</td>
            <td class="header">Tanggal Closing</td>
        </tr>
        
        <!-- Data Rows -->
        <?php if (count($omset_data) > 0): ?>
            <?php foreach ($omset_data as $index => $data): ?>
                <tr>
                    <td class="center"><?php echo $index + 1; ?></td>
                    <td class="center"><?php echo date('d/m/Y', strtotime($data['tanggal_transaksi'])); ?></td>
                    <td class="data"><?php echo htmlspecialchars($data['kode_transaksi']); ?></td>
                    <td class="data"><?php echo htmlspecialchars(ucfirst($data['nama_cabang'])); ?></td>
                    <td class="center"><?php echo htmlspecialchars($data['kode_karyawan'] ?? '-'); ?></td>
                    <td class="currency"><?php echo number_format($data['omset_penjualan'], 0, ',', '.'); ?></td>
                    <td class="currency"><?php echo number_format($data['omset_servis_cs'], 0, ',', '.'); ?></td>
                    <td class="currency"><?php echo number_format($data['selisih_closing'], 0, ',', '.'); ?></td>
                    <td class="currency"><?php echo number_format($data['omset_servis_final'], 0, ',', '.'); ?></td>
                    <td class="currency"><strong><?php echo number_format($data['total_omset'], 0, ',', '.'); ?></strong></td>
                    <td class="center"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $data['status']))); ?></td>
                    <td class="center">
                        <?php echo $data['tanggal_closing'] ? date('d/m/Y', strtotime($data['tanggal_closing'])) : 'Belum Closing'; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            
            <!-- Total Row -->
            <tr class="total-row">
                <td colspan="5" class="center"><strong>TOTAL KESELURUHAN</strong></td>
                <td class="currency"><strong><?php echo number_format($total_omset_penjualan, 0, ',', '.'); ?></strong></td>
                <td class="currency"><strong><?php echo number_format(array_sum(array_column($omset_data, 'omset_servis_cs')), 0, ',', '.'); ?></strong></td>
                <td class="currency"><strong><?php echo number_format($total_selisih_closing, 0, ',', '.'); ?></strong></td>
                <td class="currency"><strong><?php echo number_format($total_omset_servis, 0, ',', '.'); ?></strong></td>
                <td class="currency"><strong><?php echo number_format($total_omset_keseluruhan, 0, ',', '.'); ?></strong></td>
                <td colspan="2" class="center"><strong><?php echo number_format($total_records); ?> Transaksi</strong></td>
            </tr>
        <?php else: ?>
            <tr>
                <td colspan="12" class="center">Tidak ada data untuk filter yang dipilih</td>
            </tr>
        <?php endif; ?>
        
        <!-- Footer Informasi -->
        <tr><td colspan="12"></td></tr>
        <tr>
            <td colspan="12" class="center">
                <strong>KETERANGAN:</strong><br>
                • Omset Penjualan: Data yang diisikan oleh CS dari transaksi penjualan<br>
                • Omset Servis CS: Data yang diisikan oleh CS dari transaksi servis<br>
                • Selisih Closing: Selisih yang terjadi saat proses closing kasir<br>
                • Omset Servis Final: Omset Servis CS + Selisih Closing<br>
                • Total Omset: Penjualan + Servis Final untuk perhitungan rugi laba
            </td>
        </tr>
        <tr>
            <td colspan="12" class="center">
                <em>Laporan ini dibuat secara otomatis oleh Sistem Manajemen Keuangan PT. Fitmotor Indonesia</em>
            </td>
        </tr>
    </table>
</body>
</html>