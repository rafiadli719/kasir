<?php
session_start();
require_once 'vendor/autoload.php'; // PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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

// Create new Spreadsheet object
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set document properties
$spreadsheet->getProperties()
    ->setCreator("FIT MOTOR - Keuangan Pusat")
    ->setLastModifiedBy("System")
    ->setTitle("Export Data Setoran")
    ->setSubject("Data Setoran Keuangan")
    ->setDescription("Export data setoran keuangan dari sistem FIT MOTOR")
    ->setKeywords("setoran keuangan export excel")
    ->setCategory("Financial Report");

$currentRow = 1;

// Header styling function
function setHeaderStyle($sheet, $range) {
    $sheet->getStyle($range)->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
            'size' => 12
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '007bff']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000']
            ]
        ]
    ]);
}

// Data styling function
function setDataStyle($sheet, $range) {
    $sheet->getStyle($range)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'CCCCCC']
            ]
        ],
        'alignment' => [
            'vertical' => Alignment::VERTICAL_CENTER
        ]
    ]);
}

if ($type == 'receipt') {
    // Receipt export
    $data = json_decode(base64_decode($_GET['data']), true);
    
    $sheet->setTitle('Bukti Penerimaan');
    
    // Title
    $sheet->setCellValue('A1', 'BUKTI PENERIMAAN SETORAN');
    $sheet->mergeCells('A1:E1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A2', 'FIT MOTOR - KEUANGAN PUSAT');
    $sheet->mergeCells('A2:E2');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $currentRow = 4;
    
    // Receipt info
    $sheet->setCellValue('A' . $currentRow, 'Tanggal Penerimaan:');
    $sheet->setCellValue('B' . $currentRow, date('d/m/Y H:i'));
    $currentRow++;
    
    $sheet->setCellValue('A' . $currentRow, 'Diterima Oleh:');
    $sheet->setCellValue('B' . $currentRow, $_SESSION['nama_karyawan'] ?? 'Unknown');
    $currentRow++;
    
    $sheet->setCellValue('A' . $currentRow, 'Jumlah Setoran:');
    $sheet->setCellValue('B' . $currentRow, count($data) . ' paket');
    $currentRow += 2;
    
    // Headers
    $headers = ['Kode Setoran', 'Cabang', 'Tanggal Setoran', 'Pengantar', 'Status'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $currentRow, $header);
        $col++;
    }
    setHeaderStyle($sheet, 'A' . $currentRow . ':E' . $currentRow);
    $currentRow++;
    
    // Data
    foreach ($data as $item) {
        $sheet->setCellValue('A' . $currentRow, $item['kode_setoran']);
        $sheet->setCellValue('B' . $currentRow, $item['nama_cabang']);
        $sheet->setCellValue('C' . $currentRow, date('d/m/Y', strtotime($item['tanggal_setoran'])));
        $sheet->setCellValue('D' . $currentRow, $item['nama_pengantar']);
        $sheet->setCellValue('E' . $currentRow, 'Diterima');
        $currentRow++;
    }
    
    setDataStyle($sheet, 'A' . ($currentRow - count($data)) . ':E' . ($currentRow - 1));
    
    $filename = 'Bukti_Penerimaan_Setoran_' . date('Y-m-d_H-i-s') . '.xlsx';

} elseif ($type == 'terima') {
    // Terima setoran export
    $sql = "SELECT sk.*, COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan
            FROM setoran_keuangan sk
            LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
            WHERE sk.status = 'Sedang Dibawa Kurir'
            ORDER BY sk.tanggal_setoran DESC";
    
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sheet->setTitle('Terima Setoran');
    
    // Title
    $sheet->setCellValue('A1', 'DATA SETORAN MENUNGGU PENERIMAAN');
    $sheet->mergeCells('A1:G1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $currentRow = 3;
    
    // Headers
    $headers = ['Tanggal', 'Kode Setoran', 'Cabang', 'Kasir', 'Pengantar', 'Status', 'Dibuat'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $currentRow, $header);
        $col++;
    }
    setHeaderStyle($sheet, 'A' . $currentRow . ':G' . $currentRow);
    $currentRow++;
    
    // Data
    foreach ($data as $row) {
        $sheet->setCellValue('A' . $currentRow, date('d/m/Y', strtotime($row['tanggal_setoran'])));
        $sheet->setCellValue('B' . $currentRow, $row['kode_setoran']);
        $sheet->setCellValue('C' . $currentRow, ucfirst($row['nama_cabang']));
        $sheet->setCellValue('D' . $currentRow, $row['nama_karyawan']);
        $sheet->setCellValue('E' . $currentRow, $row['nama_pengantar']);
        $sheet->setCellValue('F' . $currentRow, 'Sedang Dibawa Kurir');
        $sheet->setCellValue('G' . $currentRow, date('d/m/Y H:i', strtotime($row['created_at'])));
        $currentRow++;
    }
    
    if (count($data) > 0) {
        setDataStyle($sheet, 'A4:G' . ($currentRow - 1));
    }
    
    $filename = 'Setoran_Menunggu_Penerimaan_' . date('Y-m-d_H-i-s') . '.xlsx';

} elseif ($type == 'setor_bank') {
    // Setor bank export - Updated to match setoran_keuangan.php filtering
    $sql = "SELECT sk.*, COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan
            FROM setoran_keuangan sk
            LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
            WHERE sk.status = 'Validasi Keuangan OK'";
    
    $params = [];
    
    // Add rekening filter for setor_bank - filter by cabang matching rekening with same no_rekening
    if ($rekening_filter !== 'all' && !empty($rekening_filter)) {
        // Handle multiple rekening IDs (comma separated)
        $rekening_ids = explode(',', $rekening_filter);
        $placeholders = array_fill(0, count($rekening_ids), '?');
        $sql .= " AND sk.kode_cabang IN (
            SELECT kode_cabang FROM master_rekening_cabang 
            WHERE id IN (" . implode(',', $placeholders) . ") AND status = 'active'
        )";
        $params = array_merge($params, $rekening_ids);
    }
    
    if ($tanggal_awal && $tanggal_akhir) {
        $sql .= " AND sk.tanggal_setoran BETWEEN ? AND ?";
        $params[] = $tanggal_awal;
        $params[] = $tanggal_akhir;
    }
    
    if ($cabang !== 'all') {
        $sql .= " AND sk.nama_cabang = ?";
        $params[] = $cabang;
    }
    
    $sql .= " ORDER BY sk.tanggal_setoran DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sheet->setTitle('Siap Setor Bank');
    
    // Title
    $sheet->setCellValue('A1', 'DATA SETORAN SIAP DISETOR KE BANK');
    $sheet->mergeCells('A1:H1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $currentRow = 3;
    
    // Headers
    $headers = ['Tanggal', 'Kode Setoran', 'Cabang', 'Nominal Sistem', 'Jumlah Diterima', 'Selisih', 'Status', 'Kasir'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $currentRow, $header);
        $col++;
    }
    setHeaderStyle($sheet, 'A' . $currentRow . ':H' . $currentRow);
    $currentRow++;
    
    // Data
    $total_setoran = 0;
    foreach ($data as $row) {
        $jumlah_diterima = $row['jumlah_diterima'] ?? $row['jumlah_setoran'];
        $selisih = $row['selisih_setoran'] ?? 0;
        $total_setoran += $jumlah_diterima;
        
        $sheet->setCellValue('A' . $currentRow, date('d/m/Y', strtotime($row['tanggal_setoran'])));
        $sheet->setCellValue('B' . $currentRow, $row['kode_setoran']);
        $sheet->setCellValue('C' . $currentRow, ucfirst($row['nama_cabang']));
        $sheet->setCellValue('D' . $currentRow, $row['jumlah_setoran']);
        $sheet->setCellValue('E' . $currentRow, $jumlah_diterima);
        $sheet->setCellValue('F' . $currentRow, $selisih);
        $sheet->setCellValue('G' . $currentRow, 'Validasi Keuangan OK');
        $sheet->setCellValue('H' . $currentRow, $row['nama_karyawan']);
        
        // Format currency
        $sheet->getStyle('D' . $currentRow . ':F' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
        
        $currentRow++;
    }
    
    // Total row
    if (count($data) > 0) {
        $sheet->setCellValue('A' . $currentRow, 'TOTAL');
        $sheet->mergeCells('A' . $currentRow . ':D' . $currentRow);
        $sheet->setCellValue('E' . $currentRow, $total_setoran);
        $sheet->getStyle('E' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('A' . $currentRow . ':H' . $currentRow)->getFont()->setBold(true);
        
        setDataStyle($sheet, 'A4:H' . $currentRow);
    }
    
    $filename = 'Setoran_Siap_Setor_Bank_' . date('Y-m-d_H-i-s') . '.xlsx';

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
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sheet->setTitle('Validasi Fisik');
    
    // Title
    $sheet->setCellValue('A1', 'DATA TRANSAKSI PERLU VALIDASI FISIK');
    $sheet->mergeCells('A1:H1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $currentRow = 3;
    
    // Headers
    $headers = ['Tanggal', 'Kode Transaksi', 'Kode Setoran', 'Cabang', 'Kasir', 'Nominal Transaksi', 'Status', 'Pengantar'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $currentRow, $header);
        $col++;
    }
    setHeaderStyle($sheet, 'A' . $currentRow . ':H' . $currentRow);
    $currentRow++;
    
    // Data
    foreach ($data as $row) {
        $sheet->setCellValue('A' . $currentRow, date('d/m/Y', strtotime($row['tanggal_transaksi'])));
        $sheet->setCellValue('B' . $currentRow, $row['kode_transaksi']);
        $sheet->setCellValue('C' . $currentRow, $row['kode_setoran']);
        $sheet->setCellValue('D' . $currentRow, ucfirst($row['nama_cabang']));
        $sheet->setCellValue('E' . $currentRow, $row['nama_karyawan']);
        $sheet->setCellValue('F' . $currentRow, $row['setoran_real']);
        $sheet->setCellValue('G' . $currentRow, 'Diterima Staff Keuangan');
        $sheet->setCellValue('H' . $currentRow, $row['nama_pengantar']);
        
        // Format currency
        $sheet->getStyle('F' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
        
        $currentRow++;
    }
    
    if (count($data) > 0) {
        setDataStyle($sheet, 'A4:H' . ($currentRow - 1));
    }
    
    $filename = 'Transaksi_Perlu_Validasi_' . date('Y-m-d_H-i-s') . '.xlsx';

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
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if validation columns exist
    $validation_columns_exist = false;
    try {
        $stmt_check = $pdo->query("SHOW COLUMNS FROM kasir_transactions LIKE 'jumlah_diterima_fisik'");
        $validation_columns_exist = $stmt_check->rowCount() > 0;
    } catch (Exception $e) {
        // Column doesn't exist
    }
    
    $sheet->setTitle('Validasi Selisih');
    
    // Title
    $sheet->setCellValue('A1', 'DATA TRANSAKSI DENGAN SELISIH VALIDASI');
    $sheet->mergeCells('A1:I1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $currentRow = 3;
    
    // Headers
    $headers = ['Tanggal', 'Kode Transaksi', 'Kode Setoran', 'Cabang', 'Kasir', 'Nominal Sistem', 'Diterima Fisik', 'Selisih', 'Catatan'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $currentRow, $header);
        $col++;
    }
    setHeaderStyle($sheet, 'A' . $currentRow . ':I' . $currentRow);
    $currentRow++;
    
    // Data
    foreach ($data as $row) {
        $diterima_fisik = ($validation_columns_exist && isset($row['jumlah_diterima_fisik'])) 
            ? $row['jumlah_diterima_fisik'] 
            : $row['setoran_real'];
        $selisih = ($validation_columns_exist && isset($row['selisih_fisik'])) 
            ? $row['selisih_fisik'] 
            : 0;
        
        $sheet->setCellValue('A' . $currentRow, date('d/m/Y', strtotime($row['tanggal_transaksi'])));
        $sheet->setCellValue('B' . $currentRow, $row['kode_transaksi']);
        $sheet->setCellValue('C' . $currentRow, $row['kode_setoran']);
        $sheet->setCellValue('D' . $currentRow, ucfirst($row['nama_cabang']));
        $sheet->setCellValue('E' . $currentRow, $row['nama_karyawan']);
        $sheet->setCellValue('F' . $currentRow, $row['setoran_real']);
        $sheet->setCellValue('G' . $currentRow, $diterima_fisik);
        $sheet->setCellValue('H' . $currentRow, $selisih);
        $sheet->setCellValue('I' . $currentRow, $row['catatan_validasi'] ?? '');
        
        // Format currency
        $sheet->getStyle('F' . $currentRow . ':H' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
        
        // Color negative selisih red
        if ($selisih < 0) {
            $sheet->getStyle('H' . $currentRow)->getFont()->getColor()->setRGB('FF0000');
        } elseif ($selisih > 0) {
            $sheet->getStyle('H' . $currentRow)->getFont()->getColor()->setRGB('008000');
        }
        
        $currentRow++;
    }
    
    if (count($data) > 0) {
        setDataStyle($sheet, 'A4:I' . ($currentRow - 1));
    }
    
    $filename = 'Transaksi_Selisih_Validasi_' . date('Y-m-d_H-i-s') . '.xlsx';

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
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sheet->setTitle('Riwayat Setoran Bank');
    
    // Title
    $sheet->setCellValue('A1', 'RIWAYAT SETORAN KE BANK');
    $sheet->mergeCells('A1:H1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $currentRow = 3;
    
    // Headers
    $headers = ['Tanggal Setor', 'Kode Setoran Bank', 'Cabang Terkait', 'Rekening Tujuan', 'Total Setoran', 'Jumlah Paket', 'Disetor Oleh', 'Metode'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $currentRow, $header);
        $col++;
    }
    setHeaderStyle($sheet, 'A' . $currentRow . ':H' . $currentRow);
    $currentRow++;
    
    // Data
    $grand_total = 0;
    foreach ($data as $row) {
        $grand_total += $row['total_setoran'];
        
        $sheet->setCellValue('A' . $currentRow, date('d/m/Y', strtotime($row['tanggal_setoran'])));
        $sheet->setCellValue('B' . $currentRow, $row['kode_setoran']);
        $sheet->setCellValue('C' . $currentRow, $row['cabang_names']);
        $sheet->setCellValue('D' . $currentRow, $row['rekening_tujuan']);
        $sheet->setCellValue('E' . $currentRow, $row['total_setoran']);
        $sheet->setCellValue('F' . $currentRow, $row['total_setoran_count'] . ' paket');
        $sheet->setCellValue('G' . $currentRow, $row['created_by_name']);
        $sheet->setCellValue('H' . $currentRow, $row['metode_setoran']);
        
        // Format currency
        $sheet->getStyle('E' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
        
        // Enable text wrapping for long content
        $sheet->getStyle('C' . $currentRow . ':D' . $currentRow)->getAlignment()->setWrapText(true);
        $sheet->getStyle('G' . $currentRow)->getAlignment()->setWrapText(true);
        
        $currentRow++;
    }
    
    // Set column widths for better text display
    $sheet->getColumnDimension('A')->setWidth(12); // Tanggal Setor
    $sheet->getColumnDimension('B')->setWidth(20); // Kode Setoran Bank
    $sheet->getColumnDimension('C')->setWidth(25); // Cabang Terkait
    $sheet->getColumnDimension('D')->setWidth(30); // Rekening Tujuan
    $sheet->getColumnDimension('E')->setWidth(15); // Total Setoran
    $sheet->getColumnDimension('F')->setWidth(12); // Jumlah Paket
    $sheet->getColumnDimension('G')->setWidth(20); // Disetor Oleh
    $sheet->getColumnDimension('H')->setWidth(12); // Metode
    
    // Grand total
    if (count($data) > 0) {
        $sheet->setCellValue('A' . $currentRow, 'GRAND TOTAL');
        $sheet->mergeCells('A' . $currentRow . ':D' . $currentRow);
        $sheet->setCellValue('E' . $currentRow, $grand_total);
        $sheet->getStyle('E' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('A' . $currentRow . ':H' . $currentRow)->getFont()->setBold(true);
        
        setDataStyle($sheet, 'A4:H' . $currentRow);
    }
    
    $filename = 'Riwayat_Setoran_Bank_' . date('Y-m-d_H-i-s') . '.xlsx';

} elseif ($type == 'bank_detail') {
    // Bank detail export
    $bank_id = $_GET['bank_id'] ?? '';
    
    if (!$bank_id) {
        die('Bank ID required');
    }
    
    $sql_bank_detail = "SELECT sb.*, u.nama_karyawan as created_by_name 
                       FROM setoran_ke_bank sb 
                       LEFT JOIN users u ON sb.created_by = u.kode_karyawan 
                       WHERE sb.id = ?";
    $stmt_bank_detail = $pdo->prepare($sql_bank_detail);
    $stmt_bank_detail->execute([$bank_id]);
    $bank_detail = $stmt_bank_detail->fetch(PDO::FETCH_ASSOC);
    
    if (!$bank_detail) {
        die('Bank detail not found');
    }
    
    // Get closing details
    $sql_closing = "SELECT 
                       sk.kode_cabang,
                       c.nama_cabang,
                       COUNT(sk.id) as total_setoran,
                       SUM(sk.jumlah_diterima) as total_nominal,
                       GROUP_CONCAT(sk.kode_setoran ORDER BY sk.tanggal_setoran) as kode_setoran_list,
                       MIN(sk.tanggal_setoran) as tanggal_awal,
                       MAX(sk.tanggal_setoran) as tanggal_akhir
                   FROM setoran_ke_bank_detail sbd
                   JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
                   JOIN cabang c ON sk.kode_cabang = c.kode_cabang
                   WHERE sbd.setoran_ke_bank_id = ?
                   GROUP BY sk.kode_cabang, c.nama_cabang
                   ORDER BY c.nama_cabang";
    $stmt_closing = $pdo->prepare($sql_closing);
    $stmt_closing->execute([$bank_id]);
    $closing_data = $stmt_closing->fetchAll(PDO::FETCH_ASSOC);
    
    $sheet->setTitle('Detail Setoran Bank');
    
    // Title
    $sheet->setCellValue('A1', 'DETAIL SETORAN KE BANK');
    $sheet->mergeCells('A1:G1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Bank info
    $currentRow = 3;
    $sheet->setCellValue('A' . $currentRow, 'Kode Setoran Bank:');
    $sheet->setCellValue('B' . $currentRow, $bank_detail['kode_setoran']);
    $currentRow++;
    
    $sheet->setCellValue('A' . $currentRow, 'Tanggal Setoran:');
    $sheet->setCellValue('B' . $currentRow, date('d/m/Y', strtotime($bank_detail['tanggal_setoran'])));
    $currentRow++;
    
    $sheet->setCellValue('A' . $currentRow, 'Rekening Tujuan:');
    $sheet->setCellValue('B' . $currentRow, $bank_detail['rekening_tujuan']);
    $currentRow++;
    
    $sheet->setCellValue('A' . $currentRow, 'Total Setoran:');
    $sheet->setCellValue('B' . $currentRow, $bank_detail['total_setoran']);
    $sheet->getStyle('B' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
    $currentRow++;
    
    $sheet->setCellValue('A' . $currentRow, 'Disetor Oleh:');
    $sheet->setCellValue('B' . $currentRow, $bank_detail['created_by_name']);
    $currentRow += 2;
    
    // Headers for closing detail
    $headers = ['Cabang', 'Periode Awal', 'Periode Akhir', 'Jumlah Setoran', 'Nominal Closing', 'Kode Setoran', 'Status'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $currentRow, $header);
        $col++;
    }
    setHeaderStyle($sheet, 'A' . $currentRow . ':G' . $currentRow);
    $currentRow++;
    
    // Data
    foreach ($closing_data as $row) {
        $sheet->setCellValue('A' . $currentRow, strtoupper($row['nama_cabang']));
        $sheet->setCellValue('B' . $currentRow, date('d/m/Y', strtotime($row['tanggal_awal'])));
        $sheet->setCellValue('C' . $currentRow, date('d/m/Y', strtotime($row['tanggal_akhir'])));
        $sheet->setCellValue('D' . $currentRow, $row['total_setoran'] . ' setoran');
        $sheet->setCellValue('E' . $currentRow, $row['total_nominal']);
        $sheet->setCellValue('F' . $currentRow, substr($row['kode_setoran_list'], 0, 100) . '...');
        $sheet->setCellValue('G' . $currentRow, 'Disetor ke Bank');
        
        // Format currency
        $sheet->getStyle('E' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
        
        $currentRow++;
    }
    
    if (count($closing_data) > 0) {
        setDataStyle($sheet, 'A' . ($currentRow - count($closing_data)) . ':G' . ($currentRow - 1));
    }
    
    $filename = 'Detail_Setoran_Bank_' . $bank_detail['kode_setoran'] . '_' . date('Y-m-d_H-i-s') . '.xlsx';

} elseif ($type == 'cabang_closing') {
    // Cabang closing detail export
    $bank_id = $_GET['bank_id'] ?? '';
    $cabang_name = $_GET['cabang'] ?? '';
    
    if (!$bank_id || !$cabang_name) {
        die('Bank ID and Cabang name required');
    }
    
    $sql_cabang_detail = "SELECT 
                             sk.*,
                             kt.kode_transaksi,
                             kt.tanggal_transaksi,
                             kt.setoran_real,
                             kt.deposit_status
                         FROM setoran_ke_bank_detail sbd
                         JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
                         JOIN cabang c ON sk.kode_cabang = c.kode_cabang
                         LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
                         WHERE sbd.setoran_ke_bank_id = ? AND c.nama_cabang = ?
                         ORDER BY sk.tanggal_setoran, kt.tanggal_transaksi";
    $stmt_cabang_detail = $pdo->prepare($sql_cabang_detail);
    $stmt_cabang_detail->execute([$bank_id, $cabang_name]);
    $cabang_data = $stmt_cabang_detail->fetchAll(PDO::FETCH_ASSOC);
    
    $sheet->setTitle('Closing ' . $cabang_name);
    
    // Title
    $sheet->setCellValue('A1', 'DETAIL SETORAN CLOSING - ' . strtoupper($cabang_name));
    $sheet->mergeCells('A1:F1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $currentRow = 3;
    
    // Summary
    $total_setoran = count(array_unique(array_column($cabang_data, 'kode_setoran')));
    $total_transaksi = count($cabang_data);
    $total_nominal = array_sum(array_column($cabang_data, 'setoran_real'));
    
    $sheet->setCellValue('A' . $currentRow, 'Total Setoran:');
    $sheet->setCellValue('B' . $currentRow, $total_setoran . ' setoran');
    $currentRow++;
    
    $sheet->setCellValue('A' . $currentRow, 'Total Transaksi:');
    $sheet->setCellValue('B' . $currentRow, $total_transaksi . ' transaksi');
    $currentRow++;
    
    $sheet->setCellValue('A' . $currentRow, 'Total Nominal:');
    $sheet->setCellValue('B' . $currentRow, $total_nominal);
    $sheet->getStyle('B' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
    $currentRow += 2;
    
    // Headers
    $headers = ['Tanggal', 'Kode Setoran', 'Kode Transaksi', 'Nominal Closing', 'Status Setor', 'Nominal Setor'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $currentRow, $header);
        $col++;
    }
    setHeaderStyle($sheet, 'A' . $currentRow . ':F' . $currentRow);
    $currentRow++;
    
    // Data tanpa subtotal - langsung detail transaksi
    $total_keseluruhan = 0;
    
    foreach ($cabang_data as $detail) {
        $total_keseluruhan += $detail['setoran_real'];
        
        $sheet->setCellValue('A' . $currentRow, date('d/m/Y', strtotime($detail['tanggal_transaksi'])));
        $sheet->setCellValue('B' . $currentRow, $detail['kode_setoran']);
        $sheet->setCellValue('C' . $currentRow, $detail['kode_transaksi']);
        $sheet->setCellValue('D' . $currentRow, $detail['setoran_real']);
        $sheet->setCellValue('E' . $currentRow, 'Disetor');
        $sheet->setCellValue('F' . $currentRow, $detail['setoran_real']);
        
        // Format currency
        $sheet->getStyle('D' . $currentRow . ':F' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
        
        $currentRow++;
    }
    
    // Grand total
    $sheet->setCellValue('A' . $currentRow, 'TOTAL KESELURUHAN');
    $sheet->mergeCells('A' . $currentRow . ':E' . $currentRow);
    $sheet->setCellValue('F' . $currentRow, $total_keseluruhan);
    $sheet->getStyle('A' . $currentRow . ':F' . $currentRow)->getFont()->setBold(true);
    $sheet->getStyle('A' . $currentRow . ':F' . $currentRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('28a745');
    $sheet->getStyle('A' . $currentRow . ':F' . $currentRow)->getFont()->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('F' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
    
    if (count($cabang_data) > 0) {
        setDataStyle($sheet, 'A' . ($currentRow - count($cabang_data) - 2) . ':F' . ($currentRow - 1));
    }
    
    $filename = 'Closing_Detail_' . str_replace(' ', '_', $cabang_name) . '_' . date('Y-m-d_H-i-s') . '.xlsx';

} elseif ($type == 'monitoring') {
    // Monitoring export - Updated to match setoran_keuangan.php monitoring tab
    $sql = "
        SELECT 
            kt.id,
            kt.kode_transaksi,
            kt.tanggal_transaksi,
            kt.tanggal_closing,
            kt.jam_closing,
            kt.setoran_real,
            kt.deposit_status,
            kt.kode_setoran,
            kt.nama_cabang,
            kt.validasi_at,
            kt.catatan_validasi,
            kt.jumlah_diterima_fisik,
            COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan,
            sk.tanggal_setoran,
            sk.status as setoran_status,
            -- Bank deposit information
            sb.id as setor_bank_id,
            sb.tanggal_setoran as tanggal_setor_bank,
            sb.total_setoran as total_setor_bank,
            sb.rekening_tujuan as bank_account,
            sb.metode_setoran,
            sb.bukti_transfer,
            sb.created_at as bank_created_at,
            sb.created_by as bank_created_by,
            -- Check if it's a closing transaction
            CASE 
                WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' THEN 'CLOSING'
                WHEN EXISTS (
                    SELECT 1 FROM pemasukan_kasir pk 
                    WHERE pk.nomor_transaksi_closing = kt.kode_transaksi
                ) THEN 'DARI_CLOSING'
                ELSE 'REGULER'
            END as jenis_transaksi,
            -- Get selisih if available
            CASE 
                WHEN kt.jumlah_diterima_fisik IS NOT NULL 
                THEN (kt.setoran_real - kt.jumlah_diterima_fisik) 
                ELSE 0 
            END as selisih_fisik
        FROM kasir_transactions kt
        LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
        LEFT JOIN users u ON kt.kode_karyawan = u.kode_karyawan
        LEFT JOIN setoran_ke_bank_detail sbd ON sk.id = sbd.setoran_keuangan_id
        LEFT JOIN setoran_ke_bank sb ON sbd.setoran_ke_bank_id = sb.id
        WHERE (kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' 
               OR EXISTS (
                   SELECT 1 FROM pemasukan_kasir pk 
                   WHERE pk.nomor_transaksi_closing = kt.kode_transaksi
               ))
        AND (kt.status = 'end proses' 
             OR kt.deposit_status IN ('Validasi Keuangan OK', 'Validasi Keuangan SELISIH', 'Sedang Dibawa Kurir', 'Diterima Staff Keuangan', 'Dikembalikan ke CS', 'Sudah Disetor ke Bank'))";

    $params = [];
    
    if ($tanggal_awal && $tanggal_akhir) {
        $sql .= " AND kt.tanggal_transaksi BETWEEN ? AND ?";
        $params[] = $tanggal_awal;
        $params[] = $tanggal_akhir;
    }
    
    if ($cabang !== 'all') {
        $sql .= " AND kt.nama_cabang = ?";
        $params[] = $cabang;
    }
    
    $sql .= " ORDER BY 
        CASE kt.deposit_status 
            WHEN 'Sedang Dibawa Kurir' THEN 1
            WHEN 'Diterima Staff Keuangan' THEN 2
            WHEN 'Validasi Keuangan SELISIH' THEN 3
            WHEN 'Validasi Keuangan OK' THEN 4
            WHEN 'Dikembalikan ke CS' THEN 5
            WHEN 'Sudah Disetor ke Bank' THEN 6
            ELSE 7
        END,
        kt.tanggal_transaksi DESC, kt.jam_closing DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sheet->setTitle('Monitoring Closing');
    
    // Title
    $sheet->setCellValue('A1', 'MONITORING TRANSAKSI CLOSING');
    $sheet->mergeCells('A1:M1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $currentRow = 3;
    
    // Headers
    $headers = ['Tanggal', 'Kode Transaksi', 'Cabang', 'Kasir', 'Jenis', 'Setoran Real', 'Diterima Fisik', 'Selisih', 'Status Deposit', 'Bank Account', 'Metode Setor', 'Tanggal Setor Bank', 'Catatan'];
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $currentRow, $header);
        $col++;
    }
    setHeaderStyle($sheet, 'A' . $currentRow . ':M' . $currentRow);
    $currentRow++;
    
    // Data
    foreach ($data as $row) {
        $diterima_fisik = $row['jumlah_diterima_fisik'] ?? $row['setoran_real'];
        $selisih = $row['selisih_fisik'] ?? 0;
        
        $sheet->setCellValue('A' . $currentRow, date('d/m/Y', strtotime($row['tanggal_transaksi'])));
        $sheet->setCellValue('B' . $currentRow, $row['kode_transaksi']);
        $sheet->setCellValue('C' . $currentRow, $row['nama_cabang']);
        $sheet->setCellValue('D' . $currentRow, $row['nama_karyawan']);
        $sheet->setCellValue('E' . $currentRow, $row['jenis_transaksi']);
        $sheet->setCellValue('F' . $currentRow, $row['setoran_real']);
        $sheet->setCellValue('G' . $currentRow, $diterima_fisik);
        $sheet->setCellValue('H' . $currentRow, $selisih);
        $sheet->setCellValue('I' . $currentRow, $row['deposit_status']);
        $sheet->setCellValue('J' . $currentRow, $row['bank_account'] ?? '-');
        $sheet->setCellValue('K' . $currentRow, $row['metode_setoran'] ?? '-');
        $sheet->setCellValue('L' . $currentRow, $row['tanggal_setor_bank'] ? date('d/m/Y', strtotime($row['tanggal_setor_bank'])) : '-');
        $sheet->setCellValue('M' . $currentRow, $row['catatan_validasi'] ?? '');
        
        // Format currency
        $sheet->getStyle('F' . $currentRow . ':H' . $currentRow)->getNumberFormat()->setFormatCode('#,##0');
        
        // Color status
        switch ($row['deposit_status']) {
            case 'Validasi Keuangan SELISIH':
                $sheet->getStyle('I' . $currentRow)->getFont()->getColor()->setRGB('FF6B35');
                break;
            case 'Validasi Keuangan OK':
                $sheet->getStyle('I' . $currentRow)->getFont()->getColor()->setRGB('28a745');
                break;
            case 'Sudah Disetor ke Bank':
                $sheet->getStyle('I' . $currentRow)->getFont()->getColor()->setRGB('007bff');
                break;
        }
        
        // Color selisih
        if ($selisih < 0) {
            $sheet->getStyle('H' . $currentRow)->getFont()->getColor()->setRGB('FF0000');
        } elseif ($selisih > 0) {
            $sheet->getStyle('H' . $currentRow)->getFont()->getColor()->setRGB('FF8C00');
        }
        
        $currentRow++;
    }
    
    if (count($data) > 0) {
        setDataStyle($sheet, 'A4:M' . ($currentRow - 1));
    }
    
    $filename = 'Monitoring_Closing_Transactions_' . date('Y-m-d_H-i-s') . '.xlsx';

} else {
    die('Invalid export type');
}

// Auto-size columns
foreach (range('A', $sheet->getHighestColumn()) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Add footer with timestamp
$lastRow = $sheet->getHighestRow() + 2;
$sheet->setCellValue('A' . $lastRow, 'Generated on: ' . date('d/m/Y H:i:s'));
$sheet->setCellValue('A' . ($lastRow + 1), 'By: ' . ($_SESSION['nama_karyawan'] ?? 'System'));
$sheet->getStyle('A' . $lastRow . ':A' . ($lastRow + 1))->getFont()->setItalic(true)->setSize(10);

// Set page setup for printing
$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
    ->setFitToPage(true)
    ->setFitToWidth(1)
    ->setFitToHeight(0);

// Set margins
$sheet->getPageMargins()
    ->setTop(0.75)
    ->setRight(0.25)
    ->setLeft(0.25)
    ->setBottom(0.75);

// Create writer and output
$writer = new Xlsx($spreadsheet);

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Cache-Control: max-age=1');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: cache, must-revalidate');
header('Pragma: public');

$writer->save('php://output');
exit;
?>