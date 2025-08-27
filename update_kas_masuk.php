<?php
session_start();
include 'config.php'; // Koneksi ke database

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Ambil data dari form
    $kode_transaksi = $_POST['kode_transaksi'] ?? null;
    $user_id = $_SESSION['user_id'] ?? null;
    $jumlah = $_POST['jumlah'] ?? null;
    $keterangan = $_POST['keterangan'] ?? null;
    $kode_akun = $_POST['kode_akun'] ?? null;
    $keterangan_biaya = $_POST['keterangan_akun'] ?? null;
    $tanggal = date('Y-m-d');

    // Validasi jika ada input yang kosong
    if (!$kode_transaksi || !$user_id || !$jumlah || !$keterangan || !$kode_akun || !$keterangan_biaya) {
        die('Data tidak lengkap. Pastikan semua input diisi dengan benar.');
    }

    // Cek apakah kode_transaksi valid
    $sql_check = "SELECT COUNT(*) FROM kasir_transactions WHERE kode_transaksi = :kode_transaksi";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
    $stmt_check->execute();
    $transaksi_exists = $stmt_check->fetchColumn();

    if ($transaksi_exists == 0) {
        die("Kode Transaksi tidak valid, pastikan kode_transaksi sudah ada di kasir_transactions.");
    }

    // Jika valid, masukkan ke tabel kas_masuk
    $sql_insert = "INSERT INTO kas_masuk (kode_transaksi, user_id, jumlah, keterangan, kode_akun, keterangan_biaya, tanggal) 
                   VALUES (:kode_transaksi, :user_id, :jumlah, :keterangan, :kode_akun, :keterangan_biaya, :tanggal)";
    $stmt_insert = $pdo->prepare($sql_insert);
    $stmt_insert->bindParam(':kode_transaksi', $kode_transaksi);
    $stmt_insert->bindParam(':user_id', $user_id);
    $stmt_insert->bindParam(':jumlah', $jumlah);
    $stmt_insert->bindParam(':keterangan', $keterangan);
    $stmt_insert->bindParam(':kode_akun', $kode_akun);
    $stmt_insert->bindParam(':keterangan_biaya', $keterangan_biaya);
    $stmt_insert->bindParam(':tanggal', $tanggal);

    if ($stmt_insert->execute()) {
        echo "Pemasukan kasir berhasil disimpan!";
        header('Location: index_kasir.php');
        exit;
    } else {
        echo "Gagal menyimpan pemasukan kasir.";
    }
}
?>
