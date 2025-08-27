<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Koneksi ke database
$host = "localhost";
$username = "fitmotor_LOGIN";
$password = "Sayalupa12";
$database = "fitmotor_maintance-beta";

// Koneksi ke database
$con = new mysqli($host, $username, $password, $database);

// Memeriksa koneksi
if ($con->connect_error) {
    die("Koneksi gagal: " . $con->connect_error);
}

if (isset($_POST['cabang'])) {
    // Mengamankan input dan mengubahnya menjadi huruf kecil
    $cabang = strtolower($con->real_escape_string($_POST['cabang']));
    
    // Ambil data karyawan dan role berdasarkan cabang, hanya jika status aktif
    $sql = "
        SELECT mk.kode_karyawan, mk.nama_karyawan, IFNULL(u.role, 'user') as role
        FROM masterkeys mk
        LEFT JOIN users u ON mk.kode_karyawan = u.kode_karyawan
        WHERE mk.nama_cabang = ? AND mk.status_aktif = 1
    ";
    
    if ($stmt = $con->prepare($sql)) {
        $stmt->bind_param("s", $cabang);
        $stmt->execute();
        $result = $stmt->get_result();

        // Menghasilkan opsi untuk dropdown
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $role = $row['role']; // Ambil role dari hasil query
                $nama_karyawan = $row['nama_karyawan'];
                $kode_karyawan = $row['kode_karyawan'];

                // Tampilkan nama karyawan dengan role di sampingnya
                echo '<option value="' . htmlspecialchars($kode_karyawan) . '">' . htmlspecialchars($kode_karyawan . ' - ' . $nama_karyawan . ' (' . $role . ')') . '</option>';
            }
        } else {
            echo '<option value="">Karyawan tidak ditemukan</option>';
        }

        // Menutup statement
        $stmt->close();
    } else {
        echo '<option value="">Query gagal: ' . $con->error . '</option>';
    }
}

// Menutup koneksi
$con->close();
?>
