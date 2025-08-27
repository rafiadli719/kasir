<?php
include 'config.php';

if (isset($_POST['id'])) {
    $id = $_POST['id'];
    $tanggal_pengeluaran = $_POST['tanggal_pengeluaran'];
    $keterangan = $_POST['keterangan'];
    $jumlah = $_POST['jumlah'];
    $kode_akun = $_POST['kode_akun'];

    $sql = "UPDATE pengeluaran_kasir 
            SET tanggal = '$tanggal_pengeluaran', keterangan = '$keterangan', jumlah = '$jumlah', kode_akun = '$kode_akun'
            WHERE id = $id";

    if (mysqli_query($conn, $sql)) {
        header("Location: kas_masuk_dan_pengeluaran_kasir.php");
    } else {
        echo "Error: " . $sql . "<br>" . mysqli_error($conn);
    }
}

mysqli_close($conn);
?>
