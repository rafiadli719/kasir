<?php
// Koneksi ke database
$conn = new mysqli('localhost', 'fitmotor_LOGIN', 'Sayalupa12', 'fitmotor_maintance-beta');

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Memvalidasi parameter id yang diterima melalui GET
if (isset($_GET['id']) && !empty($_GET['id'])) {
    // Mendapatkan ID karyawan dari URL dan membersihkan input
    $employee_code = htmlspecialchars($_GET['id']);

    // Siapkan pernyataan SQL untuk menghapus data dari tabel masterkeys
    $stmt = $conn->prepare("DELETE FROM masterkeys WHERE kode_karyawan = ?");
    $stmt->bind_param("s", $employee_code);

    // Eksekusi pernyataan SQL
    if ($stmt->execute()) {
        // Jika berhasil, tampilkan pesan sukses dan arahkan kembali ke halaman view_employees.php
        echo "<script>alert('Data berhasil dihapus.'); window.location.href = 'view_employees.php';</script>";
    } else {
        // Jika gagal, tampilkan pesan gagal
        echo "<script>alert('Gagal menghapus data. Silakan coba lagi.'); window.location.href = 'view_employees.php';</script>";
    }

    // Tutup pernyataan
    $stmt->close();
} else {
    // Jika tidak ada ID yang diterima atau ID kosong, kembali ke halaman view_employees.php
    echo "<script>alert('ID karyawan tidak valid.'); window.location.href = 'view_employees.php';</script>";
}

// Tutup koneksi database
$conn->close();
?>
