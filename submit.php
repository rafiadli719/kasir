<?php
session_start();
$servername = "localhost";
$username = "root"; // Ganti dengan username database Anda
$password = ""; // Ganti dengan password database Anda
$dbname = "kasir";

// Koneksi ke database
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Ambil data dari form
$user_id = $_SESSION['user_id']; // Ambil user_id dari session
$total_nilai = $_POST['totalNilai'];
$tanggal = $_POST['tanggal'];
$waktu = $_POST['waktu'];
$status = "On Proses"; // Status awal

// Simpan data kas awal
$sql = "INSERT INTO kas_awal (user_id, total_nilai, tanggal, waktu, status) VALUES (?, ?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssss", $user_id, $total_nilai, $tanggal, $waktu, $status);

if ($stmt->execute()) {
    echo "Data kas awal berhasil disimpan.";
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>
