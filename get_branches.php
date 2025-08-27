<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Koneksi ke database menggunakan root tanpa password
$host = 'localhost';
$dbname = 'fitmotor_maintance-beta';
$username = 'fitmotor_LOGIN';  // Username root tanpa password
$password = 'Sayalupa12';      // Kosongkan password

// Koneksi menggunakan mysqli
$con = new mysqli($host, $username, $password, $dbname);

// Memeriksa koneksi
if ($con->connect_error) {
    die("Koneksi gagal: " . $con->connect_error);
}

// Query untuk mendapatkan data cabang unik beserta karyawannya
$sql = "
    SELECT DISTINCT kode_cabang, nama_cabang, kode_karyawan, nama_karyawan 
    FROM masterkeys 
    WHERE nama_cabang IS NOT NULL AND nama_karyawan IS NOT NULL
";

$result = $con->query($sql);

// Menyimpan hasil query dalam array
$data = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'kode_cabang' => $row['kode_cabang'],
            'nama_cabang' => $row['nama_cabang'],
            'kode_karyawan' => $row['kode_karyawan'],  // Ambil kode_karyawan
            'nama_karyawan' => $row['nama_karyawan']   // Ambil nama_karyawan
        ];
    }
}

// Mengembalikan hasil dalam format JSON
header('Content-Type: application/json');
echo json_encode($data);

// Menutup koneksi
$con->close();
?>
