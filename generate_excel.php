<?php
require 'vendor/autoload.php'; // Autoload PhpSpreadsheet via Composer

session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'config.php'; // Koneksi ke database

// Initialize the PDO connection
$pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Pastikan kode_transaksi dan kode_karyawan tersedia
$kode_transaksi = $_GET['kode_transaksi'] ?? null;
if (!$kode_transaksi) {
    die('Kode transaksi tidak ditemukan.');
}

$kode_karyawan = $_SESSION['kode_karyawan'] ?? null;
if (!$kode_karyawan) {
    die('Kode karyawan tidak ditemukan.');
}

// Mengambil user dan cabang dari sesi
$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';  
$cabang = $_SESSION['cabang'] ?? 'Unknown Cabang';

// Ambil data transaksi dari database
$sql = "
    SELECT 
        (SELECT SUM(jumlah_penjualan) FROM data_penjualan WHERE kode_transaksi = :kode_transaksi) AS total_penjualan,
        (SELECT SUM(jumlah_servis) FROM data_servis WHERE kode_transaksi = :kode_transaksi) AS total_servis,
        ka.total_nilai AS kas_awal,
        kcl.total_nilai AS kas_akhir,
        ka.tanggal AS kas_awal_date,
        ka.waktu AS kas_awal_time,
        kcl.tanggal AS kas_akhir_date,
        kcl.waktu AS kas_akhir_time
    FROM kasir_transactions kt
    LEFT JOIN kas_awal ka ON ka.kode_transaksi = kt.kode_transaksi
    LEFT JOIN kas_akhir kcl ON kcl.kode_transaksi = kt.kode_transaksi
    WHERE kt.kode_transaksi = :kode_transaksi AND kt.kode_karyawan = :kode_karyawan
";
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt->execute();
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

$total_penjualan = $transaction['total_penjualan'] ?? 0;
$total_servis = $transaction['total_servis'] ?? 0;
$kas_awal = $transaction['kas_awal'] ?? 0;
$kas_akhir = $transaction['kas_akhir'] ?? 0;
$total_omset = $total_penjualan + $total_servis;
$setoran_real = $kas_akhir - $kas_awal;

// Fetch pemasukan dan pengeluaran
$sql_pemasukan = "SELECT * FROM pemasukan_kasir WHERE kode_transaksi = :kode_transaksi";
$stmt_pemasukan = $pdo->prepare($sql_pemasukan);
$stmt_pemasukan->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pemasukan->execute();
$pemasukan_kasir = $stmt_pemasukan->fetchAll(PDO::FETCH_ASSOC);

// Fetch pengeluaran data (with umur_pakai and kategori)
$sql_pengeluaran = "
    SELECT pk.*, pk.umur_pakai, ma.kategori 
    FROM pengeluaran_kasir pk
    LEFT JOIN master_akun ma ON pk.kode_akun = ma.kode_akun
    WHERE pk.kode_transaksi = :kode_transaksi";
$stmt_pengeluaran = $pdo->prepare($sql_pengeluaran);
$stmt_pengeluaran->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pengeluaran->execute();
$pengeluaran_kasir = $stmt_pengeluaran->fetchAll(PDO::FETCH_ASSOC);

// Total Pemasukan & Pengeluaran
$total_pemasukan = 0;
$total_pengeluaran = 0;

foreach ($pemasukan_kasir as $pemasukan) {
    $total_pemasukan += $pemasukan['jumlah'];
}

foreach ($pengeluaran_kasir as $pengeluaran) {
    $total_pengeluaran += $pengeluaran['jumlah'];
}

$data_setoran = $total_omset - $total_pengeluaran + $total_pemasukan;
$selisih_setoran = $setoran_real - $data_setoran;

// Buat objek Spreadsheet baru
$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set header laporan
$sheet->setCellValue('A1', 'Laporan Closing Kasir');
$sheet->setCellValue('A2', 'Nama User: ' . $username);
$sheet->setCellValue('A3', 'Cabang: ' . $cabang);
$sheet->setCellValue('A4', 'TANGGAL DAN JAM TRANSAKSI');
$sheet->setCellValue('A5', 'Tanggal Kas Awal: ' . date('d M Y', strtotime($transaction['kas_awal_date'])));
$sheet->setCellValue('A6', 'Jam Kas Awal: ' . date('H:i:s', strtotime($transaction['kas_awal_time'])));
$sheet->setCellValue('A7', 'Tanggal Kas Akhir: ' . date('d M Y', strtotime($transaction['kas_akhir_date'])));
$sheet->setCellValue('A8', 'Jam Kas Akhir: ' . date('H:i:s', strtotime($transaction['kas_akhir_time'])));

// Tambahkan data Kas Awal
$sheet->setCellValue('A10', 'Data Kas Awal');
$sheet->setCellValue('A11', 'Nominal');
$sheet->setCellValue('B11', 'Keping');
$sheet->setCellValue('C11', 'Total Nilai');

// Ambil data detail kas_awal
$sql_kas_awal_detail = "
    SELECT nominal, jumlah_keping 
    FROM detail_kas_awal 
    WHERE kode_transaksi = :kode_transaksi
";
$stmt_kas_awal_detail = $pdo->prepare($sql_kas_awal_detail);
$stmt_kas_awal_detail->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_kas_awal_detail->execute();
$kas_awal_detail = $stmt_kas_awal_detail->fetchAll(PDO::FETCH_ASSOC);

$row = 12;
foreach ($kas_awal_detail as $kas) {
    $sheet->setCellValue('A' . $row, 'Rp' . number_format($kas['nominal'], 0, ',', '.'));
    $sheet->setCellValue('B' . $row, $kas['jumlah_keping']);
    $sheet->setCellValue('C' . $row, 'Rp' . number_format($kas['nominal'] * $kas['jumlah_keping'], 0, ',', '.'));
    $row++;
}

$sheet->setCellValue('A' . $row, 'Total Kas Awal');
$sheet->setCellValue('C' . $row, 'Rp' . number_format($kas_awal, 0, ',', '.'));

// Tambahkan data Kas Akhir
$row += 2;
$sheet->setCellValue('A' . $row, 'Data Kas Akhir');
$row++;
$sheet->setCellValue('A' . $row, 'Nominal');
$sheet->setCellValue('B' . $row, 'Keping');
$sheet->setCellValue('C' . $row, 'Total Nilai');

$sql_kas_akhir_detail = "
    SELECT nominal, jumlah_keping 
    FROM detail_kas_akhir 
    WHERE kode_transaksi = :kode_transaksi
";
$stmt_kas_akhir_detail = $pdo->prepare($sql_kas_akhir_detail);
$stmt_kas_akhir_detail->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_kas_akhir_detail->execute();
$kas_akhir_detail = $stmt_kas_akhir_detail->fetchAll(PDO::FETCH_ASSOC);

$row++;
foreach ($kas_akhir_detail as $kas) {
    $sheet->setCellValue('A' . $row, 'Rp' . number_format($kas['nominal'], 0, ',', '.'));
    $sheet->setCellValue('B' . $row, $kas['jumlah_keping']);
    $sheet->setCellValue('C' . $row, 'Rp' . number_format($kas['nominal'] * $kas['jumlah_keping'], 0, ',', '.'));
    $row++;
}

$sheet->setCellValue('A' . $row, 'Total Kas Akhir');
$sheet->setCellValue('C' . $row, 'Rp' . number_format($kas_akhir, 0, ',', '.'));

// Tambahkan Data Sistem Aplikasi
$row += 2;
$sheet->setCellValue('A' . $row, 'Data Sistem Aplikasi');
$row++;
$sheet->setCellValue('A' . $row, 'Data Penjualan');
$sheet->setCellValue('C' . $row, 'Rp' . number_format($total_penjualan, 0, ',', '.'));
$row++;
$sheet->setCellValue('A' . $row, 'Data Servis');
$sheet->setCellValue('C' . $row, 'Rp' . number_format($total_servis, 0, ',', '.'));
$row++;
$sheet->setCellValue('A' . $row, 'Omset');
$sheet->setCellValue('C' . $row, 'Rp' . number_format($total_omset, 0, ',', '.'));
$row++;
$sheet->setCellValue('A' . $row, 'Pengeluaran dari Kasir');
$sheet->setCellValue('C' . $row, 'Rp' . number_format($total_pengeluaran, 0, ',', '.'));
$row++;
$sheet->setCellValue('A' . $row, 'Uang Masuk ke Kasir');
$sheet->setCellValue('C' . $row, 'Rp' . number_format($total_pemasukan, 0, ',', '.'));
$row++;
$sheet->setCellValue('A' . $row, 'Data Setoran');
$sheet->setCellValue('C' . $row, 'Rp' . number_format($data_setoran, 0, ',', '.'));
$row++;
$sheet->setCellValue('A' . $row, 'Selisih Setoran (REAL-DATA)');
$sheet->setCellValue('C' . $row, 'Rp' . number_format($selisih_setoran, 0, ',', '.'));

// Pemasukan Kasir
$row += 2;
$sheet->setCellValue('A' . $row, 'View Pemasukan Kasir');
$row++;
$sheet->setCellValue('A' . $row, 'Kode Transaksi');
$sheet->setCellValue('B' . $row, 'Kode Akun');
$sheet->setCellValue('C' . $row, 'Jumlah (Rp)');
$sheet->setCellValue('D' . $row, 'Keterangan Transaksi');
$sheet->setCellValue('E' . $row, 'Tanggal');
$sheet->setCellValue('F' . $row, 'Waktu');
$row++;

foreach ($pemasukan_kasir as $pemasukan) {
    $sheet->setCellValue('A' . $row, $pemasukan['kode_transaksi']);
    $sheet->setCellValue('B' . $row, $pemasukan['kode_akun']);
    $sheet->setCellValue('C' . $row, 'Rp ' . number_format($pemasukan['jumlah'], 0, ',', '.'));
    $sheet->setCellValue('D' . $row, $pemasukan['keterangan_transaksi']);
    $sheet->setCellValue('E' . $row, $pemasukan['tanggal']);
    $sheet->setCellValue('F' . $row, $pemasukan['waktu']);
    $row++;
}
$sheet->setCellValue('A' . $row, 'Total Pemasukan');
$sheet->setCellValue('C' . $row, 'Rp ' . number_format($total_pemasukan, 0, ',', '.'));

// Pengeluaran Kasir
$row += 2;
$sheet->setCellValue('A' . $row, 'View Pengeluaran Kasir');
$row++;
$sheet->setCellValue('A' . $row, 'Kode Transaksi');
$sheet->setCellValue('B' . $row, 'Kode Akun');
$sheet->setCellValue('C' . $row, 'Kategori');
$sheet->setCellValue('D' . $row, 'Jumlah (Rp)');
$sheet->setCellValue('E' . $row, 'Keterangan Transaksi');
$sheet->setCellValue('F' . $row, 'Umur Pakai (Bulan)');
$sheet->setCellValue('G' . $row, 'Tanggal');
$sheet->setCellValue('H' . $row, 'Waktu');
$row++;

foreach ($pengeluaran_kasir as $pengeluaran) {
    $sheet->setCellValue('A' . $row, $pengeluaran['kode_transaksi']);
    $sheet->setCellValue('B' . $row, $pengeluaran['kode_akun']);
    $sheet->setCellValue('C' . $row, $pengeluaran['kategori']);
    $sheet->setCellValue('D' . $row, 'Rp ' . number_format($pengeluaran['jumlah'], 0, ',', '.'));
    $sheet->setCellValue('E' . $row, $pengeluaran['keterangan_transaksi']);
    $sheet->setCellValue('F' . $row, $pengeluaran['umur_pakai'] . ' Bulan');
    $sheet->setCellValue('G' . $row, $pengeluaran['tanggal']);
    $sheet->setCellValue('H' . $row, $pengeluaran['waktu']);
    $row++;
}
$sheet->setCellValue('A' . $row, 'Total Pengeluaran');
$sheet->setCellValue('D' . $row, 'Rp ' . number_format($total_pengeluaran, 0, ',', '.'));

// Set borders to make the table more visible
$styleArray = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
            'color' => ['argb' => 'FF000000'],
        ],
    ],
];
$sheet->getStyle('A1:H' . $row)->applyFromArray($styleArray);

// Simpan file Excel
$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$filename = 'Laporan_Closing_Kasir_' . $kode_transaksi . '.xlsx';

// Set header untuk download file Excel
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Simpan file ke output langsung untuk di-download
$writer->save('php://output');
exit;
