<?php
ob_start();
session_start();
require 'libraries/fpdf/fpdf.php';
include 'config.php';

class CustomPDF extends FPDF {
    function cetakBarisPanjang($kolom1, $kolom2, $kolom3, $kolom4, $kolom5, $maksimalKarakter = 40) {
        // For pengeluaran layout (6 columns)
        if (func_num_args() > 5 && is_string($kolom5)) {
            $kolom6 = func_get_arg(5);
            $this->cetakBarisPengeluaran($kolom1, $kolom2, $kolom3, $kolom4, $kolom5, $kolom6);
            return;
        }
        
        // Get column widths (adjusted for wider table)
        $w1 = 35; // Kode Akun
        $w2 = 35; // Jumlah
        $w3 = 100; // Keterangan
        $w4 = 50; // Tanggal
        $w5 = 50; // Waktu
        
        // Calculate how much text can fit on one line
        $this->SetFont('Arial', '', 8);
        $maxWidth = $w3 - 2; // 2mm padding
        
        // Split text into lines that fit within column width
        $lines = $this->splitTextToFit($kolom3, $maxWidth);
        
        // Calculate the height needed for this row
        $lineHeight = 8;
        $totalHeight = $lineHeight * max(1, count($lines));
        
        // First row with all columns
        $this->Cell($w1, $lineHeight, $kolom1, 'LTR', 0, 'C');
        $this->Cell($w2, $lineHeight, $kolom2, 'LTR', 0, 'R');
        $this->Cell($w3, $lineHeight, $lines[0], 'LTR', 0, 'L');
        $this->Cell($w4, $lineHeight, $kolom4, 'LTR', 0, 'C');
        $this->Cell($w5, $lineHeight, $kolom5, 'LTR', 1, 'C');
        
        // Additional rows for description if needed
        for ($i = 1; $i < count($lines); $i++) {
            $this->Cell($w1, $lineHeight, '', 'LR', 0, 'C');
            $this->Cell($w2, $lineHeight, '', 'LR', 0, 'R');
            $this->Cell($w3, $lineHeight, $lines[$i], 'LR', 0, 'L');
            $this->Cell($w4, $lineHeight, '', 'LR', 0, 'C');
            $this->Cell($w5, $lineHeight, '', 'LR', 1, 'C');
        }
        
        // Bottom border
        $this->Cell($w1, 0, '', 'LB', 0);
        $this->Cell($w2, 0, '', 'LB', 0);
        $this->Cell($w3, 0, '', 'LB', 0);
        $this->Cell($w4, 0, '', 'LB', 0);
        $this->Cell($w5, 0, '', 'LB', 1);
    }
    
    function cetakBarisPengeluaran($kolom1, $kolom2, $kolom3, $kolom4, $kolom5, $kolom6) {
        // Get column widths (adjusted for wider table)
        $w1 = 35; // Kode Akun
        $w2 = 35; // Kategori
        $w3 = 35; // Jumlah
        $w4 = 85; // Keterangan
        $w5 = 45; // Tanggal
        $w6 = 35; // Waktu
        
        // Calculate how much text can fit on one line
        $this->SetFont('Arial', '', 8);
        $maxWidth = $w4 - 2; // 2mm padding
        
        // Split text into lines that fit within column width
        $lines = $this->splitTextToFit($kolom4, $maxWidth);
        
        // Calculate the height needed for this row
        $lineHeight = 8;
        $totalHeight = $lineHeight * max(1, count($lines));
        
        // First row with all columns
        $this->Cell($w1, $lineHeight, $kolom1, 'LTR', 0, 'C');
        $this->Cell($w2, $lineHeight, $kolom2, 'LTR', 0, 'C');
        $this->Cell($w3, $lineHeight, $kolom3, 'LTR', 0, 'R');
        $this->Cell($w4, $lineHeight, $lines[0], 'LTR', 0, 'L');
        $this->Cell($w5, $lineHeight, $kolom5, 'LTR', 0, 'C');
        $this->Cell($w6, $lineHeight, $kolom6, 'LTR', 1, 'C');
        
        // Additional rows for description if needed
        for ($i = 1; $i < count($lines); $i++) {
            $this->Cell($w1, $lineHeight, '', 'LR', 0, 'C');
            $this->Cell($w2, $lineHeight, '', 'LR', 0, 'C');
            $this->Cell($w3, $lineHeight, '', 'LR', 0, 'R');
            $this->Cell($w4, $lineHeight, $lines[$i], 'LR', 0, 'L');
            $this->Cell($w5, $lineHeight, '', 'LR', 0, 'C');
            $this->Cell($w6, $lineHeight, '', 'LR', 1, 'C');
        }
        
        // Bottom border
        $this->Cell($w1, 0, '', 'LB', 0);
        $this->Cell($w2, 0, '', 'LB', 0);
        $this->Cell($w3, 0, '', 'LB', 0);
        $this->Cell($w4, 0, '', 'LB', 0);
        $this->Cell($w5, 0, '', 'LB', 0);
        $this->Cell($w6, 0, '', 'LB', 1);
    }
    
    function splitTextToFit($text, $maxWidth) {
        $lines = [];
        $words = explode(' ', $text);
        $currentLine = '';
        
        foreach ($words as $word) {
            // Check if adding this word exceeds max width
            $testLine = $currentLine . ($currentLine ? ' ' : '') . $word;
            if ($this->GetStringWidth($testLine) > $maxWidth && $currentLine !== '') {
                $lines[] = $currentLine;
                $currentLine = $word;
            } else {
                $currentLine = $testLine;
            }
        }
        
        // Add the last line
        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }
        
        // If no lines were created (very long word), force-split the text
        if (empty($lines)) {
            $remainingText = $text;
            while ($remainingText !== '') {
                $i = 0;
                while ($this->GetStringWidth(substr($remainingText, 0, $i)) < $maxWidth && $i < strlen($remainingText)) {
                    $i++;
                }
                
                if ($i == 0) $i = 1; // Ensure at least one character per line
                
                $lines[] = substr($remainingText, 0, $i);
                $remainingText = substr($remainingText, $i);
            }
        }
        
        return $lines;
    }
}

// Ensure the user is logged in and has either the 'admin' or 'super_admin' role
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

try {
    // Koneksi ke database fitmotor_maintance-beta
    $pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $kode_transaksi = $_POST['kode_transaksi'] ?? null; // Get transaction code from form

    // Fetch transaction details (same query as in view_transaksi_admin.php)
    $sql = "
        SELECT 
            kt.*, 
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
            kt.jam_closing,
            u.nama_karyawan AS nama_karyawan,
            kt.nama_cabang AS kasir_cabang
        FROM kasir_transactions kt
        LEFT JOIN kas_awal ka ON ka.kode_transaksi = kt.kode_transaksi
        LEFT JOIN kas_akhir kcl ON kcl.kode_transaksi = kt.kode_transaksi
        LEFT JOIN users u ON u.kode_karyawan = kt.kode_karyawan
        WHERE kt.kode_transaksi = :kode_transaksi
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
    $stmt->execute();
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        die("Transaksi tidak ditemukan.");
    }

    // Additional calculations
    $cabang = $transaction['kasir_cabang'];
    $nama_karyawan = $transaction['nama_karyawan'];
    $total_uang_di_kasir = $transaction['kas_akhir'];
    $kas_awal = $transaction['kas_awal'];
    $setoran_real = $total_uang_di_kasir - $kas_awal;
    $omset = $transaction['data_penjualan'] + $transaction['data_servis'];
    $setoran_data = $omset + $transaction['total_pemasukan'] - $transaction['total_pengeluaran'];
    $selisih_setoran = $setoran_real - $setoran_data;

    // Fetch details for Pemasukan Kasir, Pengeluaran Kasir (biaya and non-biaya), Kas Awal, and Kas Akhir
    $sql_pemasukan = "SELECT * FROM pemasukan_kasir WHERE kode_transaksi = :kode_transaksi";
    $stmt_pemasukan = $pdo->prepare($sql_pemasukan);
    $stmt_pemasukan->execute([':kode_transaksi' => $kode_transaksi]);
    $pemasukan_kasir = $stmt_pemasukan->fetchAll(PDO::FETCH_ASSOC);

    $stmt_pengeluaran_biaya = $pdo->prepare("SELECT * FROM pengeluaran_kasir WHERE kode_transaksi = :kode_transaksi AND kategori = 'biaya'");
    $stmt_pengeluaran_biaya->execute([':kode_transaksi' => $kode_transaksi]);
    $pengeluaran_biaya = $stmt_pengeluaran_biaya->fetchAll(PDO::FETCH_ASSOC);

    $stmt_pengeluaran_non_biaya = $pdo->prepare("SELECT * FROM pengeluaran_kasir WHERE kode_transaksi = :kode_transaksi AND kategori = 'non_biaya'");
    $stmt_pengeluaran_non_biaya->execute([':kode_transaksi' => $kode_transaksi]);
    $pengeluaran_non_biaya = $stmt_pengeluaran_non_biaya->fetchAll(PDO::FETCH_ASSOC);

    $stmt_kas_awal_detail = $pdo->prepare("SELECT nominal, jumlah_keping, nominal * jumlah_keping AS total FROM detail_kas_awal WHERE kode_transaksi = :kode_transaksi");
    $stmt_kas_awal_detail->execute([':kode_transaksi' => $kode_transaksi]);
    $kas_awal_detail = $stmt_kas_awal_detail->fetchAll(PDO::FETCH_ASSOC);

    $stmt_kas_akhir_detail = $pdo->prepare("SELECT nominal, jumlah_keping, nominal * jumlah_keping AS total FROM detail_kas_akhir WHERE kode_transaksi = :kode_transaksi");
    $stmt_kas_akhir_detail->execute([':kode_transaksi' => $kode_transaksi]);
    $kas_akhir_detail = $stmt_kas_akhir_detail->fetchAll(PDO::FETCH_ASSOC);

    // Inisialisasi PDF
    $pdf = new CustomPDF();
    $pdf->AliasNbPages(); // Untuk nomor halaman
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->AddPage('L', 'A4'); // Mode Landscape
    $pdf->SetFont('Arial', 'B', 12);

    // Transaction Info (adjusted to wider width)
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(135, 8, 'Kode Transaksi: ' . $kode_transaksi, 0, 0);
    $pdf->Cell(135, 8, 'Nama User: ' . htmlspecialchars($nama_karyawan), 0, 1);
    $pdf->Cell(135, 8, 'Tanggal Kas Awal: ' . date('d-m-Y', strtotime($transaction['kas_awal_date'])), 0, 0);
    $pdf->Cell(135, 8, 'Jam Kas Awal: ' . $transaction['kas_awal_time'], 0, 1);
    $pdf->Cell(135, 8, 'Tanggal Kas Akhir: ' . date('d-m-Y', strtotime($transaction['kas_akhir_date'])), 0, 0);
    $pdf->Cell(135, 8, 'Jam Kas Akhir: ' . $transaction['kas_akhir_time'], 0, 1);
    $pdf->Cell(135, 8, 'Tanggal Closing: ' . date('d-m-Y', strtotime($transaction['tanggal_closing'])), 0, 0);
    $pdf->Cell(135, 8, 'Jam Closing: ' . $transaction['jam_closing'], 0, 1);

    // Data Sistem Aplikasi (wider table)
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(270, 10, 'Data Sistem Aplikasi', 1, 1, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(135, 8, 'Omset Penjualan', 1);
    $pdf->Cell(135, 8, 'Rp ' . number_format($transaction['data_penjualan'], 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Omset Servis', 1);
    $pdf->Cell(135, 8, 'Rp ' . number_format($transaction['data_servis'], 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Jumlah Omset (Penjualan + Servis)', 1);
    $pdf->Cell(135, 8, 'Rp ' . number_format($omset, 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Pemasukan Kas', 1);
    $pdf->Cell(135, 8, 'Rp ' . number_format($transaction['total_pemasukan'], 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Pengeluaran Kas', 1);
    $pdf->Cell(135, 8, 'Rp ' . number_format($transaction['total_pengeluaran'], 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Data Setoran', 1);
    $pdf->Cell(135, 8, 'Rp ' . number_format($setoran_data, 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Selisih Setoran (REAL - DATA)', 1);
    $pdf->Cell(135, 8, 'Rp ' . number_format($selisih_setoran, 0, ',', '.'), 1, 1, 'R');
    
    // Riil Uang Section (wider table)
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(270, 10, 'Riil Uang', 1, 1, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(135, 8, 'Kas Awal', 1);
    $pdf->Cell(135, 8, 'Rp ' . number_format($kas_awal, 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Kas Akhir', 1);
    $pdf->Cell(135, 8, 'Rp ' . number_format($transaction['kas_akhir'], 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Setoran Riil', 1);
    $pdf->Cell(135, 8, 'Rp ' . number_format($setoran_real, 0, ',', '.'), 1, 1, 'R');

    // Pemasukan Kasir dengan Metode Baru (wider table)
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(270, 10, 'Pemasukan Kasir', 1, 1, 'C');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(35, 8, 'Kode Akun', 1, 0, 'C');
    $pdf->Cell(35, 8, 'Jumlah (Rp)', 1, 0, 'C');
    $pdf->Cell(100, 8, 'Keterangan Transaksi', 1, 0, 'C');
    $pdf->Cell(50, 8, 'Tanggal', 1, 0, 'C');
    $pdf->Cell(50, 8, 'Waktu', 1, 1, 'C');
    $pdf->SetFont('Arial', '', 8);

    foreach ($pemasukan_kasir as $pemasukan) {
        $pdf->cetakBarisPanjang(
            $pemasukan['kode_akun'], 
            'Rp ' . number_format($pemasukan['jumlah'], 0, ',', '.'), 
            $pemasukan['keterangan_transaksi'], 
            $pemasukan['tanggal'], 
            $pemasukan['waktu']
        );
    }

    // Pengeluaran Kasir - Biaya dengan Metode Baru (wider table)
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(270, 10, 'Pengeluaran Kasir - Biaya', 1, 1, 'C');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(35, 8, 'Kode Akun', 1, 0, 'C');
    $pdf->Cell(35, 8, 'Kategori', 1, 0, 'C');
    $pdf->Cell(35, 8, 'Jumlah (Rp)', 1, 0, 'C');
    $pdf->Cell(85, 8, 'Keterangan Transaksi', 1, 0, 'C');
    $pdf->Cell(45, 8, 'Tanggal', 1, 0, 'C');
    $pdf->Cell(35, 8, 'Waktu', 1, 1, 'C');
    $pdf->SetFont('Arial', '', 8);

    $total_biaya = 0;
    foreach ($pengeluaran_biaya as $pengeluaran) {
        $pdf->cetakBarisPanjang(
            $pengeluaran['kode_akun'], 
            $pengeluaran['kategori'], 
            'Rp ' . number_format($pengeluaran['jumlah'], 0, ',', '.'), 
            $pengeluaran['keterangan_transaksi'], 
            $pengeluaran['tanggal'], 
            $pengeluaran['waktu']
        );
        $total_biaya += $pengeluaran['jumlah'];
    }

    // Pengeluaran Kasir - Non Biaya dengan Metode Baru (wider table)
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(270, 10, 'Pengeluaran Kasir - Non Biaya', 1, 1, 'C');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(35, 8, 'Kode Akun', 1, 0, 'C');
    $pdf->Cell(35, 8, 'Kategori', 1, 0, 'C');
    $pdf->Cell(35, 8, 'Jumlah (Rp)', 1, 0, 'C');
    $pdf->Cell(85, 8, 'Keterangan Transaksi', 1, 0, 'C');
    $pdf->Cell(45, 8, 'Tanggal', 1, 0, 'C');
    $pdf->Cell(35, 8, 'Waktu', 1, 1, 'C');
    $pdf->SetFont('Arial', '', 8);

    $total_non_biaya = 0;
    foreach ($pengeluaran_non_biaya as $pengeluaran) {
        $pdf->cetakBarisPanjang(
            $pengeluaran['kode_akun'], 
            $pengeluaran['kategori'], 
            'Rp ' . number_format($pengeluaran['jumlah'], 0, ',', '.'), 
            $pengeluaran['keterangan_transaksi'], 
            $pengeluaran['tanggal'], 
            $pengeluaran['waktu']
        );
        $total_non_biaya += $pengeluaran['jumlah'];
    }

    // Total Pengeluaran (wider table)
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(200, 8, 'Total Pengeluaran Biaya', 1, 0, 'R');
    $pdf->Cell(70, 8, 'Rp ' . number_format($total_biaya, 0, ',', '.'), 1, 1, 'C');
    $pdf->Cell(200, 8, 'Total Pengeluaran Non Biaya', 1, 0, 'R');
    $pdf->Cell(70, 8, 'Rp ' . number_format($total_non_biaya, 0, ',', '.'), 1, 1, 'C');

    // Kas Awal with formatted header (wider table)
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(270, 10, 'Data Kas Awal', 1, 1, 'C');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(85, 8, 'Nominal', 1, 0, 'C');
    $pdf->Cell(85, 8, 'Keping', 1, 0, 'C');
    $pdf->Cell(100, 8, 'Total Nilai', 1, 1, 'C');
    $pdf->SetFont('Arial', '', 10);

    foreach ($kas_awal_detail as $kas_awal) {
        $pdf->Cell(85, 8, 'Rp ' . number_format($kas_awal['nominal'], 0, ',', '.'), 1, 0, 'R');
        $pdf->Cell(85, 8, $kas_awal['jumlah_keping'], 1, 0, 'C');
        $pdf->Cell(100, 8, 'Rp ' . number_format($kas_awal['total'], 0, ',', '.'), 1, 1, 'R');
    }

    // Kas Akhir with formatted header (wider table)
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(270, 10, 'Data Kas Akhir', 1, 1, 'C');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(85, 8, 'Nominal', 1, 0, 'C');
    $pdf->Cell(85, 8, 'Keping', 1, 0, 'C');
    $pdf->Cell(100, 8, 'Total Nilai', 1, 1, 'C');
    $pdf->SetFont('Arial', '', 10);

    foreach ($kas_akhir_detail as $kas_akhir) {
        $pdf->Cell(85, 8, 'Rp ' . number_format($kas_akhir['nominal'], 0, ',', '.'), 1, 0, 'R');
        $pdf->Cell(85, 8, $kas_akhir['jumlah_keping'], 1, 0, 'C');
        $pdf->Cell(100, 8, 'Rp ' . number_format($kas_akhir['total'], 0, ',', '.'), 1, 1, 'R');
    }
    
    ob_end_clean();
    $pdf->Output('D', 'Laporan_Closing_Kasir_' . $kode_transaksi . '.pdf');
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>