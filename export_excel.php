<?php
// PERBAIKAN: Mulai output buffering dan matikan error reporting
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

// Function to extract transaction date from kode_transaksi with flexible format
function extractTransactionDate($kode_transaksi, $format = 'full') {
    $year = $month = $day = null;
    
    // For PST format: PST-20240115-USR001001
    if (preg_match('/PST-(\d{4})(\d{2})(\d{2})-/', $kode_transaksi, $matches)) {
        $year = $matches[1];
        $month = $matches[2];
        $day = $matches[3];
    }
    // For TRX format: TRX-20240115-
    elseif (preg_match('/TRX-(\d{4})(\d{2})(\d{2})-/', $kode_transaksi, $matches)) {
        $year = $matches[1];
        $month = $matches[2];
        $day = $matches[3];
    }
    
    if ($year && $month) {
        switch ($format) {
            case 'year':
                return mktime(0, 0, 0, 1, 1, $year); // Return timestamp for start of year
            case 'month':
                return mktime(0, 0, 0, $month, 1, $year); // Return timestamp for start of month
            case 'full':
            default:
                if ($day) {
                    return mktime(0, 0, 0, $month, $day, $year); // Return timestamp for full date
                }
                return mktime(0, 0, 0, $month, 1, $year); // Fallback to start of month if day is missing
        }
    }
    
    return null; // Return null if date cannot be extracted
}

// Function to determine date format based on filter input
function determineDateFormat($tanggal_awal, $tanggal_akhir) {
    if ($tanggal_awal === $tanggal_akhir && preg_match('/^\d{4}$/', $tanggal_awal)) {
        return 'year';
    }
    if ($tanggal_awal === $tanggal_akhir && preg_match('/^\d{4}-\d{2}$/', $tanggal_awal)) {
        return 'month';
    }
    return 'full';
}

// Function to parse flexible date filters
function parseFlexibleDate($date_string, $is_start = true) {
    if (empty($date_string)) return null;
    
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_string)) {
        return $date_string;
    }
    
    if (preg_match('/^\d{4}-\d{2}$/', $date_string)) {
        if ($is_start) {
            return $date_string . '-01';
        } else {
            return date('Y-m-t', strtotime($date_string . '-01'));
        }
    }
    
    if (preg_match('/^\d{4}$/', $date_string)) {
        if ($is_start) {
            return $date_string . '-01-01';
        } else {
            return $date_string . '-12-31';
        }
    }
    
    return $date_string;
}

try {
    // Set filter variables from GET parameters
    $tanggal_awal = $_GET['tanggal_awal'] ?? null;
    $tanggal_akhir = $_GET['tanggal_akhir'] ?? null;
    $kategori = $_GET['kategori'] ?? null;
    $cabang = $_GET['cabang'] ?? null;
    $jenis_data = $_GET['jenis_data'] ?? 'kasir';

    // Parse flexible date filters
    $tanggal_awal_parsed = parseFlexibleDate($tanggal_awal, true);
    $tanggal_akhir_parsed = parseFlexibleDate($tanggal_akhir, false);

    // Determine date format for transaction date display
    $date_format = determineDateFormat($tanggal_awal, $tanggal_akhir);

    // Variabel untuk sorting
    $sort_by = $_GET['sort_by'] ?? 'tanggal';
    $sort_order = $_GET['sort_order'] ?? 'DESC';

    // Validasi sort_by untuk keamanan
    $allowed_sort_columns = [
        'tanggal', 'waktu', 'kode_transaksi', 'nama_cabang', 'kategori_akun',
        'nama_akun', 'tanggal_transaksi', 'kode_akun', 'umur_pakai', 'keterangan_akun', 'jumlah', 'jenis_sumber'
    ];

    if (!in_array($sort_by, $allowed_sort_columns)) {
        $sort_by = 'tanggal';
    }

    if (!in_array(strtoupper($sort_order), ['ASC', 'DESC'])) {
        $sort_order = 'DESC';
    }

    // Query berdasarkan jenis data dengan sorting dinamis
    $pengeluaran = [];

    if ($jenis_data === 'semua') {
        // UNION query untuk menggabungkan data kasir dan pusat
        try {
            $query_kasir = "SELECT 
                            kode_transaksi,
                            nama_cabang,
                            kategori_akun,
                            tanggal,
                            waktu,
                            kode_akun,
                            nama_akun,
                            jumlah,
                            umur_pakai,
                            keterangan_akun,
                            jenis_sumber,
                            tanggal_transaksi,
                            datetime_input
                          FROM view_pengeluaran_kasir
                          WHERE 1 = 1";
            
            $query_pusat = "SELECT 
                            kode_transaksi,
                            nama_cabang,
                            kategori_akun,
                            tanggal,
                            waktu,
                            kode_akun,
                            nama_akun,
                            jumlah,
                            umur_pakai,
                            keterangan_akun,
                            jenis_sumber,
                            tanggal_transaksi,
                            datetime_input
                          FROM view_pengeluaran_pusat
                          WHERE 1 = 1";
            
            $filter_conditions = [];
            if ($tanggal_awal_parsed && $tanggal_akhir_parsed) {
                $filter_conditions[] = " AND tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
            }
            if ($kategori) {
                $filter_conditions[] = " AND kategori_akun = :kategori";
            }
            if ($cabang) {
                $filter_conditions[] = " AND nama_cabang = :cabang";
            }
            
            $filter_string = implode('', $filter_conditions);
            $query_kasir .= $filter_string;
            $query_pusat .= $filter_string;
            
            $query = "({$query_kasir}) UNION ALL ({$query_pusat}) ORDER BY {$sort_by} " . strtoupper($sort_order);
            
            if ($sort_by !== 'tanggal') {
                $query .= ", tanggal " . strtoupper($sort_order);
            }
            
        } catch (PDOException $e) {
            // Fallback to original queries
            $query_kasir = "SELECT 
                            p.kode_transaksi,
                            k.nama_cabang,
                            p.kategori AS kategori_akun,
                            p.tanggal,
                            p.waktu,
                            p.kode_akun,
                            m.arti AS nama_akun,
                            p.jumlah,
                            p.umur_pakai,
                            p.keterangan_transaksi AS keterangan_akun,
                            'kasir' as jenis_sumber
                          FROM pengeluaran_kasir p
                          JOIN kasir_transactions k ON p.kode_transaksi = k.kode_transaksi
                          LEFT JOIN master_akun m ON p.kode_akun = m.kode_akun
                          WHERE 1 = 1";
            
            $query_pusat = "SELECT 
                            pp.kode_transaksi,
                            pp.cabang as nama_cabang,
                            pp.kategori AS kategori_akun,
                            pp.tanggal,
                            pp.waktu,
                            pp.kode_akun,
                            ma.arti AS nama_akun,
                            pp.jumlah,
                            pp.umur_pakai,
                            pp.keterangan AS keterangan_akun,
                            'pusat' as jenis_sumber
                          FROM pengeluaran_pusat pp
                          LEFT JOIN master_akun ma ON pp.kode_akun = ma.kode_akun
                          WHERE 1 = 1";
            
            if ($tanggal_awal_parsed && $tanggal_akhir_parsed) {
                $query_kasir .= " AND p.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
                $query_pusat .= " AND pp.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
            }
            if ($kategori) {
                $query_kasir .= " AND p.kategori = :kategori";
                $query_pusat .= " AND pp.kategori = :kategori";
            }
            if ($cabang) {
                $query_kasir .= " AND k.nama_cabang = :cabang";
                $query_pusat .= " AND pp.cabang = :cabang";
            }
            
            $query = "({$query_kasir}) UNION ALL ({$query_pusat}) ORDER BY {$sort_by} " . strtoupper($sort_order);
        }
        
    } else {
        // Single data source query
        try {
            if ($jenis_data === 'pusat') {
                $query = "SELECT 
                            kode_transaksi,
                            nama_cabang,
                            kategori_akun,
                            tanggal,
                            waktu,
                            kode_akun,
                            nama_akun,
                            jumlah,
                            umur_pakai,
                            keterangan_akun,
                            jenis_sumber,
                            tanggal_transaksi,
                            datetime_input
                          FROM view_pengeluaran_pusat
                          WHERE 1 = 1";
            } else {
                $query = "SELECT 
                            kode_transaksi,
                            nama_cabang,
                            kategori_akun,
                            tanggal,
                            waktu,
                            kode_akun,
                            nama_akun,
                            jumlah,
                            umur_pakai,
                            keterangan_akun,
                            jenis_sumber,
                            tanggal_transaksi,
                            datetime_input
                          FROM view_pengeluaran_kasir
                          WHERE 1 = 1";
            }
            
            if ($tanggal_awal_parsed && $tanggal_akhir_parsed) {
                $query .= " AND tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
            }
            if ($kategori) {
                $query .= " AND kategori_akun = :kategori";
            }
            if ($cabang) {
                $query .= " AND nama_cabang = :cabang";
            }
            
            $query .= " ORDER BY {$sort_by} " . strtoupper($sort_order);
            
            if ($sort_by !== 'tanggal') {
                $query .= ", tanggal " . strtoupper($sort_order);
            }
            
        } catch (PDOException $e) {
            // Fallback queries
            if ($jenis_data === 'pusat') {
                $query = "SELECT 
                            pp.kode_transaksi,
                            pp.cabang as nama_cabang,
                            pp.kategori AS kategori_akun,
                            pp.tanggal,
                            pp.waktu,
                            pp.kode_akun,
                            ma.arti AS nama_akun,
                            pp.jumlah,
                            pp.umur_pakai,
                            pp.keterangan AS keterangan_akun,
                            'pusat' as jenis_sumber
                          FROM pengeluaran_pusat pp
                          LEFT JOIN master_akun ma ON pp.kode_akun = ma.kode_akun
                          WHERE 1 = 1";
                
                if ($tanggal_awal_parsed && $tanggal_akhir_parsed) {
                    $query .= " AND pp.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
                }
                if ($kategori) {
                    $query .= " AND pp.kategori = :kategori";
                }
                if ($cabang) {
                    $query .= " AND pp.cabang = :cabang";
                }
                
                $query .= " ORDER BY pp.{$sort_by} " . strtoupper($sort_order);
                
            } else {
                $query = "SELECT 
                            p.kode_transaksi,
                            k.nama_cabang,
                            p.kategori AS kategori_akun,
                            p.tanggal,
                            p.waktu,
                            p.kode_akun,
                            m.arti AS nama_akun,
                            p.jumlah,
                            p.umur_pakai,
                            p.keterangan_transaksi AS keterangan_akun,
                            'kasir' as jenis_sumber
                          FROM pengeluaran_kasir p
                          JOIN kasir_transactions k ON p.kode_transaksi = k.kode_transaksi
                          LEFT JOIN master_akun m ON p.kode_akun = m.kode_akun
                          WHERE 1 = 1";
                
                if ($tanggal_awal_parsed && $tanggal_akhir_parsed) {
                    $query .= " AND p.tanggal BETWEEN :tanggal_awal AND :tanggal_akhir";
                }
                if ($kategori) {
                    $query .= " AND p.kategori = :kategori";
                }
                if ($cabang) {
                    $query .= " AND k.nama_cabang = :cabang";
                }
                
                $fallback_sort_by = $sort_by;
                if ($sort_by === 'nama_cabang') $fallback_sort_by = 'k.nama_cabang';
                elseif ($sort_by === 'kategori_akun') $fallback_sort_by = 'p.kategori';
                elseif (in_array($sort_by, ['tanggal', 'waktu', 'kode_akun', 'jumlah', 'umur_pakai'])) $fallback_sort_by = 'p.' . $sort_by;
                
                $query .= " ORDER BY {$fallback_sort_by} " . strtoupper($sort_order);
            }
        }
    }

    $stmt = $pdo->prepare($query);
    if ($tanggal_awal_parsed && $tanggal_akhir_parsed) {
        $stmt->bindParam(':tanggal_awal', $tanggal_awal_parsed);
        $stmt->bindParam(':tanggal_akhir', $tanggal_akhir_parsed);
    }
    if ($kategori) {
        $stmt->bindParam(':kategori', $kategori);
    }
    if ($cabang) {
        $stmt->bindParam(':cabang', $cabang);
    }
    $stmt->execute();
    $pengeluaran = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Create a new Spreadsheet object
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Set document properties
    $spreadsheet->getProperties()
        ->setCreator("Fitmotor Maintenance System")
        ->setLastModifiedBy($_SESSION['nama_karyawan'] ?? 'System')
        ->setTitle("Laporan Pengeluaran " . ucfirst($jenis_data))
        ->setSubject("Detail Pengeluaran " . ucfirst($jenis_data))
        ->setDescription("Laporan detail pengeluaran " . $jenis_data . " yang diekspor dari sistem Fitmotor Maintenance")
        ->setKeywords("pengeluaran " . $jenis_data . " fitmotor maintenance")
        ->setCategory("Laporan Keuangan");

    // Set header row
    $headers = [
        'Tanggal Input', 'Waktu Input', 'Kode Transaksi', 'Nama Cabang', 'Sumber',
        'Kategori Akun', 'Nama Akun', 'Tanggal Transaksi', 'Kode Akun',
        'Umur Pakai (Bulan)', 'Keterangan Akun', 'Jumlah (Rp)'
    ];

    $sheet->fromArray($headers, NULL, 'A1');

    // Apply bold font and fill color for header row
    $sheet->getStyle('A1:L1')->getFont()->setBold(true);
    $sheet->getStyle('A1:L1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFCCCCCC');

    // Insert data rows
    $rowIndex = 2;
    foreach ($pengeluaran as $row) {
        // PERBAIKAN: Validasi data sebelum input
        $tanggal_input = !empty($row['tanggal']) ? strtotime($row['tanggal']) : time();
        
        $sheet->setCellValue("A{$rowIndex}", \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($tanggal_input));
        $sheet->setCellValue("B{$rowIndex}", $row['waktu'] ?? '');
        $sheet->setCellValue("C{$rowIndex}", $row['kode_transaksi'] ?? '');
        $sheet->setCellValue("D{$rowIndex}", $row['nama_cabang'] ?? '');
        $sheet->setCellValue("E{$rowIndex}", ucfirst($row['jenis_sumber'] ?? 'Unknown'));
        $sheet->setCellValue("F{$rowIndex}", ucfirst(str_replace('_', ' ', $row['kategori_akun'] ?? '')));
        $sheet->setCellValue("G{$rowIndex}", $row['nama_akun'] ?? '-');
        
        // Handle Tanggal Transaksi as a proper Excel date
        $tanggal_transaksi = extractTransactionDate($row['kode_transaksi'] ?? '', $date_format);
        if ($tanggal_transaksi !== null) {
            $sheet->setCellValue("H{$rowIndex}", \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($tanggal_transaksi));
        } else {
            $sheet->setCellValue("H{$rowIndex}", '-');
        }
        
        $sheet->setCellValue("I{$rowIndex}", $row['kode_akun'] ?? '');
        $sheet->setCellValue("J{$rowIndex}", $row['umur_pakai'] ?? '-');
        $sheet->setCellValue("K{$rowIndex}", $row['keterangan_akun'] ?? '-');
        
        // PERBAIKAN: Pastikan numeric value untuk jumlah
        $jumlah = is_numeric($row['jumlah']) ? (float)$row['jumlah'] : 0;
        $sheet->setCellValue("L{$rowIndex}", $jumlah);
        $rowIndex++;
    }

    // Apply formatting
    if ($rowIndex > 2) {
        $sheet->getStyle("A2:A" . ($rowIndex - 1))
              ->getNumberFormat()
              ->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);

        // Format Tanggal Transaksi with slashes
        $sheet->getStyle("H2:H" . ($rowIndex - 1))
              ->getNumberFormat()
              ->setFormatCode('yyyy/mm/dd');

        $sheet->getStyle("L2:L" . ($rowIndex - 1))
              ->getNumberFormat()
              ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
    }

    // Auto-adjust column widths
    foreach (range('A', 'L') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    // Apply border style to all cells
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

    // Add summary row if there's data
    if (count($pengeluaran) > 0) {
        $total_amount = array_sum(array_column($pengeluaran, 'jumlah'));
        
        // Calculate statistics by source
        $stats_by_source = [];
        foreach ($pengeluaran as $data) {
            $source = $data['jenis_sumber'] ?? 'unknown';
            if (!isset($stats_by_source[$source])) {
                $stats_by_source[$source] = ['count' => 0, 'total' => 0];
            }
            $stats_by_source[$source]['count']++;
            $stats_by_source[$source]['total'] += $data['jumlah'];
        }
        
        $rowIndex++;
        
        // Add summary by source if multiple sources
        if (count($stats_by_source) > 1) {
            foreach ($stats_by_source as $source => $stats) {
                $sheet->setCellValue("A{$rowIndex}", "TOTAL " . strtoupper($source));
                $sheet->mergeCells("A{$rowIndex}:K{$rowIndex}");
                $sheet->setCellValue("L{$rowIndex}", $stats['total']);
                
                $sheet->getStyle("A{$rowIndex}:L{$rowIndex}")->getFont()->setBold(true);
                $sheet->getStyle("A{$rowIndex}:L{$rowIndex}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8F9FA');
                $sheet->getStyle("A{$rowIndex}:L{$rowIndex}")->applyFromArray($styleArray);
                $sheet->getStyle("A{$rowIndex}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("L{$rowIndex}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                
                $rowIndex++;
            }
            
            $rowIndex++;
        }
        
        // Add grand total row
        $sheet->setCellValue("A{$rowIndex}", "TOTAL KESELURUHAN");
        $sheet->mergeCells("A{$rowIndex}:K{$rowIndex}");
        $sheet->setCellValue("L{$rowIndex}", $total_amount);
        
        $sheet->getStyle("A{$rowIndex}:L{$rowIndex}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowIndex}:L{$rowIndex}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF0F0F0');
        $sheet->getStyle("A{$rowIndex}:L{$rowIndex}")->applyFromArray($styleArray);
        $sheet->getStyle("A{$rowIndex}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("L{$rowIndex}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
    }

    // Add information sheet
    $infoSheet = $spreadsheet->createSheet();
    $infoSheet->setTitle('Info Laporan');
    $spreadsheet->setActiveSheetIndex(1);

    // Format filter display information
    $filter_period_display = 'Semua Data';
    if ($tanggal_awal && $tanggal_akhir) {
        if (preg_match('/^\d{4}-\d{2}$/', $tanggal_awal) && $tanggal_awal === $tanggal_akhir) {
            $filter_period_display = 'Bulan ' . date('F Y', strtotime($tanggal_awal . '-01'));
        } elseif (preg_match('/^\d{4}$/', $tanggal_awal) && $tanggal_awal === $tanggal_akhir) {
            $filter_period_display = 'Tahun ' . $tanggal_awal;
        } else {
            $filter_period_display = date('d F Y', strtotime($tanggal_awal_parsed)) . ' s/d ' . date('d F Y', strtotime($tanggal_akhir_parsed));
        }
    }

    // Add report information
    $infoData = [
        ['INFORMASI LAPORAN PENGELUARAN ' . strtoupper($jenis_data)],
        [''],
        ['Tanggal Export:', date('d F Y - H:i:s') . ' WIB'],
        ['User Export:', $_SESSION['nama_karyawan'] ?? 'Unknown User'],
        ['Role:', ucfirst($_SESSION['role'] ?? 'User')],
        [''],
        ['FILTER YANG DIGUNAKAN:'],
        ['Jenis Data:', ucfirst($jenis_data)],
        ['Periode:', $filter_period_display],
        ['Kategori:', $kategori ? ucfirst(str_replace('_', ' ', $kategori)) : 'Semua Kategori'],
        ['Cabang:', $cabang ? ucfirst($cabang) : 'Semua Cabang'],
        ['Sorting:', ucfirst($sort_by) . ' - ' . ($sort_order === 'ASC' ? 'Ascending' : 'Descending')],
        ['Format Tanggal Transaksi:', ucfirst($date_format) . ' (mengikuti filter periode, ditampilkan sebagai YYYY/MM/DD untuk tanggal lengkap)'],
        [''],
        ['KETERANGAN FILTER TANGGAL:'],
        ['- Filter tanggal input mendukung format fleksibel:'],
        ['  * YYYY-MM-DD: Filter tanggal spesifik (tanggal transaksi ditampilkan sebagai YYYY/MM/DD)'],
        ['  * YYYY-MM: Filter per bulan (tanggal transaksi ditampilkan sebagai YYYY/MM/01)'],
        ['  * YYYY: Filter per tahun (tanggal transaksi ditampilkan sebagai YYYY/01/01)'],
        ['- Tanggal transaksi disimpan sebagai format tanggal Excel untuk mendukung perhitungan dan sorting'],
        [''],
        ['STATISTIK:'],
        ['Total Transaksi:', number_format(count($pengeluaran)) . ' transaksi'],
        ['Total Pengeluaran:', 'Rp ' . number_format(array_sum(array_column($pengeluaran, 'jumlah')), 0, ',', '.')],
        [''],
        ['KETERANGAN URUTAN KOLOM:'],
        ['1. Tanggal Input - Tanggal saat data dimasukkan ke sistem (format YYYY/MM/DD)'],
        ['2. Waktu Input - Waktu saat data dimasukkan ke sistem'],
        ['3. Kode Transaksi - Kode unik transaksi (TRX untuk kasir, PST untuk pusat)'],
        ['4. Nama Cabang - Cabang tempat transaksi dilakukan'],
        ['5. Sumber - Sumber data (kasir atau pusat)'],
        ['6. Kategori Akun - Kategori akun pengeluaran (biaya/non-biaya)'],
        ['7. Nama Akun - Nama lengkap akun pengeluaran'],
        ['8. Tanggal Transaksi - Tanggal transaksi (format YYYY/MM/DD, YYYY/MM/01, atau YYYY/01/01 mengikuti filter periode)'],
        ['9. Kode Akun - Kode akun dalam master akun'],
        ['10. Umur Pakai (Bulan) - Durasi umur pakai aset (jika ada)'],
        ['11. Keterangan Akun - Keterangan tambahan transaksi'],
        ['12. Jumlah (Rp) - Nominal pengeluaran dalam rupiah'],
        [''],
        ['CATATAN PENTING:'],
        ['- Laporan ini dibuat secara otomatis oleh sistem Fitmotor Maintenance'],
        ['- Data yang ditampilkan sesuai dengan filter dan sorting yang dipilih'],
        ['- Urutan kolom diseragamkan dengan tampilan web untuk konsistensi'],
        ['- Kolom "Sumber" menunjukkan asal data (kasir/pusat)'],
        ['- Kolom "Tanggal Transaksi" disimpan sebagai format tanggal Excel untuk mendukung perhitungan'],
        ['- Filter tahun akan menampilkan tanggal transaksi sebagai YYYY/01/01 untuk konsistensi sorting'],
        ['- Filter bulan akan menampilkan tanggal transaksi sebagai YYYY/MM/01 untuk konsistensi sorting'],
        ['- File ini dapat dibuka dengan Microsoft Excel atau aplikasi spreadsheet lainnya']
    ];

    $infoSheet->fromArray($infoData, NULL, 'A1');

    // Style the info sheet
    $infoSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $infoSheet->getStyle('A7')->getFont()->setBold(true);
    $infoSheet->getStyle('A15')->getFont()->setBold(true);
    $infoSheet->getStyle('A22')->getFont()->setBold(true);
    $infoSheet->getStyle('A26')->getFont()->setBold(true);
    $infoSheet->getStyle('A39')->getFont()->setBold(true);

    // Auto-adjust column widths for info sheet
    foreach (range('A', 'B') as $columnID) {
        $infoSheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    // Set active sheet back to data sheet
    $spreadsheet->setActiveSheetIndex(0);

    // Generate filename based on data type and filters
    $filename = "laporan_pengeluaran_" . $jenis_data;
    if ($tanggal_awal && $tanggal_akhir) {
        if ($tanggal_awal === $tanggal_akhir) {
            if (preg_match('/^\d{4}-\d{2}$/', $tanggal_awal)) {
                $filename .= "_" . str_replace('-', '', $tanggal_awal);
            } elseif (preg_match('/^\d{4}$/', $tanggal_awal)) {
                $filename .= "_" . $tanggal_awal;
            } else {
                $filename .= "_" . str_replace('-', '', $tanggal_awal);
            }
        } else {
            $filename .= "_" . str_replace('-', '', $tanggal_awal_parsed) . "_" . str_replace('-', '', $tanggal_akhir_parsed);
        }
    }
    if ($cabang) {
        $filename .= "_" . preg_replace('/[^a-zA-Z0-9]/', '', $cabang);
    }
    if ($kategori) {
        $filename .= "_" . $kategori;
    }
    $filename .= "_sort_" . $sort_by . "_" . strtolower($sort_order);
    $filename .= "_" . date('Ymd_His') . ".xlsx";

    // PERBAIKAN: Clear output buffer sebelum set headers
    ob_end_clean();

    // PERBAIKAN: Set headers yang bersih dan konsisten
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Expires: 0');

    // Write to output
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

} catch (Exception $e) {
    // Error handling
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo "Error generating Excel file: " . htmlspecialchars($e->getMessage());
    error_log("Excel Export Error (Pengeluaran): " . $e->getMessage());
} catch (PDOException $e) {
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo "Database Error: " . htmlspecialchars($e->getMessage());
    error_log("Database Error in Excel Export (Pengeluaran): " . $e->getMessage());
}

exit;
?>