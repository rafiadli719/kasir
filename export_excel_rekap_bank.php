<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'vendor/autoload.php'; // Adjust path to PhpSpreadsheet if needed
require 'config.php'; // Database configuration file

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

session_start();
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    die("Akses ditolak. Hanya untuk admin dan super_admin.");
}

// Get parameters
$type = $_GET['type'] ?? 'rekening_summary';
$tanggal_awal = $_GET['tanggal_awal'] ?? null;
$tanggal_akhir = $_GET['tanggal_akhir'] ?? null;
$cabang = $_GET['cabang'] ?? 'all';
$rekening_filter = $_GET['rekening_filter'] ?? 'all';
$rekening_detail = $_GET['rekening_detail'] ?? null;

// NEW: Parameters for transaction detail
$tanggal_detail = $_GET['tanggal_detail'] ?? null;
$cabang_detail = $_GET['cabang_detail'] ?? null;
$rekening_detail_trans = $_GET['rekening_detail_trans'] ?? null;

// Create a new Spreadsheet object
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set document properties
$spreadsheet->getProperties()
    ->setCreator("Fitmotor Maintenance System")
    ->setLastModifiedBy($_SESSION['nama_karyawan'] ?? 'System')
    ->setTitle("Laporan Rekap Setoran Bank")
    ->setSubject("Rekap Setoran Bank per Rekening")
    ->setDescription("Laporan rekap setoran bank yang diekspor dari sistem Fitmotor Maintenance")
    ->setKeywords("setoran bank rekening fitmotor maintenance")
    ->setCategory("Laporan Keuangan");

if ($type === 'rekening_summary') {
    // Export rekening summary dengan ekstraksi nomor rekening
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
            MIN(sb.tanggal_setoran) as tanggal_awal,
            MAX(sb.tanggal_setoran) as tanggal_akhir,
            COUNT(DISTINCT sk.nama_cabang) as jumlah_cabang
        FROM setoran_ke_bank sb
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        WHERE 1=1
    ";

    $params = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_rekening_summary .= " AND sb.tanggal_setoran BETWEEN ? AND ?";
        $params[] = $tanggal_awal;
        $params[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_rekening_summary .= " AND sk.nama_cabang = ?";
        $params[] = $cabang;
    }
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
        // Fallback tanpa REGEXP_SUBSTR dengan penggabungan manual
        $sql_rekening_summary_fallback = "
            SELECT 
                sb.rekening_tujuan,
                GROUP_CONCAT(DISTINCT sk.nama_cabang ORDER BY sk.nama_cabang) as nama_cabang_combined,
                COUNT(DISTINCT sb.id) as total_setoran_bank,
                COUNT(DISTINCT sbd.setoran_keuangan_id) as total_paket_setoran,
                SUM(COALESCE(sb.total_setoran, 0)) as total_nominal,
                MIN(sb.tanggal_setoran) as tanggal_awal,
                MAX(sb.tanggal_setoran) as tanggal_akhir,
                COUNT(DISTINCT sk.nama_cabang) as jumlah_cabang
            FROM setoran_ke_bank sb
            JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
            JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
            WHERE 1=1
        ";
        
        $params_fallback = [];
        if ($tanggal_awal && $tanggal_akhir) {
            $sql_rekening_summary_fallback .= " AND sb.tanggal_setoran BETWEEN ? AND ?";
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
            preg_match('/(\d{10,})/', $row['rekening_tujuan'], $matches);
            $rekening_number = isset($matches[1]) ? $matches[1] : $row['rekening_tujuan'];
            
            // Skip jika filter rekening tidak cocok
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

    // Set headers for summary
    $headers = [
        'Rekening Tujuan', 'Cabang Terlibat', 'Jumlah Cabang', 'Periode Awal', 'Periode Akhir',
        'Jumlah Transaksi', 'Jumlah Paket', 'Total Nominal (Rp)'
    ];

    $sheet->fromArray($headers, NULL, 'A1');

    // Apply header styling
    $sheet->getStyle('A1:H1')->getFont()->setBold(true);
    $sheet->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
    $sheet->getStyle('A1:H1')->getFont()->getColor()->setARGB('FFFFFFFF');

    // Insert data
    $rowIndex = 2;
    $total_transaksi = 0;
    $total_paket = 0;
    $total_nominal = 0;

    foreach ($rekening_summary as $row) {
        $sheet->setCellValue("A{$rowIndex}", $row['rekening_number']);
        $sheet->setCellValue("B{$rowIndex}", $row['nama_cabang_combined']);
        $sheet->setCellValue("C{$rowIndex}", $row['jumlah_cabang'] . ' cabang');
        $sheet->setCellValue("D{$rowIndex}", date('d/m/Y', strtotime($row['tanggal_awal'])));
        $sheet->setCellValue("E{$rowIndex}", date('d/m/Y', strtotime($row['tanggal_akhir'])));
        $sheet->setCellValue("F{$rowIndex}", $row['total_setoran_bank']);
        $sheet->setCellValue("G{$rowIndex}", $row['total_paket_setoran']);
        $sheet->setCellValue("H{$rowIndex}", $row['total_nominal']);
        
        // Accumulate totals
        $total_transaksi += $row['total_setoran_bank'];
        $total_paket += $row['total_paket_setoran'];
        $total_nominal += $row['total_nominal'];
        
        $rowIndex++;
    }

    // Add total row
    if (count($rekening_summary) > 0) {
        $rowIndex++; // Empty row
        $sheet->setCellValue("A{$rowIndex}", "TOTAL KESELURUHAN");
        $sheet->mergeCells("A{$rowIndex}:E{$rowIndex}");
        $sheet->setCellValue("F{$rowIndex}", $total_transaksi);
        $sheet->setCellValue("G{$rowIndex}", $total_paket);
        $sheet->setCellValue("H{$rowIndex}", $total_nominal);
        
        // Style total row
        $sheet->getStyle("A{$rowIndex}:H{$rowIndex}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowIndex}:H{$rowIndex}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF0F0F0');
        $sheet->getStyle("A{$rowIndex}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    }

    // Apply currency format to nominal columns
    if ($rowIndex > 2) {
        $sheet->getStyle("H2:H" . ($rowIndex - 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
    }

} elseif ($type === 'rekening_detail' && $rekening_detail) {
    // Export detail for specific rekening dengan ekstraksi nomor rekening
    $sql_detail = "
        SELECT 
            sb.tanggal_setoran,
            sk.nama_cabang,
            SUM(CASE WHEN sk.status = 'closing' THEN COALESCE(sk.jumlah_diterima, 0) ELSE 0 END) as nominal_closing,
            SUM(COALESCE(sk.jumlah_diterima, 0)) as nominal_setor,
            COUNT(DISTINCT sk.kode_setoran) as jumlah_kode_setoran
        FROM setoran_ke_bank sb
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        WHERE (CASE 
            WHEN sb.rekening_tujuan REGEXP '[0-9]{10,}' THEN
                REGEXP_SUBSTR(sb.rekening_tujuan, '[0-9]{10,}')
            ELSE sb.rekening_tujuan
        END) = ?
    ";

    $params_detail = [$rekening_detail];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_detail .= " AND sb.tanggal_setoran BETWEEN ? AND ?";
        $params_detail[] = $tanggal_awal;
        $params_detail[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_detail .= " AND sk.nama_cabang = ?";
        $params_detail[] = $cabang;
    }

    $sql_detail .= " GROUP BY sb.tanggal_setoran, sk.nama_cabang ORDER BY sb.tanggal_setoran DESC, sk.nama_cabang";

    // Fallback untuk MySQL versi lama
    try {
        $stmt_detail = $pdo->prepare($sql_detail);
        $stmt_detail->execute($params_detail);
        $rekening_detail_data = $stmt_detail->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback tanpa REGEXP_SUBSTR
        $sql_detail_fallback = "
            SELECT 
                sb.tanggal_setoran,
                sk.nama_cabang,
                sb.rekening_tujuan,
                SUM(CASE WHEN sk.status = 'closing' THEN COALESCE(sk.jumlah_diterima, 0) ELSE 0 END) as nominal_closing,
                SUM(COALESCE(sk.jumlah_diterima, 0)) as nominal_setor,
                COUNT(DISTINCT sk.kode_setoran) as jumlah_kode_setoran
            FROM setoran_ke_bank sb
            JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
            JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
            WHERE 1=1
        ";
        
        $params_detail_fallback = [];
        if ($tanggal_awal && $tanggal_akhir) {
            $sql_detail_fallback .= " AND sb.tanggal_setoran BETWEEN ? AND ?";
            $params_detail_fallback[] = $tanggal_awal;
            $params_detail_fallback[] = $tanggal_akhir;
        }
        if ($cabang !== 'all') {
            $sql_detail_fallback .= " AND sk.nama_cabang = ?";
            $params_detail_fallback[] = $cabang;
        }
        
        $sql_detail_fallback .= " GROUP BY sb.tanggal_setoran, sk.nama_cabang, sb.rekening_tujuan ORDER BY sb.tanggal_setoran DESC, sk.nama_cabang";
        
        $stmt_detail = $pdo->prepare($sql_detail_fallback);
        $stmt_detail->execute($params_detail_fallback);
        $rekening_detail_raw = $stmt_detail->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter berdasarkan nomor rekening yang diekstrak
        $rekening_detail_data = [];
        foreach ($rekening_detail_raw as $row) {
            preg_match('/(\d{10,})/', $row['rekening_tujuan'], $matches);
            $rekening_number = isset($matches[1]) ? $matches[1] : $row['rekening_tujuan'];
            
            if ($rekening_number === $rekening_detail) {
                $rekening_detail_data[] = [
                    'tanggal_setoran' => $row['tanggal_setoran'],
                    'nama_cabang' => $row['nama_cabang'],
                    'nominal_closing' => $row['nominal_closing'],
                    'nominal_setor' => $row['nominal_setor'],
                    'jumlah_kode_setoran' => $row['jumlah_kode_setoran']
                ];
            }
        }
    }

    // Set headers for detail
    $headers = [
        'Tanggal', 'Cabang', 'Jml Kode Setoran', 'Nominal Closing (Rp)', 'Nominal Setor (Rp)'
    ];

    // Add header with rekening info
    $sheet->setCellValue('A1', 'DETAIL SETORAN REKENING: ' . $rekening_detail . ' (Semua Cabang)');
    $sheet->mergeCells('A1:E1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    // Add filter info
    $filter_info = 'Periode: ';
    if ($tanggal_awal && $tanggal_akhir) {
        $filter_info .= date('d/m/Y', strtotime($tanggal_awal)) . ' s/d ' . date('d/m/Y', strtotime($tanggal_akhir));
    } else {
        $filter_info .= 'Semua Data';
    }
    $filter_info .= ' | Cabang: ' . ($cabang !== 'all' ? ucfirst($cabang) : 'Semua');
    $filter_info .= ' | Rekening: ' . ($rekening_filter !== 'all' ? $rekening_filter : 'Semua');
    $filter_info .= ' | Tampilan: Gabungan Semua Cabang';

    $sheet->setCellValue('A2', $filter_info);
    $sheet->mergeCells('A2:E2');
    $sheet->getStyle('A2')->getFont()->setItalic(true);

    $sheet->fromArray($headers, NULL, 'A4');

    // Apply header styling
    $sheet->getStyle('A4:E4')->getFont()->setBold(true);
    $sheet->getStyle('A4:E4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
    $sheet->getStyle('A4:E4')->getFont()->getColor()->setARGB('FFFFFFFF');

    // Insert data
    $rowIndex = 5;
    $total_closing = 0;
    $total_setor = 0;
    $total_kode_setoran = 0;

    foreach ($rekening_detail_data as $row) {
        $sheet->setCellValue("A{$rowIndex}", date('d-M-Y', strtotime($row['tanggal_setoran'])));
        $sheet->setCellValue("B{$rowIndex}", $row['nama_cabang']);
        $sheet->setCellValue("C{$rowIndex}", $row['jumlah_kode_setoran'] . ' paket');
        $sheet->setCellValue("D{$rowIndex}", $row['nominal_closing']);
        $sheet->setCellValue("E{$rowIndex}", $row['nominal_setor']);
        
        $total_closing += $row['nominal_closing'];
        $total_setor += $row['nominal_setor'];
        $total_kode_setoran += $row['jumlah_kode_setoran'];
        
        $rowIndex++;
    }

    // Add total row
    if (count($rekening_detail_data) > 0) {
        $rowIndex++; // Empty row
        $sheet->setCellValue("A{$rowIndex}", "TOTAL KESELURUHAN");
        $sheet->mergeCells("A{$rowIndex}:B{$rowIndex}");
        $sheet->setCellValue("C{$rowIndex}", $total_kode_setoran . ' paket');
        $sheet->setCellValue("D{$rowIndex}", $total_closing);
        $sheet->setCellValue("E{$rowIndex}", $total_setor);
        
        // Style total row
        $sheet->getStyle("A{$rowIndex}:E{$rowIndex}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowIndex}:E{$rowIndex}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF0F0F0');
        $sheet->getStyle("A{$rowIndex}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    }

    // Apply currency format to nominal columns
    if ($rowIndex > 5) {
        $sheet->getStyle("D5:E" . ($rowIndex - 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
    }

} elseif ($type === 'transaction_detail' && $tanggal_detail && $cabang_detail && $rekening_detail_trans) {
    // NEW: Export transaction detail for specific date, branch, and account
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
            mk.nama_karyawan
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
        AND sb.tanggal_setoran = ?
        AND sk.nama_cabang = ?
        ORDER BY sk.kode_setoran, kt.kode_transaksi
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
                sb.rekening_tujuan
            FROM setoran_ke_bank sb
            JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
            JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
            LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
            LEFT JOIN masterkeys mk ON kt.kode_karyawan = mk.kode_karyawan
            WHERE sb.tanggal_setoran = ?
            AND sk.nama_cabang = ?
            ORDER BY sk.kode_setoran, kt.kode_transaksi
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

    // Group transactions by kode_setoran
    $grouped_transactions = [];
    foreach ($transaction_detail_data as $trans) {
        $grouped_transactions[$trans['kode_setoran']][] = $trans;
    }

    // Add main header
    $sheet->setCellValue('A1', 'DETAIL TRANSAKSI PER TANGGAL');
    $sheet->mergeCells('A1:I1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    // Add detail info
    $detail_info = 'Tanggal: ' . date('d-M-Y', strtotime($tanggal_detail)) . 
                   ' | Cabang: ' . $cabang_detail . 
                   ' | Rekening: ' . $rekening_detail_trans;
    $sheet->setCellValue('A2', $detail_info);
    $sheet->mergeCells('A2:I2');
    $sheet->getStyle('A2')->getFont()->setItalic(true)->setBold(true);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $rowIndex = 4;

    foreach ($grouped_transactions as $kode_setoran => $transactions) {
        // Kode Setoran Header
        $sheet->setCellValue("A{$rowIndex}", "KODE SETORAN: " . $kode_setoran);
        $sheet->mergeCells("A{$rowIndex}:I{$rowIndex}");
        $sheet->getStyle("A{$rowIndex}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowIndex}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
        $sheet->getStyle("A{$rowIndex}")->getFont()->getColor()->setARGB('FFFFFFFF');
        $rowIndex++;

        // Setoran details
        $first_trans = $transactions[0];
        $setoran_details = [
            ['Tanggal Setoran:', date('d-M-Y', strtotime($first_trans['tanggal_setoran'])), 'Tanggal Closing:', $first_trans['tanggal_closing'] ? date('d-M-Y', strtotime($first_trans['tanggal_closing'])) : 'N/A'],
            ['Pengantar:', $first_trans['nama_pengantar'], 'Status:', $first_trans['status']],
            ['Jumlah Setoran:', 'Rp ' . number_format($first_trans['jumlah_setoran'], 0, ',', '.'), 'Jumlah Diterima:', 'Rp ' . number_format($first_trans['jumlah_diterima'], 0, ',', '.')],
            ['Selisih:', 'Rp ' . number_format($first_trans['selisih_setoran'], 0, ',', '.'), '', '']
        ];

        foreach ($setoran_details as $detail) {
            $sheet->setCellValue("A{$rowIndex}", $detail[0]);
            $sheet->setCellValue("B{$rowIndex}", $detail[1]);
            $sheet->setCellValue("D{$rowIndex}", $detail[2]);
            $sheet->setCellValue("E{$rowIndex}", $detail[3]);
            $sheet->getStyle("A{$rowIndex}")->getFont()->setBold(true);
            $sheet->getStyle("D{$rowIndex}")->getFont()->setBold(true);
            $rowIndex++;
        }

        $rowIndex++; // Empty row

        // Transaction headers
        $trans_headers = [
            'Kode Transaksi', 'Nama Karyawan', 'Setoran Real (Rp)', 'Omset (Rp)', 'Selisih Kasir (Rp)'
        ];
        $sheet->fromArray($trans_headers, NULL, "A{$rowIndex}");
        $sheet->getStyle("A{$rowIndex}:E{$rowIndex}")->getFont()->setBold(true);
        $sheet->getStyle("A{$rowIndex}:E{$rowIndex}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE7E6E6');
        $rowIndex++;

        // Transaction data
        foreach ($transactions as $trans) {
            $sheet->setCellValue("A{$rowIndex}", $trans['kode_transaksi'] ?? 'N/A');
            $sheet->setCellValue("B{$rowIndex}", $trans['nama_karyawan'] ?? 'N/A');
            $sheet->setCellValue("C{$rowIndex}", $trans['setoran_real'] ?? 0);
            $sheet->setCellValue("D{$rowIndex}", $trans['omset'] ?? 0);
            $sheet->setCellValue("E{$rowIndex}", $trans['selisih_kasir'] ?? 0);
            $rowIndex++;
        }

        $rowIndex += 2; // Space between groups
    }

    // Apply currency format to all monetary columns
    if (count($grouped_transactions) > 0) {
        $sheet->getStyle("C4:E" . ($rowIndex - 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
    }

    // Apply borders to the entire data range
    $lastRow = $rowIndex - 1;
    $styleArray = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'FF000000'],
            ],
        ],
    ];
    $sheet->getStyle("A1:I{$lastRow}")->applyFromArray($styleArray);
}

// Auto-adjust column widths based on the type
if ($type === 'transaction_detail') {
    foreach (range('A', 'I') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }
} else {
    foreach (range('A', 'H') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }
}

// Apply border style to all cells with data (for non-transaction detail types)
if ($type !== 'transaction_detail') {
    $styleArray = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'FF000000'],
            ],
        ],
    ];

    if ($type === 'rekening_summary') {
        $lastRow = $sheet->getHighestRow();
        $sheet->getStyle("A1:H{$lastRow}")->applyFromArray($styleArray);
    } else {
        $lastRow = $sheet->getHighestRow();
        $sheet->getStyle("A1:E{$lastRow}")->applyFromArray($styleArray);
    }
}

// Add information sheet
$infoSheet = $spreadsheet->createSheet();
$infoSheet->setTitle('Info Laporan');
$spreadsheet->setActiveSheetIndex(1);

// Add report information based on type
$infoData = [
    ['INFORMASI LAPORAN REKAP SETORAN BANK'],
    [''],
    ['Tanggal Export:', date('d F Y - H:i:s') . ' WIB'],
    ['User Export:', $_SESSION['nama_karyawan'] ?? 'Unknown User'],
    ['Role:', ucfirst($_SESSION['role'] ?? 'User')],
    [''],
    ['FILTER YANG DIGUNAKAN:'],
];

if ($type === 'transaction_detail') {
    $infoData = array_merge($infoData, [
        ['Jenis Laporan:', 'Detail Transaksi per Tanggal dan Cabang'],
        ['Tanggal Detail:', date('d F Y', strtotime($tanggal_detail))],
        ['Cabang Detail:', $cabang_detail],
        ['Rekening Detail:', $rekening_detail_trans],
    ]);
} else {
    $infoData = array_merge($infoData, [
        ['Jenis Laporan:', $type === 'rekening_summary' ? 'Rekap per Rekening (Gabungan Cabang)' : 'Detail Rekening (Semua Cabang)'],
        ['Periode:', $tanggal_awal && $tanggal_akhir ? 
            date('d F Y', strtotime($tanggal_awal)) . ' s/d ' . date('d F Y', strtotime($tanggal_akhir)) : 'Semua Data'],
        ['Cabang:', $cabang !== 'all' ? ucfirst($cabang) : 'Semua Cabang'],
        ['Rekening Filter:', $rekening_filter !== 'all' ? $rekening_filter : 'Semua Rekening'],
    ]);

    if ($type === 'rekening_detail' && $rekening_detail) {
        $infoData[] = ['Rekening Detail:', $rekening_detail . ' (Semua Cabang)'];
    }
}

$infoData = array_merge($infoData, [
    [''],
    ['KETERANGAN STRUKTUR DATA:'],
    ['- Jika rekening yang sama digunakan oleh beberapa cabang, data digabungkan'],
    ['- Kolom "Cabang Terlibat" menampilkan semua cabang yang menggunakan rekening tersebut'],
    ['- Detail menampilkan breakdown per tanggal dan cabang untuk rekening terpilih'],
    ['- Detail transaksi menampilkan semua kode setoran dan transaksi kasir pada tanggal tertentu'],
    ['- Filter cabang akan membatasi data hanya pada cabang yang dipilih'],
    ['- Filter rekening akan menampilkan data untuk rekening tertentu saja'],
    ['- Ekstraksi nomor rekening otomatis menggabungkan rekening yang sama'],
    [''],
    ['KETERANGAN KOLOM:'],
]);

if ($type === 'rekening_summary') {
    $infoData = array_merge($infoData, [
        ['1. Rekening Tujuan - Nomor rekening bank tujuan setoran (diekstrak otomatis)'],
        ['2. Cabang Terlibat - Daftar cabang yang menggunakan rekening ini'],
        ['3. Jumlah Cabang - Total cabang yang menggunakan rekening ini'],
        ['4. Periode Awal - Tanggal setoran pertama untuk rekening ini'],
        ['5. Periode Akhir - Tanggal setoran terakhir untuk rekening ini'],
        ['6. Jumlah Transaksi - Total transaksi setoran bank'],
        ['7. Jumlah Paket - Total paket setoran keuangan'],
        ['8. Total Nominal (Rp) - Total nominal yang disetor ke rekening'],
    ]);
} elseif ($type === 'rekening_detail') {
    $infoData = array_merge($infoData, [
        ['1. Tanggal - Tanggal setoran dilakukan'],
        ['2. Cabang - Nama cabang yang melakukan setoran'],
        ['3. Jml Kode Setoran - Jumlah paket setoran pada tanggal tersebut'],
        ['4. Nominal Closing (Rp) - Nominal dari setoran dengan status closing'],
        ['5. Nominal Setor (Rp) - Total nominal yang disetor'],
    ]);
} elseif ($type === 'transaction_detail') {
    $infoData = array_merge($infoData, [
        ['STRUKTUR DETAIL TRANSAKSI:'],
        ['- Data dikelompokkan berdasarkan Kode Setoran'],
        ['- Setiap grup menampilkan informasi setoran dan daftar transaksi kasir'],
        ['- Informasi setoran: tanggal, pengantar, status, nominal'],
        ['- Detail transaksi: kode transaksi, karyawan, setoran real, omset, selisih'],
        [''],
        ['KOLOM DETAIL TRANSAKSI:'],
        ['1. Kode Transaksi - Kode unik transaksi kasir'],
        ['2. Nama Karyawan - Nama karyawan yang melakukan transaksi'],
        ['3. Setoran Real (Rp) - Nominal yang disetor kasir'],
        ['4. Omset (Rp) - Total omset dari transaksi'],
        ['5. Selisih Kasir (Rp) - Selisih antara omset dan setoran'],
    ]);
}

$infoData = array_merge($infoData, [
    [''],
    ['CATATAN PENTING:'],
    ['- Laporan ini dibuat secara otomatis oleh sistem Fitmotor Maintenance'],
    ['- Data yang ditampilkan sesuai dengan filter yang dipilih'],
    ['- Rekening yang sama dari berbagai cabang digabungkan dalam satu baris (kecuali detail transaksi)'],
    ['- Filter rekening akan menampilkan gabungan semua cabang untuk rekening tersebut'],
    ['- Filter cabang memungkinkan fokus pada cabang tertentu'],
    ['- Detail transaksi menampilkan breakdown lengkap per kode setoran dan transaksi kasir'],
    ['- Ekstraksi nomor rekening menggunakan regex untuk mengatasi format berbeda'],
    ['- Kompatibel dengan MySQL 5.7+ dan 8.0+ (dengan fallback otomatis)'],
    ['- Nominal dalam format mata uang Indonesia (Rupiah)'],
    ['- File ini dapat dibuka dengan Microsoft Excel atau aplikasi spreadsheet lainnya']
]);

$infoSheet->fromArray($infoData, NULL, 'A1');

// Style the info sheet
$infoSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$infoSheet->getStyle('A7')->getFont()->setBold(true);
$info_row = 13;
if ($type === 'transaction_detail') {
    $info_row = 17;
    $infoSheet->getStyle('A17')->getFont()->setBold(true);
    $infoSheet->getStyle('A24')->getFont()->setBold(true);
} else {
    $infoSheet->getStyle('A13')->getFont()->setBold(true);
    if ($type === 'rekening_detail') {
        $infoSheet->getStyle('A19')->getFont()->setBold(true);
    } else {
        $infoSheet->getStyle('A22')->getFont()->setBold(true);
    }
}

$row_catatan = count($infoData) - 11;
$infoSheet->getStyle("A{$row_catatan}")->getFont()->setBold(true);

// Auto-adjust column widths for info sheet
foreach (range('A', 'B') as $columnID) {
    $infoSheet->getColumnDimension($columnID)->setAutoSize(true);
}

// Set active sheet back to data sheet
$spreadsheet->setActiveSheetIndex(0);

// Generate filename
$filename = "rekap_setoran_bank_";
if ($type === 'transaction_detail' && $tanggal_detail && $cabang_detail && $rekening_detail_trans) {
    $filename .= "transaksi_detail_" . date('Ymd', strtotime($tanggal_detail)) . "_" . preg_replace('/[^a-zA-Z0-9]/', '_', $cabang_detail) . "_" . preg_replace('/[^a-zA-Z0-9]/', '_', $rekening_detail_trans);
} elseif ($type === 'rekening_detail' && $rekening_detail) {
    $filename .= "detail_" . preg_replace('/[^a-zA-Z0-9]/', '_', $rekening_detail) . "_semua_cabang";
} else {
    $filename .= "summary_gabungan_cabang";
}

if ($tanggal_awal && $tanggal_akhir) {
    $filename .= "_" . date('Ymd', strtotime($tanggal_awal)) . "_" . date('Ymd', strtotime($tanggal_akhir));
}
if ($cabang !== 'all') {
    $filename .= "_" . preg_replace('/[^a-zA-Z0-9]/', '', $cabang);
}
if ($rekening_filter !== 'all') {
    $filename .= "_" . preg_replace('/[^a-zA-Z0-9]/', '_', $rekening_filter);
}
$filename .= "_" . date('Ymd_His') . ".xlsx";

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Cache-Control: max-age=1');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: cache, must-revalidate');
header('Pragma: public');

// Write to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>