<?php
include 'config.php'; // Koneksi ke database melalui file config.php

// Koneksi ke database MySQL
$conn = mysqli_connect("localhost", "fitmotor_LOGIN", "Sayalupa12", "fitmotor_maintance-beta");
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Ambil data kasir berdasarkan cabang yang dipilih
if (isset($_POST['cabang'])) {
    $cabang = $_POST['cabang'];

    // Menggunakan prepared statement untuk mencegah SQL Injection
    $query = "SELECT id, username FROM users WHERE cabang = ? AND role = 'kasir'";
    if ($stmt = mysqli_prepare($conn, $query)) {
        // Bind parameter
        mysqli_stmt_bind_param($stmt, "s", $cabang);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        // Jika ada hasil, tampilkan kasir
        if (mysqli_num_rows($result) > 0) {
            echo '<option value="">-- Pilih Kasir --</option>';
            while ($row = mysqli_fetch_assoc($result)) {
                echo '<option value="' . $row['id'] . '">' . htmlspecialchars($row['username']) . '</option>';
            }
        } else {
            echo '<option value="">-- Tidak Ada Kasir --</option>';
        }

        // Menutup prepared statement
        mysqli_stmt_close($stmt);
    } else {
        echo '<option value="">Query gagal: ' . mysqli_error($conn) . '</option>';
    }
}

// Menutup koneksi database
mysqli_close($conn);
?>
