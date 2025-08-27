<?php
// Start output buffering dan disable error reporting untuk production
ob_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require 'vendor/autoload.php';
require 'config.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

session_start();
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    ob_end_clean();
    die("Akses ditolak. Hanya untuk admin dan super_admin.");
}

// Fungsi untuk mengekstrak tanggal dari kode_transaksi
function extractTransactionDate($kode_transaksi, $format = 'full') {
    if (preg_match('/(PMK|PST)-(\d{4})(\d{2})(\d{2})-/', $kode_transaksi, $matches)) {
        $year = $matches[2];
        $month = $matches[3];
        $day = $matches[4];
        switch ($format) {
            case 'year': return $year;
            case 'month': return $year . '-' . $month;
            case 'full':
            default: return $year . '-' . $month . '-' . $day;
        }
    }
    return '-';
}

// Fungsi untuk menentukan format tanggal berdasarkan filter
function determineDateFormat($tanggal_awal, $tanggal_akhir) {
    if ($tanggal_awal === $tanggal_akhir && preg_match('/^\d{4}$/', $tanggal_awal)) {
        return 'year';
    }
    if ($tanggal_awal === $tanggal_akhir && preg_match('/^\d{4}-\d{2}$/', $tanggal_awal)) {
        return 'month';
    }
    return 'full';
}

// Fungsi untuk memparse filter tanggal fleksibel
function parseFlexibleDate($date_string, $is_start = true) {
    if (empty($date_string)) return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_string)) return $date_string;
    if (preg_match('/^\d{4}-\d{2}$/', $date_string)) {
        return $is_start ? $date_string . '-01' : date('Y-m-t', strtotime($date_string . '-01'));
    }
    if (preg_match('/^\d{4}$/', $date_string)) {
        return $is_start ? $date_string . '-01-01' : $date_string . '-12-31';
    }
    return $date_string;
}

// Koneksi database
try {
    $dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta;charset=utf8mb4";
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");
} catch (PDOException $e) {
    ob_end_clean();
    die("Koneksi database gagal: " . htmlspecialchars($e->getMessage()));
}

// Set variabel untuk filter
$jenis_data = $_GET['jenis_data'] ?? 'semua';
$filter_tanggal_awal = $_GET['filter_tanggal_awal'] ?? null;
$filter_tanggal_akhir = $_GET['filter_tanggal_akhir'] ?? null;
$filter_cabang = $_GET['filter_cabang'] ?? null;

$tanggal_awal_parsed = parseFlexibleDate($filter_tanggal_awal, true);
$tanggal_akhir_parsed = parseFlexibleDate($filter_tanggal_akhir, false);
$date_format = determineDateFormat($filter_tanggal_awal, $filter_tanggal_akhir);

$sort_by = $_GET['sort_by'] ?? 'tanggal';
$sort_order = $_GET['sort_order'] ?? 'ASC';

$allowed_sort_columns = ['tanggal', 'waktu', 'kode_transaksi', 'nama_cabang', 'kategori_akun', 'nama_akun', 'kode_akun', 'keterangan_akun', 'jumlah'];
if (!in_array($sort_by, $allowed_sort_columns)) $sort_by = 'tanggal';
if (!in_array(strtoupper($sort_order), ['ASC', 'DESC'])) $sort_order = 'ASC';

// Query utama untuk pemasukan saja (matching pengeluaran format)
try {
    $query_kasir = "SELECT 
                    CAST(kode_transaksi AS CHAR(20)) COLLATE utf8mb4_unicode_ci AS kode_transaksi,
                    CAST(nama_cabang AS CHAR(100)) COLLATE utf8mb4_unicode_ci AS nama_cabang,
                    tanggal,
                    waktu,
                    CAST(kode_akun AS CHAR(10)) COLLATE utf8mb4_unicode_ci AS kode_akun,
                    CAST(nama_akun AS CHAR(100)) COLLATE utf8mb4_unicode_ci AS nama_akun,
                    CAST(jenis_akun AS CHAR(20)) COLLATE utf8mb4_unicode_ci AS kategori_akun,
                    jumlah,
                    CAST(keterangan_akun AS CHAR(255)) COLLATE utf8mb4_unicode_ci AS keterangan_akun,
                    CAST('kasir' AS CHAR(10)) COLLATE utf8mb4_unicode_ci AS jenis_sumber,
                    tanggal_transaksi,
                    CAST(datetime_input AS CHAR(21)) COLLATE utf8mb4_unicode_ci AS datetime_input,
                    CAST(NULL AS CHAR(50)) COLLATE utf8mb4_unicode_ci AS nama_karyawan,
                    CAST(NULL AS CHAR(20)) COLLATE utf8mb4_unicode_ci AS umur_pakai
                FROM view_pemasukan_kasir
                WHERE 1=1";

    $query_pusat = "SELECT 
                    CAST(pp.kode_transaksi AS CHAR(20)) COLLATE utf8mb4_unicode_ci AS kode_transaksi,
                    CAST(pp.cabang AS CHAR(100)) COLLATE utf8mb4_unicode_ci AS nama_cabang,
                    pp.tanggal,
                    pp.waktu,
                    CAST(pp.kode_akun AS CHAR(10)) COLLATE utf8mb4_unicode_ci AS kode_akun,
                    CAST(ma.arti AS CHAR(100)) COLLATE utf8mb4_unicode_ci AS nama_akun,
                    CAST(ma.jenis_akun AS CHAR(20)) COLLATE utf8mb4_unicode_ci AS kategori_akun,
                    pp.jumlah,
                    CAST(pp.keterangan AS CHAR(255)) COLLATE utf8mb4_unicode_ci AS keterangan_akun,
                    CAST('pusat' AS CHAR(10)) COLLATE utf8mb4_unicode_ci AS jenis_sumber,
                    pp.tanggal AS tanggal_transaksi,
                    CAST(CONCAT(pp.tanggal, ' ', pp.waktu) AS CHAR(21)) COLLATE utf8mb4_unicode_ci AS datetime_input,
                    CAST(u.nama_karyawan AS CHAR(50)) COLLATE utf8mb4_unicode_ci AS nama_karyawan,
                    CAST(NULL AS CHAR(20)) COLLATE utf8mb4_unicode_ci AS umur_pakai
                FROM pemasukan_pusat pp
                LEFT JOIN master_akun ma ON pp.kode_akun = ma.kode_akun
                LEFT JOIN users u ON pp.kode_karyawan = u.kode_karyawan
                WHERE 1=1";

    $params = [];
    $filters = [];
    if ($tanggal_awal_parsed && $tanggal_akhir_parsed) {
        $filters[] = " AND tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
        $params[':tanggal_awal'] = $tanggal_awal_parsed;
        $params[':tanggal_akhir'] = $tanggal_akhir_parsed;
    }
    if ($filter_cabang) {
        $filters[] = " AND nama_cabang = :cabang";
        $params[':cabang'] = $filter_cabang;
    }

    $filter_string = implode('', $filters);
    $query_kasir .= $filter_string;
    $query_pusat = str_replace('nama_cabang', 'cabang', $query_pusat) . $filter_string;

    $sort_column = $sort_by;
    if ($sort_by === 'nama_cabang') $sort_column = 'nama_cabang';
    elseif ($sort_by === 'kategori_akun') $sort_column = 'kategori_akun';
    elseif ($sort_by === 'kode_akun') $sort_column = 'kode_akun';

    $query = "($query_kasir) UNION ALL ($query_pusat) ORDER BY $sort_column " . strtoupper($sort_order);
    if ($sort_by !== 'tanggal') $query .= ", tanggal " . strtoupper($sort_order);

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Buat Spreadsheet
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Set properti dokumen
    $spreadsheet->getProperties()
        ->setCreator("Fitmotor Maintenance System")
        ->setLastModifiedBy($_SESSION['nama_karyawan'] ?? 'System')
        ->setTitle("Laporan Pemasukan")
        ->setSubject("Detail Pemasukan")
        ->setDescription("Laporan detail pemasukan dari sistem Fitmotor Maintenance")
        ->setKeywords("pemasukan fitmotor maintenance")
        ->setCategory("Laporan Keuangan");

    // Set header row (dengan tanggal transaksi)
    $headers = ['No', 'Tanggal Input', 'Tanggal Transaksi', 'Waktu', 'Cabang', 'Kode Akun', 'Nama Akun', 'Kategori Akun', 'Jumlah (Rp)', 'Keterangan', 'Umur Pakai', 'Input By'];
    $sheet->fromArray($headers, NULL, 'A1');

    // Terapkan bold font dan warna fill untuk header row
    $sheet->getStyle('A1:L1')->getFont()->setBold(true);
    $sheet->getStyle('A1:L1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFCCCCCC');

    // Masukkan data rows (dengan tanggal transaksi)
    $rowIndex = 2;
    foreach ($transactions as $index => $row) {
        $tanggal_input = !empty($row['tanggal']) ? strtotime($row['tanggal']) : time();
        $tanggal_transaksi = !empty($row['tanggal_transaksi']) ? strtotime($row['tanggal_transaksi']) : $tanggal_input;
        $jumlah = is_numeric($row['jumlah']) ? (float)$row['jumlah'] : 0;

        $sheet->setCellValue("A{$rowIndex}", $index + 1);
        $sheet->setCellValue("B{$rowIndex}", \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($tanggal_input));
        $sheet->setCellValue("C{$rowIndex}", \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($tanggal_transaksi));
        $sheet->setCellValue("D{$rowIndex}", $row['waktu'] ?? '');
        $sheet->setCellValue("E{$rowIndex}", ucfirst($row['nama_cabang'] ?? ''));
        $sheet->setCellValue("F{$rowIndex}", $row['kode_akun'] ?? '');
        $sheet->setCellValue("G{$rowIndex}", $row['nama_akun'] ?? '-');
        $sheet->setCellValue("H{$rowIndex}", $row['kategori_akun'] ?? '-');
        $sheet->setCellValue("I{$rowIndex}", $jumlah);
        $sheet->setCellValue("J{$rowIndex}", $row['keterangan_akun'] ?? '-');
        $sheet->setCellValue("K{$rowIndex}", $row['umur_pakai'] ?? '-');
        $sheet->setCellValue("L{$rowIndex}", $row['nama_karyawan'] ?? '-');
        $rowIndex++;
    }

    // Terapkan formatting
    if ($rowIndex > 2) {
        $sheet->getStyle("B2:B" . ($rowIndex - 1))
              ->getNumberFormat()
              ->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
        $sheet->getStyle("C2:C" . ($rowIndex - 1))
              ->getNumberFormat()
              ->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
        $sheet->getStyle("I2:I" . ($rowIndex - 1))
              ->getNumberFormat()
              ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
    }

    // Auto-adjust column widths
    foreach (range('A', 'L') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    // Terapkan border style ke semua sel
    $styleArray = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'FF000000'],
            ],
        ],
    ];
    if ($rowIndex > 1) {
        $sheet->getStyle("A1:L" . ($rowIndex - 1))->applyFromArray($styleArray);
    }

    // Tambahkan summary row
    if (count($transactions) > 0) {
        $total_amount = array_sum(array_column($transactions, 'jumlah'));

        $rowIndex++;
        $sheet->setCellValue("A{$rowIndex}", "TOTAL PEMASUKAN");
        $sheet->mergeCells("A{$rowIndex}:H{$rowIndex}");
        $sheet->setCellValue("I{$rowIndex}", $total_amount);
        $sheet->getStyle("A{$rowIndex}:L{$rowIndex}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowIndex}:L{$rowIndex}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFCCFFCC');
        $sheet->getStyle("A{$rowIndex}:L{$rowIndex}")->applyFromArray($styleArray);
        $sheet->getStyle("I{$rowIndex}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
    }

    // Tambahkan information sheet
    $infoSheet = $spreadsheet->createSheet();
    $infoSheet->setTitle('Info Laporan');

    $filter_period_display = 'Semua Data';
    if ($filter_tanggal_awal && $filter_tanggal_akhir) {
        if ($filter_tanggal_awal === $filter_tanggal_akhir) {
            if (preg_match('/^\d{4}-\d{2}$/', $filter_tanggal_awal)) {
                $filter_period_display = 'Bulan ' . date('F Y', strtotime($filter_tanggal_awal . '-01'));
            } elseif (preg_match('/^\d{4}$/', $filter_tanggal_awal)) {
                $filter_period_display = 'Tahun ' . $filter_tanggal_awal;
            } else {
                $filter_period_display = date('d F Y', strtotime($tanggal_awal_parsed)) . ' s/d ' . date('d F Y', strtotime($tanggal_akhir_parsed));
            }
        } else {
            $filter_period_display = date('d F Y', strtotime($tanggal_awal_parsed)) . ' s/d ' . date('d F Y', strtotime($tanggal_akhir_parsed));
        }
    }

    $infoData = [
        ['INFORMASI LAPORAN PEMASUKAN'],
        [''],
        ['Tanggal Export:', date('d F Y - H:i:s') . ' WIB'],
        ['User Export:', $_SESSION['nama_karyawan'] ?? 'Unknown User'],
        ['Role:', ucfirst($_SESSION['role'] ?? 'User')],
        [''],
        ['FILTER YANG DIGUNAKAN:'],
        ['Periode:', $filter_period_display],
        ['Cabang:', $filter_cabang ? ucfirst($filter_cabang) : 'Semua Cabang'],
        ['Jenis Data:', ucfirst($jenis_data)],
        ['Sorting:', ucfirst($sort_by) . ' - ' . ($sort_order === 'ASC' ? 'Ascending' : 'Descending')],
        [''],
        ['STATISTIK:'],
        ['Total Transaksi:', number_format(count($transactions)) . ' transaksi'],
        ['Total Pemasukan:', 'Rp ' . number_format($total_amount, 0, ',', '.')],
        [''],
        ['KETERANGAN URUTAN KOLOM (TELAH DIPERBAIKI):'],
        ['1. No - Nomor urut transaksi'],
        ['2. Tanggal Input - Tanggal input transaksi ke sistem'],
        ['3. Tanggal Transaksi - Tanggal aktual transaksi terjadi'],
        ['4. Waktu - Waktu input transaksi'],
        ['5. Cabang - Nama cabang tempat transaksi'],
        ['6. Kode Akun - Kode akun'],
        ['7. Nama Akun - Nama akun'],
        ['8. Kategori Akun - Kategori/jenis akun'],
        ['9. Jumlah (Rp) - Nominal transaksi'],
        ['10. Keterangan - Keterangan transaksi'],
        ['11. Umur Pakai - Umur pakai (untuk pemasukan biasanya kosong)'],
        ['12. Input By - Nama karyawan yang menginput'],
        [''],
        ['CATATAN PENTING:'],
        ['- Laporan ini dibuat secara otomatis oleh sistem Fitmotor Maintenance'],
        ['- PERBAIKAN: Tanggal transaksi sekarang ditampilkan terpisah dari tanggal input'],
        ['- Format kolom telah disesuaikan dengan kebutuhan analisis'],
        ['- Data yang ditampilkan sesuai dengan filter dan sorting yang dipilih'],
        ['- File ini dapat dibuka dengan Microsoft Excel atau aplikasi spreadsheet lainnya']
    ];

    $infoSheet->fromArray($infoData, NULL, 'A1');
    $infoSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $infoSheet->getStyle('A7')->getFont()->setBold(true);
    $infoSheet->getStyle('A13')->getFont()->setBold(true);
    $infoSheet->getStyle('A17')->getFont()->setBold(true);
    foreach (range('A', 'B') as $columnID) {
        $infoSheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    // Kembali ke sheet data
    $spreadsheet->setActiveSheetIndex(0);

    // Generate nama file
    $filename = "laporan_pemasukan";
    if ($filter_tanggal_awal && $filter_tanggal_akhir) {
        if ($filter_tanggal_awal === $filter_tanggal_akhir) {
            if (preg_match('/^\d{4}-\d{2}$/', $filter_tanggal_awal)) {
                $filename .= "_" . str_replace('-', '', $filter_tanggal_awal);
            } elseif (preg_match('/^\d{4}$/', $filter_tanggal_awal)) {
                $filename .= "_" . $filter_tanggal_awal;
            } else {
                $filename .= "_" . str_replace('-', '', $tanggal_awal_parsed);
            }
        } else {
            $filename .= "_" . str_replace('-', '', $tanggal_awal_parsed) . "_" . str_replace('-', '', $tanggal_akhir_parsed);
        }
    }
    if ($filter_cabang) {
        $filename .= "_" . preg_replace('/[^a-zA-Z0-9]/', '', $filter_cabang);
    }
    $filename .= "_sort_" . $sort_by . "_" . strtolower($sort_order);
    $filename .= "_" . date('Ymd_His') . ".xlsx";

    // Bersihkan output buffer dan set header
    ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Expires: 0');

    // Tulis ke output
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo "Error generating Excel file: " . htmlspecialchars($e->getMessage());
    error_log("Excel Export Error (Pemasukan): " . $e->getMessage() . " - " . (isset($query) ? $query : 'No query'));
    exit;
} catch (PDOException $e) {
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo "Database Error: " . htmlspecialchars($e->getMessage()) . "<br>Query: " . htmlspecialchars($query);
    error_log("Database Error in Excel Export (Pemasukan): " . $e->getMessage() . " - Query: " . $query);
    exit;
}

exit;
?>