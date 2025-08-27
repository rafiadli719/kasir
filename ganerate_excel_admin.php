<?php
require 'vendor/autoload.php'; // Include PhpSpreadsheet library
include 'config.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

try {
    $pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $kode_transaksi = $_POST['kode_transaksi'];

    // Fetch transaction details
    $sql = "
        SELECT 
            kt.*, 
            u.nama_karyawan AS kasir_name,
            kt.nama_cabang AS kasir_cabang,
            (SELECT SUM(jumlah_penjualan) FROM data_penjualan WHERE kode_transaksi = :kode_transaksi) AS data_penjualan,
            (SELECT SUM(jumlah_servis) FROM data_servis WHERE kode_transaksi = :kode_transaksi) AS data_servis,
            (SELECT SUM(jumlah) FROM pengeluaran_kasir WHERE kode_transaksi = :kode_transaksi) AS total_pengeluaran,
            (SELECT SUM(jumlah) FROM pemasukan_kasir WHERE kode_transaksi = :kode_transaksi) AS total_pemasukan,
            ka.total_nilai AS kas_awal,
            kcl.total_nilai AS kas_akhir,
            ka.tanggal AS kas_awal_date,
            kcl.tanggal AS kas_akhir_date,
            ka.waktu AS kas_awal_time,
            kcl.waktu AS kas_akhir_time,
            kt.tanggal_closing,
            kt.jam_closing
        FROM kasir_transactions kt
        LEFT JOIN kas_awal ka ON ka.kode_transaksi = kt.kode_transaksi
        LEFT JOIN kas_akhir kcl ON kcl.kode_transaksi = kt.kode_transaksi
        LEFT JOIN users u ON u.kode_karyawan = kt.kode_karyawan
        WHERE kt.kode_transaksi = :kode_transaksi";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':kode_transaksi' => $kode_transaksi]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        die("Transaksi tidak ditemukan.");
    }

    // Fetch additional transaction-related data
    $pemasukan_kasir = $pdo->query("SELECT * FROM pemasukan_kasir WHERE kode_transaksi = '$kode_transaksi'")->fetchAll(PDO::FETCH_ASSOC);
    $pengeluaran_biaya = $pdo->query("SELECT * FROM pengeluaran_kasir WHERE kode_transaksi = '$kode_transaksi' AND kategori = 'biaya'")->fetchAll(PDO::FETCH_ASSOC);
    $pengeluaran_non_biaya = $pdo->query("SELECT * FROM pengeluaran_kasir WHERE kode_transaksi = '$kode_transaksi' AND kategori = 'non_biaya'")->fetchAll(PDO::FETCH_ASSOC);
    $kas_awal_detail = $pdo->query("SELECT * FROM detail_kas_awal WHERE kode_transaksi = '$kode_transaksi'")->fetchAll(PDO::FETCH_ASSOC);
    $kas_akhir_detail = $pdo->query("SELECT * FROM detail_kas_akhir WHERE kode_transaksi = '$kode_transaksi'")->fetchAll(PDO::FETCH_ASSOC);

    // Calculated fields
    $data_penjualan = $transaction['data_penjualan'] ?? 0;
    $data_servis = $transaction['data_servis'] ?? 0;
    $total_pemasukan = $transaction['total_pemasukan'] ?? 0;
    $total_pengeluaran = $transaction['total_pengeluaran'] ?? 0;
    $omset = $data_penjualan + $data_servis;
    $kas_awal = $transaction['kas_awal'];
    $kas_akhir = $transaction['kas_akhir'];
    $setoran_real = $kas_akhir - $kas_awal;
    $setoran_data = $omset + $total_pemasukan - $total_pengeluaran;
    $selisih_setoran = $setoran_real - $setoran_data;

    // Create Excel sheet
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Page setup
    $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);

    // Column width adjustments
    $sheet->getColumnDimension('A')->setWidth(20);
    $sheet->getColumnDimension('B')->setWidth(25);
    $sheet->getColumnDimension('C')->setWidth(30);
    $sheet->getColumnDimension('D')->setWidth(15);
    $sheet->getColumnDimension('E')->setWidth(15);

    // Border and formatting styles
    $borderStyle = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => '000000'],
            ],
        ],
    ];

    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']]
    ];

    $wrapTextStyle = [
        'alignment' => [
            'wrapText' => true,
            'vertical' => Alignment::VERTICAL_TOP
        ]
    ];

    // Number formatting
    $rupiahFormat = '#,##0';
    $dateFormat = 'dd-mm-yyyy';
    $timeFormat = 'hh:mm:ss';

    // Title
    $sheet->setCellValue('A1', "Laporan Closing Kasir - " . $transaction['kasir_cabang']);
    $sheet->mergeCells('A1:E1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Transaction Details
    $sheet->setCellValue('A3', 'Kode Transaksi')->setCellValue('B3', $kode_transaksi);
    $sheet->setCellValue('A4', 'Nama Kasir')->setCellValue('B4', $transaction['kasir_name']);
    $sheet->setCellValue('A5', 'Tanggal Kas Awal')->setCellValue('B5', $transaction['kas_awal_date']);
    $sheet->setCellValue('A6', 'Jam Kas Awal')->setCellValue('B6', $transaction['kas_awal_time']);
    $sheet->setCellValue('A7', 'Tanggal Kas Akhir')->setCellValue('B7', $transaction['kas_akhir_date']);
    $sheet->setCellValue('A8', 'Jam Kas Akhir')->setCellValue('B8', $transaction['kas_akhir_time']);
    $sheet->setCellValue('A9', 'Tanggal Closing')->setCellValue('B9', $transaction['tanggal_closing']);
    $sheet->setCellValue('A10', 'Jam Closing')->setCellValue('B10', $transaction['jam_closing']);
    $sheet->getStyle('B5:B9')->getNumberFormat()->setFormatCode($dateFormat);
    $sheet->getStyle('B6:B10')->getNumberFormat()->setFormatCode($timeFormat);
    $sheet->getStyle("A3:B10")->applyFromArray($borderStyle);

// System Data Section
$row = 12;
$sheet->setCellValue('A' . $row, 'Data Sistem Aplikasi');
$sheet->mergeCells('A' . $row . ':B' . $row);
$sheet->getStyle('A' . $row)->applyFromArray($headerStyle);
$row++;

$sheet->setCellValue('A' . $row, 'Keterangan');
$sheet->setCellValue('B' . $row, 'Nominal');
$sheet->getStyle('A' . $row . ':B' . $row)->applyFromArray($headerStyle);
$row++;

$sistemData = [
    'Omset Penjualan' => $data_penjualan,
    'Omset Servis' => $data_servis,
    'Jumlah Omset' => $omset,
    'Pemasukan Kasir' => $total_pemasukan,
    'Pengeluaran Kasir' => $total_pengeluaran,
    'Data Setoran' => $setoran_data,
    'Selisih Setoran' => $selisih_setoran
];

$startSistemDataRow = $row;
foreach ($sistemData as $label => $value) {
    $sheet->setCellValue('A' . $row, $label);
    $sheet->setCellValue('B' . $row, $value);
    $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode($rupiahFormat);
    $row++;
}

$sheet->getStyle('A' . ($startSistemDataRow - 1) . ':B' . ($row - 1))->applyFromArray($borderStyle);

// Real Cash Section
$row += 2;
$sheet->setCellValue('A' . $row, 'Riil Uang');
$sheet->mergeCells('A' . $row . ':B' . $row);
$sheet->getStyle('A' . $row)->applyFromArray($headerStyle);
$row++;

$sheet->setCellValue('A' . $row, 'Keterangan');
$sheet->setCellValue('B' . $row, 'Nominal');
$sheet->getStyle('A' . $row . ':B' . $row)->applyFromArray($headerStyle);
$row++;

$riilUangData = [
    'Kas Awal' => $kas_awal,
    'Kas Akhir' => $kas_akhir,
    'Setoran Riil' => $setoran_real
];

$startRiilUangRow = $row;
foreach ($riilUangData as $label => $value) {
    $sheet->setCellValue('A' . $row, $label);
    $sheet->setCellValue('B' . $row, $value);
    $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode($rupiahFormat);
    $row++;
}

$sheet->getStyle('A' . ($startRiilUangRow - 1) . ':B' . ($row - 1))->applyFromArray($borderStyle);

// Pemasukan Kasir Section
$row += 2;
$sheet->setCellValue('A' . $row, 'Pemasukan Kasir');
$sheet->mergeCells("A{$row}:E{$row}");
$sheet->getStyle("A{$row}:E{$row}")->applyFromArray($headerStyle);
$row++;
$sheet->setCellValue('A' . $row, 'Kode Akun')->setCellValue('B' . $row, 'Jumlah (Rp)')
    ->setCellValue('C' . $row, 'Keterangan')->setCellValue('D' . $row, 'Tanggal')->setCellValue('E' . $row, 'Waktu');
$sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
$sheet->getStyle("A{$row}:E{$row}")->applyFromArray($headerStyle);
$row++;
$startRow = $row;
foreach ($pemasukan_kasir as $pemasukan) {
    $sheet->setCellValue('A' . $row, $pemasukan['kode_akun'])
        ->setCellValue('B' . $row, $pemasukan['jumlah'])
        ->setCellValue('C' . $row, $pemasukan['keterangan_transaksi'])
        ->setCellValue('D' . $row, $pemasukan['tanggal'])
        ->setCellValue('E' . $row, $pemasukan['waktu']);
    
    // Aktifkan wrap text dan atur tinggi baris otomatis
    $sheet->getStyle('C' . $row)->getAlignment()->setWrapText(true);
    $sheet->getRowDimension($row)->setRowHeight(-1);  // Otomatis sesuaikan tinggi baris
    
    $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode($rupiahFormat);
    $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode($dateFormat);
    $row++;
}
$sheet->getStyle("A" . ($startRow - 1) . ":E" . ($row - 1))->applyFromArray($borderStyle);

// Lakukan hal yang sama untuk Pengeluaran Kasir - Biaya
$row += 2;
$sheet->setCellValue('A' . $row, 'Pengeluaran Kasir - Biaya');
$sheet->mergeCells("A{$row}:E{$row}");
$sheet->getStyle("A{$row}:E{$row}")->applyFromArray($headerStyle);
$row++;
$sheet->setCellValue('A' . $row, 'Kode Akun')->setCellValue('B' . $row, 'Jumlah (Rp)')
    ->setCellValue('C' . $row, 'Keterangan')->setCellValue('D' . $row, 'Tanggal')->setCellValue('E' . $row, 'Waktu');
$sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
$sheet->getStyle("A{$row}:E{$row}")->applyFromArray($headerStyle);
$row++;
$startRow = $row;
foreach ($pengeluaran_biaya as $pengeluaran) {
    $sheet->setCellValue('A' . $row, $pengeluaran['kode_akun'])
        ->setCellValue('B' . $row, $pengeluaran['jumlah'])
        ->setCellValue('C' . $row, $pengeluaran['keterangan_transaksi'])
        ->setCellValue('D' . $row, $pengeluaran['tanggal'])
        ->setCellValue('E' . $row, $pengeluaran['waktu']);
    
    // Aktifkan wrap text dan atur tinggi baris otomatis
    $sheet->getStyle('C' . $row)->getAlignment()->setWrapText(true);
    $sheet->getRowDimension($row)->setRowHeight(-1);  // Otomatis sesuaikan tinggi baris
    
    $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode($rupiahFormat);
    $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode($dateFormat);
    $row++;
}
$sheet->getStyle("A" . ($startRow - 1) . ":E" . ($row - 1))->applyFromArray($borderStyle);

// Dan untuk Pengeluaran Kasir - Non Biaya
$row += 2;
$sheet->setCellValue('A' . $row, 'Pengeluaran Kasir - Non Biaya');
$sheet->mergeCells("A{$row}:E{$row}");
$sheet->getStyle("A{$row}:E{$row}")->applyFromArray($headerStyle);
$row++;
$sheet->setCellValue('A' . $row, 'Kode Akun')->setCellValue('B' . $row, 'Jumlah (Rp)')
    ->setCellValue('C' . $row, 'Keterangan')->setCellValue('D' . $row, 'Tanggal')->setCellValue('E' . $row, 'Waktu');
$sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
$sheet->getStyle("A{$row}:E{$row}")->applyFromArray($headerStyle);
$row++;
$startRow = $row;
foreach ($pengeluaran_non_biaya as $pengeluaran) {
    $sheet->setCellValue('A' . $row, $pengeluaran['kode_akun'])
        ->setCellValue('B' . $row, $pengeluaran['jumlah'])
        ->setCellValue('C' . $row, $pengeluaran['keterangan_transaksi'])
        ->setCellValue('D' . $row, $pengeluaran['tanggal'])
        ->setCellValue('E' . $row, $pengeluaran['waktu']);
    
    // Aktifkan wrap text dan atur tinggi baris otomatis
    $sheet->getStyle('C' . $row)->getAlignment()->setWrapText(true);
    $sheet->getRowDimension($row)->setRowHeight(-1);  // Otomatis sesuaikan tinggi baris
    
    $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode($rupiahFormat);
    $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode($dateFormat);
    $row++;
}
$sheet->getStyle("A" . ($startRow - 1) . ":E" . ($row - 1))->applyFromArray($borderStyle);

    // Kas Awal Section
    $row += 2;
    $sheet->setCellValue('A' . $row, 'Data Kas Awal');
    $sheet->mergeCells("A{$row}:C{$row}");
    $sheet->getStyle("A{$row}:C{$row}")->applyFromArray($headerStyle);
    $row++;
    $sheet->setCellValue('A' . $row, 'Nominal')->setCellValue('B' . $row, 'Jumlah Keping')->setCellValue('C' . $row, 'Total');
    $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
    $sheet->getStyle("A{$row}:C{$row}")->applyFromArray($headerStyle);
    $row++;
    $startRow = $row;
    $totalKasAwal = 0;
    foreach ($kas_awal_detail as $detail) {
        $total = $detail['nominal'] * $detail['jumlah_keping'];
        $totalKasAwal += $total;
        $sheet->setCellValue('A' . $row, $detail['nominal'])
            ->setCellValue('B' . $row, $detail['jumlah_keping'])
            ->setCellValue('C' . $row, $total);
        $sheet->getStyle('A' . $row)->getNumberFormat()->setFormatCode($rupiahFormat);
        $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode($rupiahFormat);
        $row++;
    }
    // Tambahkan baris total
    $sheet->setCellValue('A' . $row, 'Total')->setCellValue('C' . $row, $totalKasAwal);
    $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
    $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode($rupiahFormat);
    $sheet->getStyle("A" . ($startRow - 1) . ":C" . $row)->applyFromArray($borderStyle);
    $sheet->getStyle("A" . ($startRow - 1) . ":C" . $row)->applyFromArray($wrapTextStyle);

    // Kas Akhir Section
    $row += 2;
    $sheet->setCellValue('A' . $row, 'Data Kas Akhir');
    $sheet->mergeCells("A{$row}:C{$row}");
    $sheet->getStyle("A{$row}:C{$row}")->applyFromArray($headerStyle);
    $row++;
    $sheet->setCellValue('A' . $row, 'Nominal')->setCellValue('B' . $row, 'Jumlah Keping')->setCellValue('C' . $row, 'Total');
    $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
    $sheet->getStyle("A{$row}:C{$row}")->applyFromArray($headerStyle);
    $row++;
    $startRow = $row;
    $totalKasAkhir = 0;
    foreach ($kas_akhir_detail as $detail) {
        $total = $detail['nominal'] * $detail['jumlah_keping'];
        $totalKasAkhir += $total;
        $sheet->setCellValue('A' . $row, $detail['nominal'])
            ->setCellValue('B' . $row, $detail['jumlah_keping'])
            ->setCellValue('C' . $row, $total);
        $sheet->getStyle('A' . $row)->getNumberFormat()->setFormatCode($rupiahFormat);
        $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode($rupiahFormat);
        $row++;
    }
    // Tambahkan baris total
    $sheet->setCellValue('A' . $row, 'Total')->setCellValue('C' . $row, $totalKasAkhir);
    $sheet->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
    $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode($rupiahFormat);
    $sheet->getStyle("A" . ($startRow - 1) . ":C" . $row)->applyFromArray($borderStyle);
    $sheet->getStyle("A" . ($startRow - 1) . ":C" . $row)->applyFromArray($wrapTextStyle);

    // Save Excel file
    $writer = new Xlsx($spreadsheet);
    $filename = 'Laporan_Closing_Kasir_' . $kode_transaksi . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
