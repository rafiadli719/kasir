<?php
include('config.php');

// Koneksi ke database
$host = "localhost";
$db_user = "fitmotor_LOGIN";
$db_password = "Sayalupa12";
$db_name = "fitmotor_maintance-beta";
$conn = mysqli_connect($host, $db_user, $db_password, $db_name);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

if (isset($_GET['kode_karyawan'])) {
    $kode_karyawan = $_GET['kode_karyawan'];

    // Fetch nama cabang based on kode_karyawan
    $sql = "SELECT nama_cabang FROM masterkeys WHERE kode_karyawan = '$kode_karyawan'";
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        echo json_encode(['nama_cabang' => $row['nama_cabang']]);
    } else {
        echo json_encode(['nama_cabang' => '']);
    }
}
?>
