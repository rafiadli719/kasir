<?php
// Koneksi ke database
$conn = new mysqli('localhost', 'fitmotor_LOGIN', 'Sayalupa12', 'fitmotor_maintance-beta');

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Mendapatkan data dari form
$kode_karyawan = $_POST['kode_karyawan'];
$kode_cabang = $_POST['kode_cabang'];
$status_aktif = $_POST['status_aktif'];

// Mapping kode_cabang ke nama_cabang dari database
$nama_cabang = '';
$stmt = $conn->prepare("SELECT nama_cabang FROM cabang WHERE kode_cabang = ?");
$stmt->bind_param("s", $kode_cabang);
$stmt->execute();
$stmt->bind_result($nama_cabang);
$stmt->fetch();
$stmt->close();

if (empty($nama_cabang)) {
    die("Branch ID tidak valid.");
}

// Update hanya cabang dan status aktif pada karyawan
$stmt = $conn->prepare("UPDATE masterkeys SET kode_cabang = ?, nama_cabang = ?, status_aktif = ? WHERE kode_karyawan = ?");
$stmt->bind_param("ssis", $kode_cabang, $nama_cabang, $status_aktif, $kode_karyawan);

if ($stmt->execute()) {
    echo "<script>alert('Data berhasil diupdate.'); window.location.href = 'view_employees.php';</script>";
} else {
    echo "Gagal mengupdate data: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>
