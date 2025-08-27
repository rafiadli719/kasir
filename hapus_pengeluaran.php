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
    die("ID pengeluaran tidak ditemukan.");
}

try {
    // Fetch the pengeluaran data first to get the kode_transaksi
    $sql = "SELECT kode_transaksi FROM pengeluaran_kasir WHERE id = :id AND kode_karyawan = :kode_karyawan";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
    $stmt->execute();
    $pengeluaran = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pengeluaran) {
        die("Data pengeluaran tidak ditemukan atau Anda tidak memiliki akses untuk menghapus data ini.");
    }

    $kode_transaksi = $pengeluaran['kode_transaksi'];

    // Delete the pengeluaran data based on ID and kode_karyawan
    $sql = "DELETE FROM pengeluaran_kasir WHERE id = :id AND kode_karyawan = :kode_karyawan";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);

    if ($stmt->execute()) {
        // Log successful deletion
        error_log("Data pengeluaran ID: $id berhasil dihapus oleh karyawan: $kode_karyawan");
        
        // Redirect back to pengeluaran_kasir.php with success message
        header("Location: pengeluaran_kasir.php?kode_transaksi=$kode_transaksi&deleted=1");
        exit;
    } else {
        error_log("Gagal menghapus data pengeluaran ID: $id");
        header("Location: pengeluaran_kasir.php?kode_transaksi=$kode_transaksi&error=delete_failed");
        exit;
    }
} catch (PDOException $e) {
    error_log("Database error saat menghapus pengeluaran: " . $e->getMessage());
    header("Location: pengeluaran_kasir.php?error=database_error");
    exit;
} catch (Exception $e) {
    error_log("Error saat menghapus pengeluaran: " . $e->getMessage());
    header("Location: pengeluaran_kasir.php?error=general_error");
    exit;
}
?>