<?php
include 'config.php'; // Menghubungkan ke database

// Cek apakah semua data yang diperlukan dikirim melalui POST
if (isset($_POST['tanggal_pengeluaran']) && isset($_POST['keterangan']) && isset($_POST['jumlah']) && isset($_POST['kode_akun']) && isset($_POST['keterangan_akun']) && isset($_POST['kode_transaksi']) && isset($_POST['user_id'])) {
    
    // Ambil data dari form
    $tanggal_pengeluaran = $_POST['tanggal_pengeluaran'];
    $keterangan = $_POST['keterangan'];
    $jumlah = $_POST['jumlah'];
    $kode_akun = $_POST['kode_akun'];
    $keterangan_akun = $_POST['keterangan_akun'];
    $user_id = $_POST['user_id']; // Ambil user_id dari form hidden
    $kode_transaksi = $_POST['kode_transaksi']; // Ambil kode_transaksi dari form hidden
    
    // Query untuk menyimpan ke tabel pengeluaran_kasir
    $sql = "INSERT INTO pengeluaran_kasir (tanggal, keterangan, jumlah, kode_akun, keterangan_biaya, user_id, kode_transaksi) 
            VALUES ('$tanggal_pengeluaran', '$keterangan', '$jumlah', '$kode_akun', '$keterangan_akun', '$user_id', '$kode_transaksi')";

    if (mysqli_query($conn, $sql)) {
        // Redirect ke halaman kas_masuk_dan_pengeluaran_kasir.php setelah berhasil menyimpan data
        header("Location: pengeluaran_kasir.php?status=success");
    } else {
        // Jika ada kesalahan saat menyimpan data, tampilkan pesan error
        echo "Error: " . $sql . "<br>" . mysqli_error($conn);
    }
} else {
    // Jika ada data yang tidak dikirim melalui form
    echo "Data pengeluaran tidak lengkap, mohon isi semua field.";
}

mysqli_close($conn); // Menutup koneksi ke database
?>
