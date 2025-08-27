<?php
session_start();
include 'config.php'; // Koneksi ke database

// Pastikan user sudah login dan memiliki peran yang sesuai
if (!isset($_SESSION['kode_karyawan'])) {
    die("Kode karyawan tidak ditemukan di sesi. Silakan login kembali.");
}

$kode_karyawan = $_SESSION['kode_karyawan'];

// Cek apakah ID dari `kas_awal` tersedia di URL
if (isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // Koneksi menggunakan PDO
    $pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Siapkan query DELETE
    $query = "DELETE FROM kas_awal WHERE id = :id AND kode_karyawan = :kode_karyawan";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);

    // Eksekusi query
    if ($stmt->execute()) {
        echo "Kas Awal berhasil dihapus!";
    } else {
        echo "Terjadi kesalahan saat menghapus data.";
    }
}

// Redirect ke halaman dashboard kasir
header('Location: kasir_dashboard.php');
exit();
?>
