<?php
session_start();

// Koneksi ke database
try {
    $pdo = new PDO('mysql:host=localhost;dbname=fitmotor_maintance-beta', 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Debugging untuk melihat apakah data dikirimkan dengan benar
    echo "<pre>";
    print_r($_POST); // Melihat semua data yang dikirim dari form
    echo "</pre>";

    // Ambil data dari POST
    $kode_transaksi = $_POST['kode_transaksi'] ?? null;
    $user_id = $_SESSION['user_id'] ?? null;
    $jumlah = $_POST['jumlah'] ?? null;
    $keterangan = $_POST['keterangan'] ?? null;
    $kode_akun = $_POST['kode_akun'] ?? null;
    $keterangan_biaya = $_POST['keterangan_akun'] ?? null;
    $tanggal = date('Y-m-d'); // Tanggal saat ini

    // Validasi input
    if (!$kode_transaksi || !$user_id || !$jumlah || !$keterangan || !$kode_akun || !$keterangan_biaya) {
        die('Data tidak lengkap. Pastikan semua input diisi dengan benar.');
    }

    // Cek apakah kode_transaksi valid di kasir_transactions
    $sql_check = "SELECT * FROM kasir_transactions WHERE kode_transaksi = :kode_transaksi";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
    $stmt_check->execute();
    $transaksi = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$transaksi) {
        die("Kode Transaksi tidak valid, pastikan kode_transaksi sudah ada di kasir_transactions.");
    } else {
        echo "Kode Transaksi Ditemukan: " . htmlspecialchars($transaksi['kode_transaksi']) . "<br>";
    }

    // Masukkan data ke tabel kas_masuk
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

    // Menyimpan data ke database
    if ($stmt_insert->execute()) {
        echo "Pemasukan kasir berhasil disimpan!";
        header('Location: pemasukan_kasir.php'); // Redirect setelah sukses
        exit;
    } else {
        echo "Gagal menyimpan pemasukan kasir.";
    }
}
?>
