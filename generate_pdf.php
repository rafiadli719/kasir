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
        $w1 = 30;  // Kode Akun
        $w2 = 40;  // Jumlah
        $w3 = 115; // Keterangan (dikurangi dari 130 menjadi 115 agar total 270mm)
        $w4 = 40;  // Tanggal
        $w5 = 45;  // Waktu (disesuaikan menjadi 45mm agar teks muat)
        
        // Calculate how much text can fit on one line
        $this->SetFont('Arial', '', 10);
        $maxWidth = $w3 - 2; // 2mm padding for Keterangan
        
        // Split text into lines that fit within column width (for Keterangan)
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
        $w1 = 30;  // Kode Akun
        $w2 = 30;  // Kategori
        $w3 = 40;  // Jumlah
        $w4 = 85;  // Keterangan (dikurangi dari 110 menjadi 85 agar total 270mm)
        $w5 = 40;  // Tanggal
        $w6 = 45;  // Waktu (disesuaikan menjadi 45mm agar teks muat)
        
        // Calculate how much text can fit on one line
        $this->SetFont('Arial', '', 10);
        $maxWidth = $w4 - 2; // 2mm padding for Keterangan
        
        // Split text into lines that fit within column width (for Keterangan)
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
            $testLine = $currentLine . ($currentLine ? ' ' : '') . $word;
            if ($this->GetStringWidth($testLine) > $maxWidth && $currentLine !== '') {
                $lines[] = $currentLine;
                $currentLine = $word;
            } else {
                $currentLine = $testLine;
            }
        }
        
        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }
        
        if (empty($lines)) {
            $remainingText = $text;
            while ($remainingText !== '') {
                $i = 0;
                while ($this->GetStringWidth(substr($remainingText, 0, $i)) < $maxWidth && $i < strlen($remainingText)) {
                    $i++;
                }
                
                if ($i == 0) $i = 1;
                
                $lines[] = substr($remainingText, 0, $i);
                $remainingText = substr($remainingText, $i);
            }
        }
        
        return $lines;
    }
}

try {
    $pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $kode_transaksi = $_GET['kode_transaksi'] ?? null;
    if (!$kode_transaksi) die("Kode transaksi tidak ditemukan.");
    $kode_karyawan = $_SESSION['kode_karyawan'] ?? null;
    if (!$kode_karyawan) die("Kode karyawan tidak ditemukan.");

    $username = $_SESSION['nama_karyawan'] ?? 'Unknown User';  
    $cabang = $_SESSION['cabang'] ?? 'Unknown Cabang';

    // Fetch transaction details
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
            kt.jam_closing
        FROM kasir_transactions kt
        LEFT JOIN kas_awal ka ON ka.kode_transaksi = kt.kode_transaksi
        LEFT JOIN kas_akhir kcl ON kcl.kode_transaksi = kt.kode_transaksi
        WHERE kt.kode_transaksi = :kode_transaksi AND kt.kode_karyawan = :kode_karyawan";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':kode_transaksi' => $kode_transaksi, ':kode_karyawan' => $kode_karyawan]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        die("Transaksi tidak ditemukan.");
    }

    $total_uang_di_kasir = $transaction['kas_akhir'];
    $kas_awal = $transaction['kas_awal'];
    $setoran_real = $total_uang_di_kasir - $kas_awal;
    $omset = $transaction['data_penjualan'] + $transaction['data_servis'];
    $setoran_data = $omset + $transaction['total_pemasukan'] - $transaction['total_pengeluaran'];
    $selisih_setoran = $setoran_real - $setoran_data;

    // Kas Awal Detail
    $sql_kas_awal_detail = "SELECT nominal, jumlah_keping FROM detail_kas_awal WHERE kode_transaksi = :kode_transaksi";
    $stmt_kas_awal = $pdo->prepare($sql_kas_awal_detail);
    $stmt_kas_awal->execute([':kode_transaksi' => $kode_transaksi]);
    $kas_awal_detail = $stmt_kas_awal->fetchAll(PDO::FETCH_ASSOC);

    // Kas Akhir Detail
    $sql_kas_akhir_detail = "SELECT nominal, jumlah_keping FROM detail_kas_akhir WHERE kode_transaksi = :kode_transaksi";
    $stmt_kas_akhir = $pdo->prepare($sql_kas_akhir_detail);
    $stmt_kas_akhir->execute([':kode_transaksi' => $kode_transaksi]);
    $kas_akhir_detail = $stmt_kas_akhir->fetchAll(PDO::FETCH_ASSOC);

    // Pemasukan Kasir
    $sql_pemasukan = "SELECT kode_transaksi, kode_akun, jumlah, keterangan_transaksi, tanggal, waktu FROM pemasukan_kasir WHERE kode_transaksi = :kode_transaksi";
    $stmt_pemasukan = $pdo->prepare($sql_pemasukan);
    $stmt_pemasukan->execute([':kode_transaksi' => $kode_transaksi]);
    $pemasukan_kasir = $stmt_pemasukan->fetchAll(PDO::FETCH_ASSOC);

    // Pengeluaran Kasir
    $sql_pengeluaran = "
        SELECT pk.kode_transaksi, pk.kode_akun, pk.jumlah, pk.keterangan_transaksi, pk.tanggal, pk.waktu, ma.kategori
        FROM pengeluaran_kasir pk
        LEFT JOIN master_akun ma ON pk.kode_akun = ma.kode_akun
        WHERE pk.kode_transaksi = :kode_transaksi";
    $stmt_pengeluaran = $pdo->prepare($sql_pengeluaran);
    $stmt_pengeluaran->execute([':kode_transaksi' => $kode_transaksi]);
    $pengeluaran_kasir = $stmt_pengeluaran->fetchAll(PDO::FETCH_ASSOC);

    $pdf = new CustomPDF();
    $pdf->AddPage('L', 'A4'); // Mode Landscape
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Laporan Closing Kasir Fit Motor ' . $cabang, 0, 1, 'C');

    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(135, 8, 'Kode Transaksi: ' . $kode_transaksi, 0, 0);
    $pdf->Cell(135, 8, 'Nama User: ' . htmlspecialchars($username), 0, 1);
    $pdf->Cell(135, 8, 'Tanggal Kas Awal: ' . $transaction['kas_awal_date'], 0, 0);
    $pdf->Cell(135, 8, 'Jam Kas Awal: ' . $transaction['kas_awal_time'], 0, 1);
    $pdf->Cell(135, 8, 'Tanggal Kas Akhir: ' . $transaction['kas_akhir_date'], 0, 0);
    $pdf->Cell(135, 8, 'Jam Kas Akhir: ' . $transaction['kas_akhir_time'], 0, 1);
    $pdf->Cell(135, 8, 'Tanggal Closing: ' . date('d M Y', strtotime($transaction['tanggal_closing'])), 0, 0);
    $pdf->Cell(135, 8, 'Jam Closing: ' . $transaction['jam_closing'], 0, 1);

    // Data Sistem Aplikasi
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(270, 10, 'Data Sistem Aplikasi', 1, 1, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(135, 8, 'Omset Penjualan', 1);
    $pdf->Cell(135, 8, 'Rp' . number_format($transaction['data_penjualan'], 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Omset Servis', 1);
    $pdf->Cell(135, 8, 'Rp' . number_format($transaction['data_servis'], 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Jumlah Omset (Penjualan + Servis)', 1);
    $pdf->Cell(135, 8, 'Rp' . number_format($omset, 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Pemasukan Kas', 1);
    $pdf->Cell(135, 8, 'Rp' . number_format($transaction['total_pemasukan'], 0, ',', '.'), 1, 1, 'R');
    $total_uang_masuk = $transaction['total_pemasukan'] + $omset;
    $pdf->Cell(135, 8, 'Total Uang Masuk Kas', 1);
    $pdf->Cell(135, 8, 'Rp' . number_format($total_uang_masuk, 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Pengeluaran Kas', 1);
    $pdf->Cell(135, 8, 'Rp' . number_format($transaction['total_pengeluaran'], 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Data Setoran', 1);
    $pdf->Cell(135, 8, 'Rp' . number_format($setoran_data, 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Selisih Setoran (REAL - DATA)', 1);
    $pdf->Cell(135, 8, 'Rp' . number_format($selisih_setoran, 0, ',', '.'), 1, 1, 'R');

    // Riil Uang
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(270, 10, 'Riil Uang', 1, 1, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(135, 8, 'Kas Awal', 1);
    $pdf->Cell(135, 8, 'Rp' . number_format($kas_awal, 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Kas Akhir', 1);
    $pdf->Cell(135, 8, 'Rp' . number_format($transaction['kas_akhir'], 0, ',', '.'), 1, 1, 'R');
    $pdf->Cell(135, 8, 'Setoran Riil', 1);
    $pdf->Cell(135, 8, 'Rp' . number_format($setoran_real, 0, ',', '.'), 1, 1, 'R');

    // Pemasukan Kasir
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(270, 10, 'Pemasukan Kasir', 1, 1, 'C');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(30, 8, 'Kode Akun', 1, 0, 'C');
    $pdf->Cell(40, 8, 'Jumlah (Rp)', 1, 0, 'C');
    $pdf->Cell(115, 8, 'Keterangan', 1, 0, 'C'); // Sesuaikan lebar Keterangan
    $pdf->Cell(40, 8, 'Tanggal', 1, 0, 'C');
    $pdf->Cell(45, 8, 'Waktu', 1, 1, 'C'); // Sesuaikan lebar Waktu
    $pdf->SetFont('Arial', '', 10);

    if ($pemasukan_kasir) {
        foreach ($pemasukan_kasir as $pemasukan) {
            $pdf->cetakBarisPanjang(
                $pemasukan['kode_akun'] ?? '-',
                'Rp ' . number_format($pemasukan['jumlah'], 0, ',', '.'),
                $pemasukan['keterangan_transaksi'] ?? '-',
                $pemasukan['tanggal'] ?? '-',
                $pemasukan['waktu'] ?? '-'
            );
        }
    } else {
        $pdf->Cell(0, 8, 'Tidak ada entri', 1, 1, 'C');
    }

    // Pengeluaran Kasir
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(270, 10, 'Pengeluaran Kasir', 1, 1, 'C');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(30, 8, 'Kode Akun', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Kategori', 1, 0, 'C');
    $pdf->Cell(40, 8, 'Jumlah (Rp)', 1, 0, 'C');
    $pdf->Cell(85, 8, 'Keterangan', 1, 0, 'C'); // Sesuaikan lebar Keterangan
    $pdf->Cell(40, 8, 'Tanggal', 1, 0, 'C');
    $pdf->Cell(45, 8, 'Waktu', 1, 1, 'C'); // Sesuaikan lebar Waktu
    $pdf->SetFont('Arial', '', 10);

    if ($pengeluaran_kasir) {
        foreach ($pengeluaran_kasir as $pengeluaran) {
            $pdf->cetakBarisPengeluaran(
                $pengeluaran['kode_akun'] ?? '-',
                $pengeluaran['kategori'] ?? '-',
                'Rp ' . number_format($pengeluaran['jumlah'], 0, ',', '.'),
                $pengeluaran['keterangan_transaksi'] ?? '-',
                $pengeluaran['tanggal'] ?? '-',
                $pengeluaran['waktu'] ?? '-'
            );
        }
    } else {
        $pdf->Cell(0, 8, 'Tidak ada entri', 1, 1, 'C');
    }

    // Data Kas Awal
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(270, 10, 'Data Kas Awal', 1, 1, 'C');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(90, 8, 'Nominal', 1, 0, 'C');
    $pdf->Cell(90, 8, 'Keping', 1, 0, 'C');
    $pdf->Cell(90, 8, 'Total', 1, 1, 'C');
    $pdf->SetFont('Arial', '', 10);

    if ($kas_awal_detail) {
        foreach ($kas_awal_detail as $detail) {
            $nominal = 'Rp ' . number_format($detail['nominal'], 0, ',', '.');
            $total = 'Rp ' . number_format($detail['nominal'] * $detail['jumlah_keping'], 0, ',', '.');
            $pdf->Cell(90, 8, $nominal, 1, 0, 'R');
            $pdf->Cell(90, 8, $detail['jumlah_keping'], 1, 0, 'C');
            $pdf->Cell(90, 8, $total, 1, 1, 'R');
        }
    } else {
        $pdf->Cell(0, 8, 'Tidak ada entri', 1, 1, 'C');
    }

    // Data Kas Akhir
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(270, 10, 'Data Kas Akhir', 1, 1, 'C');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(90, 8, 'Nominal', 1, 0, 'C');
    $pdf->Cell(90, 8, 'Keping', 1, 0, 'C');
    $pdf->Cell(90, 8, 'Total', 1, 1, 'C');
    $pdf->SetFont('Arial', '', 10);

    if ($kas_akhir_detail) {
        foreach ($kas_akhir_detail as $detail) {
            $nominal = 'Rp ' . number_format($detail['nominal'], 0, ',', '.');
            $total = 'Rp ' . number_format($detail['nominal'] * $detail['jumlah_keping'], 0, ',', '.');
            $pdf->Cell(90, 8, $nominal, 1, 0, 'R');
            $pdf->Cell(90, 8, $detail['jumlah_keping'], 1, 0, 'C');
            $pdf->Cell(90, 8, $total, 1, 1, 'R');
        }
    } else {
        $pdf->Cell(0, 8, 'Tidak ada entri', 1, 1, 'C');
    }

    ob_end_clean();
    $pdf->Output('D', 'Laporan_Closing_Kasir_' . $kode_transaksi . '.pdf');
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>