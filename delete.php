<?php
// Include konfigurasi database
require_once 'config.php';

if (isset($_GET['id'])) {
    // Ambil ID dari URL
    $id = $_GET['id'];

    // Hapus data dari database
    $stmt = $pdo->prepare("DELETE FROM kasir_transactions WHERE id = ?");
    $stmt->execute([$id]);

    // Redirect kembali ke halaman utama
    header("Location: kasir_closing_dashboard.php");
    exit;
}
?>
