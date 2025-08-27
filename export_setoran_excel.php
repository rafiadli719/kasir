<?php
// export_excel.php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['kode_karyawan']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

// Include PhpSpreadsheet
require_once 'vendor/autoload.php'; // Pastikan PhpSpreadsheet sudah terinstall

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get parameters
$report_type = $_GET['report_type'] ?? 'cs_to_keuangan';
$tanggal_awal = $_GET['tanggal_awal'] ?? '';
$tanggal_akhir = $_GET['tanggal_akhir'] ?? '';
$cabang = $_GET['cabang'] ?? 'all';

function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

function addImageToExcel($worksheet, $imagePath, $column, $row, $width = 80, $height = 60) {
    if (file_exists($imagePath)) {
        try {
            $drawing = new Drawing();
            $drawing->setName('Image');
            $drawing->setDescription('Transaction Image');
            $drawing->setPath($imagePath);
            $drawing->setHeight($height);
            $drawing->setWidth($width);
            $drawing->setCoordinates($column . $row);
            $drawing->setWorksheet($worksheet);
            
            // Adjust row height to accommodate image
            $worksheet->getRowDimension($row)->setRowHeight($height * 0.75);
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    return false;
}

function setHeaderStyle($sheet, $range) {
    $sheet->getStyle($range)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF'));
    $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2563EB');
    $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
}

function setDataStyle($sheet, $range, $bgColor = 'FFFFFF') {
    $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bgColor);
    $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
}

// Create new Spreadsheet object
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set document properties
$spreadsheet->getProperties()
    ->setCreator("FIT MOTOR - Financial System")
    ->setLastModifiedBy("Super Admin")
    ->setTitle("Laporan Setoran")
    ->setSubject("Financial Report")
    ->setDescription("Laporan setoran keuangan FIT MOTOR")
    ->setKeywords("finance report setoran")
    ->setCategory("Financial Report");

if ($report_type == 'cs_to_keuangan') {
    // Query data sesuai dengan halaman laporan
    $sql = "
        SELECT sk.*, u.nama_karyawan, 
               GROUP_CONCAT(kt.deposit_status) as deposit_status_list,
               GROUP_CONCAT(kt.deposit_difference_status) as deposit_difference_status_list
        FROM setoran_keuangan sk
        LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
        LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
        WHERE 1=1";
    $params = [];
    
    if ($tanggal_awal && $tanggal_akhir) {
        $sql .= " AND sk.tanggal_setoran BETWEEN ? AND ?";
        $params[] = $tanggal_awal;
        $params[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql .= " AND sk.nama_cabang = ?";
        $params[] = $cabang;
    }
    $sql .= " GROUP BY sk.id ORDER BY sk.tanggal_setoran DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set title
    $sheet->setTitle('CS ke Keuangan');
    
    // Header information dengan styling yang lebih baik
    $sheet->setCellValue('A1', 'LAPORAN SETORAN CS KE KEUANGAN');
    $sheet->mergeCells('A1:L1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('2563EB'));
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A2', 'FIT MOTOR - Financial Management System');
    $sheet->mergeCells('A2:L2');
    $sheet->getStyle('A2')->getFont()->setSize(14)->setBold(true);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $periode = 'Periode: ';
    if ($tanggal_awal && $tanggal_akhir) {
        $periode .= date('d/m/Y', strtotime($tanggal_awal)) . ' - ' . date('d/m/Y', strtotime($tanggal_akhir));
    } else {
        $periode .= 'Semua Data';
    }
    $periode .= ' | Cabang: ' . ($cabang === 'all' ? 'Semua Cabang' : ucfirst($cabang));
    
    $sheet->setCellValue('A3', $periode);
    $sheet->mergeCells('A3:L3');
    $sheet->getStyle('A3')->getFont()->setSize(12);
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A4', 'Dicetak pada: ' . date('d/m/Y H:i:s') . ' oleh: ' . $_SESSION['nama_karyawan']);
    $sheet->mergeCells('A4:L4');
    $sheet->getStyle('A4')->getFont()->setSize(10);
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Table headers
    $headers = [
        'A6' => 'No',
        'B6' => 'Tanggal Setoran',
        'C6' => 'Kode Setoran',
        'D6' => 'Nama Kasir',
        'E6' => 'Cabang',
        'F6' => 'Jumlah Setoran',
        'G6' => 'Jumlah Diterima',
        'H6' => 'Selisih',
        'I6' => 'Status',
        'J6' => 'Pengantar',
        'K6' => 'Catatan Validasi',
        'L6' => 'Bukti Gambar'
    ];
    
    foreach ($headers as $cell => $header) {
        $sheet->setCellValue($cell, $header);
    }
    setHeaderStyle($sheet, 'A6:L6');
    
    // Data rows
    $row = 7;
    $no = 1;
    
    foreach ($data as $item) {
        // Get related transactions with images
        $sql_trans = "SELECT * FROM kasir_transactions WHERE kode_setoran = ?";
        $stmt_trans = $pdo->prepare($sql_trans);
        $stmt_trans->execute([$item['kode_setoran']]);
        $transactions = $stmt_trans->fetchAll(PDO::FETCH_ASSOC);
        
        $sheet->setCellValue('A' . $row, $no);
        $sheet->setCellValue('B' . $row, date('d/m/Y', strtotime($item['tanggal_setoran'])));
        $sheet->setCellValue('C' . $row, $item['kode_setoran']);
        $sheet->setCellValue('D' . $row, $item['nama_karyawan'] ?? 'Unknown');
        $sheet->setCellValue('E' . $row, ucfirst($item['nama_cabang']));
        $sheet->setCellValue('F' . $row, $item['jumlah_setoran']);
        $sheet->setCellValue('G' . $row, $item['jumlah_diterima'] ?? $item['jumlah_setoran']);
        
        $selisih = $item['selisih_setoran'] ?? 0;
        $sheet->setCellValue('H' . $row, $selisih);
        
        $sheet->setCellValue('I' . $row, $item['status']);
        $sheet->setCellValue('J' . $row, $item['nama_pengantar'] ?? '-');
        $sheet->setCellValue('K' . $row, $item['catatan_validasi'] ?? '-');
        
        // Format currency columns
        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('G' . $row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('H' . $row)->getNumberFormat()->setFormatCode('#,##0');
        
        // Add images from transactions
        $imageAdded = false;
        $imageColumn = 'L';
        
        foreach ($transactions as $trans) {
            if (!empty($trans['bukti_gambar_setoran']) && file_exists($trans['bukti_gambar_setoran'])) {
                if (addImageToExcel($sheet, $trans['bukti_gambar_setoran'], $imageColumn, $row, 80, 60)) {
                    $imageAdded = true;
                    break; // Only add first image to avoid overlap
                }
            }
        }
        
        if (!$imageAdded) {
            $sheet->setCellValue('L' . $row, 'Tidak ada gambar');
        }
        
        // Style the row with color coding based on status
        $bgColor = 'FFFFFF';
        switch($item['status']) {
            case 'Belum Diterima': $bgColor = 'FFF3E0'; break;
            case 'Sudah Diterima': $bgColor = 'E8F5E8'; break;
            case 'Sudah Divalidasi': $bgColor = 'E3F2FD'; break;
            case 'Selisih': $bgColor = 'FFEBEE'; break;
            case 'Sudah Disetor ke Bank': $bgColor = 'E8F5E8'; break;
        }
        setDataStyle($sheet, 'A' . $row . ':L' . $row, $bgColor);
        
        $row++;
        $no++;
    }
    
    // Summary section
    $summaryRow = $row + 2;
    $sheet->setCellValue('A' . $summaryRow, 'RINGKASAN LAPORAN');
    $sheet->mergeCells('A' . $summaryRow . ':L' . $summaryRow);
    $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A' . $summaryRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Get summary data
    $sql_summary = "
        SELECT 
            status, 
            COUNT(*) as count,
            SUM(jumlah_setoran) as total_setoran,
            SUM(COALESCE(jumlah_diterima, jumlah_setoran)) as total_diterima,
            SUM(COALESCE(selisih_setoran, 0)) as total_selisih
        FROM setoran_keuangan sk
        WHERE 1=1";
    $params_summary = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_summary .= " AND sk.tanggal_setoran BETWEEN ? AND ?";
        $params_summary[] = $tanggal_awal;
        $params_summary[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_summary .= " AND sk.nama_cabang = ?";
        $params_summary[] = $cabang;
    }
    $sql_summary .= " GROUP BY status";
    
    $stmt_summary = $pdo->prepare($sql_summary);
    $stmt_summary->execute($params_summary);
    $summary = $stmt_summary->fetchAll(PDO::FETCH_ASSOC);
    
    $summaryRow += 2;
    $summaryHeaders = [
        'A' => 'Status',
        'B' => 'Jumlah Transaksi', 
        'C' => 'Total Setoran', 
        'D' => 'Total Diterima', 
        'E' => 'Total Selisih'
    ];
    foreach ($summaryHeaders as $column => $header) {
        $sheet->setCellValue($column . $summaryRow, $header);
    }
    setHeaderStyle($sheet, 'A' . $summaryRow . ':E' . $summaryRow);
    
    $summaryRow++;
    foreach ($summary as $item) {
        $sheet->setCellValue('A' . $summaryRow, $item['status']);
        $sheet->setCellValue('B' . $summaryRow, $item['count'] . ' transaksi');
        $sheet->setCellValue('C' . $summaryRow, $item['total_setoran']);
        $sheet->setCellValue('D' . $summaryRow, $item['total_diterima']);
        $sheet->setCellValue('E' . $summaryRow, $item['total_selisih']);
        
        // Format currency
        $sheet->getStyle('C' . $summaryRow)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('D' . $summaryRow)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('E' . $summaryRow)->getNumberFormat()->setFormatCode('#,##0');
        
        setDataStyle($sheet, 'A' . $summaryRow . ':E' . $summaryRow);
        $summaryRow++;
    }
    
    $filename = 'Laporan_CS_ke_Keuangan_' . date('Y-m-d_H-i-s') . '.xlsx';
    
} elseif ($report_type == 'keuangan_to_bank') {
    // Query data for bank deposits sesuai dengan halaman laporan
    $sql = "
        SELECT sb.*, u.nama_karyawan as created_by_name, 
               GROUP_CONCAT(DISTINCT sk.nama_cabang SEPARATOR ', ') as cabang_list
        FROM setoran_ke_bank sb
        JOIN users u ON sb.created_by = u.kode_karyawan
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        WHERE 1=1";
    $params = [];
    
    if ($tanggal_awal && $tanggal_akhir) {
        $sql .= " AND sb.tanggal_setoran BETWEEN ? AND ?";
        $params[] = $tanggal_awal;
        $params[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql .= " AND sk.nama_cabang = ?";
        $params[] = $cabang;
    }
    $sql .= " GROUP BY sb.id ORDER BY sb.tanggal_setoran DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set title
    $sheet->setTitle('Keuangan ke Bank');
    
    // Header information
    $sheet->setCellValue('A1', 'LAPORAN SETORAN KEUANGAN KE BANK');
    $sheet->mergeCells('A1:J1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('2563EB'));
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A2', 'FIT MOTOR - Financial Management System');
    $sheet->mergeCells('A2:J2');
    $sheet->getStyle('A2')->getFont()->setSize(14)->setBold(true);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $periode = 'Periode: ';
    if ($tanggal_awal && $tanggal_akhir) {
        $periode .= date('d/m/Y', strtotime($tanggal_awal)) . ' - ' . date('d/m/Y', strtotime($tanggal_akhir));
    } else {
        $periode .= 'Semua Data';
    }
    $periode .= ' | Cabang: ' . ($cabang === 'all' ? 'Semua Cabang' : ucfirst($cabang));
    
    $sheet->setCellValue('A3', $periode);
    $sheet->mergeCells('A3:J3');
    $sheet->getStyle('A3')->getFont()->setSize(12);
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A4', 'Dicetak pada: ' . date('d/m/Y H:i:s') . ' oleh: ' . $_SESSION['nama_karyawan']);
    $sheet->mergeCells('A4:J4');
    $sheet->getStyle('A4')->getFont()->setSize(10);
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Table headers
    $headers = [
        'A6' => 'No',
        'B6' => 'Kode Setoran',
        'C6' => 'Tanggal Setoran',
        'D6' => 'Metode Setoran',
        'E6' => 'Rekening Tujuan',
        'F6' => 'Total Setoran',
        'G6' => 'Cabang Asal',
        'H6' => 'Dibuat Oleh',
        'I6' => 'Tanggal Dibuat',
        'J6' => 'Bukti Transfer'
    ];
    
    foreach ($headers as $cell => $header) {
        $sheet->setCellValue($cell, $header);
    }
    setHeaderStyle($sheet, 'A6:J6');
    
    // Data rows
    $row = 7;
    $no = 1;
    
    foreach ($data as $item) {
        $sheet->setCellValue('A' . $row, $no);
        $sheet->setCellValue('B' . $row, $item['kode_setoran']);
        $sheet->setCellValue('C' . $row, date('d/m/Y', strtotime($item['tanggal_setoran'])));
        $sheet->setCellValue('D' . $row, $item['metode_setoran']);
        $sheet->setCellValue('E' . $row, $item['rekening_tujuan']);
        $sheet->setCellValue('F' . $row, $item['total_setoran']);
        $sheet->setCellValue('G' . $row, $item['cabang_list']);
        $sheet->setCellValue('H' . $row, $item['created_by_name']);
        $sheet->setCellValue('I' . $row, date('d/m/Y H:i', strtotime($item['created_at'])));
        
        // Format currency
        $sheet->getStyle('F' . $row)->getNumberFormat()->setFormatCode('#,##0');
        
        // Add transfer proof image
        if (!empty($item['bukti_transfer']) && file_exists($item['bukti_transfer'])) {
            if (addImageToExcel($sheet, $item['bukti_transfer'], 'J', $row, 100, 75)) {
                $sheet->setCellValue('J' . $row, 'Lihat Gambar');
            } else {
                $sheet->setCellValue('J' . $row, 'Error loading image');
            }
        } else {
            $sheet->setCellValue('J' . $row, 'Tidak ada bukti');
        }
        
        setDataStyle($sheet, 'A' . $row . ':J' . $row);
        
        $row++;
        $no++;
    }
    
    // Summary
    $summaryRow = $row + 2;
    $sheet->setCellValue('A' . $summaryRow, 'RINGKASAN LAPORAN');
    $sheet->mergeCells('A' . $summaryRow . ':J' . $summaryRow);
    $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A' . $summaryRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $sql_summary = "
        SELECT 
            SUM(total_setoran) as total,
            COUNT(*) as count
        FROM setoran_ke_bank sb
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        WHERE 1=1";
    $params_summary = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_summary .= " AND sb.tanggal_setoran BETWEEN ? AND ?";
        $params_summary[] = $tanggal_awal;
        $params_summary[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_summary .= " AND sk.nama_cabang = ?";
        $params_summary[] = $cabang;
    }
    
    $stmt_summary = $pdo->prepare($sql_summary);
    $stmt_summary->execute($params_summary);
    $summary = $stmt_summary->fetch(PDO::FETCH_ASSOC);
    
    $summaryRow += 2;
    $sheet->setCellValue('A' . $summaryRow, 'Total Setoran ke Bank');
    $sheet->setCellValue('B' . $summaryRow, $summary['total']);
    $sheet->setCellValue('C' . $summaryRow, $summary['count'] . ' transaksi');
    
    $sheet->getStyle('B' . $summaryRow)->getNumberFormat()->setFormatCode('#,##0');
    setHeaderStyle($sheet, 'A' . $summaryRow . ':C' . $summaryRow);
    
    $filename = 'Laporan_Keuangan_ke_Bank_' . date('Y-m-d_H-i-s') . '.xlsx';
    
} elseif ($report_type == 'rekapitulasi') {
    // Query rekapitulasi data sesuai dengan halaman laporan
    $sql_total_cs = "
        SELECT SUM(COALESCE(jumlah_diterima, jumlah_setoran)) as total_cs
        FROM setoran_keuangan sk
        WHERE 1=1";
    $params_cs = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_total_cs .= " AND sk.tanggal_setoran BETWEEN ? AND ?";
        $params_cs[] = $tanggal_awal;
        $params_cs[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_total_cs .= " AND sk.nama_cabang = ?";
        $params_cs[] = $cabang;
    }
    
    $stmt_total_cs = $pdo->prepare($sql_total_cs);
    $stmt_total_cs->execute($params_cs);
    $total_cs = $stmt_total_cs->fetchColumn() ?? 0;
    
    $sql_total_selisih = "
        SELECT SUM(COALESCE(selisih_setoran, 0)) as total_selisih
        FROM setoran_keuangan sk
        WHERE 1=1";
    $params_selisih = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_total_selisih .= " AND sk.tanggal_setoran BETWEEN ? AND ?";
        $params_selisih[] = $tanggal_awal;
        $params_selisih[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_total_selisih .= " AND sk.nama_cabang = ?";
        $params_selisih[] = $cabang;
    }
    
    $stmt_total_selisih = $pdo->prepare($sql_total_selisih);
    $stmt_total_selisih->execute($params_selisih);
    $total_selisih = $stmt_total_selisih->fetchColumn() ?? 0;
    
    $sql_total_bank = "
        SELECT SUM(total_setoran) as total_bank
        FROM setoran_ke_bank sb
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        WHERE 1=1";
    $params_bank = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_total_bank .= " AND sb.tanggal_setoran BETWEEN ? AND ?";
        $params_bank[] = $tanggal_awal;
        $params_bank[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_total_bank .= " AND sk.nama_cabang = ?";
        $params_bank[] = $cabang;
    }
    
    $stmt_total_bank = $pdo->prepare($sql_total_bank);
    $stmt_total_bank->execute($params_bank);
    $total_bank = $stmt_total_bank->fetchColumn() ?? 0;
    
    $selisih = $total_cs - $total_bank;
    
    // Set title
    $sheet->setTitle('Rekapitulasi');
    
    // Header information
    $sheet->setCellValue('A1', 'REKAPITULASI SETORAN');
    $sheet->mergeCells('A1:E1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('2563EB'));
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A2', 'FIT MOTOR - Financial Management System');
    $sheet->mergeCells('A2:E2');
    $sheet->getStyle('A2')->getFont()->setSize(14)->setBold(true);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $periode = 'Periode: ';
    if ($tanggal_awal && $tanggal_akhir) {
        $periode .= date('d/m/Y', strtotime($tanggal_awal)) . ' - ' . date('d/m/Y', strtotime($tanggal_akhir));
    } else {
        $periode .= 'Semua Data';
    }
    $periode .= ' | Cabang: ' . ($cabang === 'all' ? 'Semua Cabang' : ucfirst($cabang));
    
    $sheet->setCellValue('A3', $periode);
    $sheet->mergeCells('A3:E3');
    $sheet->getStyle('A3')->getFont()->setSize(12);
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $sheet->setCellValue('A4', 'Dicetak pada: ' . date('d/m/Y H:i:s') . ' oleh: ' . $_SESSION['nama_karyawan']);
    $sheet->mergeCells('A4:E4');
    $sheet->getStyle('A4')->getFont()->setSize(10);
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Rekapitulasi data
    $rekapData = [
        ['Keterangan', 'Jumlah', 'Persentase', 'Status', 'Keterangan Detail'],
        ['Total Setoran Diterima dari CS', $total_cs, '100%', 'Diterima', 'Jumlah uang yang telah diterima dan divalidasi dari seluruh cabang'],
        ['Total Selisih Setoran CS', $total_selisih, $total_cs > 0 ? number_format(($total_selisih/$total_cs)*100, 2) . '%' : '0%', $total_selisih > 0 ? 'Kelebihan' : ($total_selisih < 0 ? 'Kekurangan' : 'Sesuai'), 'Selisih antara sistem dan fisik saat validasi'],
        ['Total Setoran ke Bank', $total_bank, $total_cs > 0 ? number_format(($total_bank/$total_cs)*100, 2) . '%' : '0%', 'Disetor', 'Jumlah uang yang telah disetor ke bank'],
        ['Saldo Belum Disetor', $selisih, $total_cs > 0 ? number_format(($selisih/$total_cs)*100, 2) . '%' : '0%', $selisih > 0 ? 'Pending' : 'Lunas', 'Sisa uang yang belum disetor ke bank']
    ];
    
    $row = 6;
    foreach ($rekapData as $index => $rowData) {
        $sheet->setCellValue('A' . $row, $rowData[0]);
        $sheet->setCellValue('B' . $row, is_numeric($rowData[1]) ? $rowData[1] : $rowData[1]);
        $sheet->setCellValue('C' . $row, $rowData[2]);
        $sheet->setCellValue('D' . $row, $rowData[3]);
        $sheet->setCellValue('E' . $row, $rowData[4]);
        
        if ($index == 0) {
            // Header
            setHeaderStyle($sheet, 'A' . $row . ':E' . $row);
        } else {
            // Format currency for amount column
            if (is_numeric($rowData[1])) {
                $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0');
            }
            setDataStyle($sheet, 'A' . $row . ':E' . $row);
        }
        $row++;
    }
    
    $filename = 'Rekapitulasi_Setoran_' . date('Y-m-d_H-i-s') . '.xlsx';
}

// Auto-size columns
foreach (range('A', 'L') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

// Set minimum width for image column - PERBAIKAN ERROR ISSET()
// Untuk kolom L (laporan CS ke Keuangan)
if ($report_type == 'cs_to_keuangan') {
    $sheet->getColumnDimension('L')->setWidth(25);
}

// Untuk kolom J (laporan Keuangan ke Bank)
if ($report_type == 'keuangan_to_bank') {
    $sheet->getColumnDimension('J')->setWidth(25);
}

// Create Excel writer
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

// Save to output
$writer->save('php://output');
exit;
?>