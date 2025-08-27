<?php
session_start();
include 'config.php'; // Include your database connection

// Check if the user is logged in
if (isset($_SESSION['kode_karyawan'])) {
    $kode_karyawan = $_SESSION['kode_karyawan'];
} else {
    die("Kode Karyawan tidak ditemukan di session. Silakan login kembali.");
}

// Get ID from URL
if (isset($_GET['id'])) {
    $id = $_GET['id'];
} else {
    die("ID pemasukan tidak ditemukan.");
}

$sql = "SELECT * FROM pemasukan_kasir WHERE id = :id AND kode_karyawan = :kode_karyawan";
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':id', $id, PDO::PARAM_INT);
$stmt->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt->execute();
$pemasukan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pemasukan) {
    die("Data pemasukan tidak ditemukan.");
}

// Assign the kode_transaksi from the fetched data
$kode_transaksi = $pemasukan['kode_transaksi'];

// Fetch the existing Pemasukan for this transaction
$sql_pemasukan = "SELECT * FROM pemasukan_kasir WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan";
$stmt_pemasukan = $pdo->prepare($sql_pemasukan);
$stmt_pemasukan->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pemasukan->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_pemasukan->execute();
$pemasukan_data = $stmt_pemasukan->fetchAll(PDO::FETCH_ASSOC);

$pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Delete the pemasukan data based on ID and kode_karyawan
$sql = "DELETE FROM pemasukan_kasir WHERE id = :id AND kode_karyawan = :kode_karyawan";
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':id', $id, PDO::PARAM_INT);
$stmt->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);

if ($stmt->execute()) {
echo "<script>alert('Data pemasukan berhasil diperbarui.'); window.location.href='pemasukan_kasir.php?kode_transaksi=$kode_transaksi';</script>";
} else {
    echo "<script>alert('Terjadi kesalahan saat menghapus data.'); window.location.href='index_kasir.php';</script>";
}
?>
