<?php
$servername = "localhost";
$username = "root"; // Ganti dengan username database Anda
$password = ""; // Ganti dengan password database Anda
$dbname = "fitmotor_maintance-beta";

// Membuat koneksi
$conn = new mysqli($servername, $username, $password, $dbname);

// Cek koneksi
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

$sql = "SELECT * FROM kas_awal WHERE status = 'On Proses'";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        echo "<tr>
                <td>{$row['total_nilai']}</td>
                <td>{$row['tanggal']}</td>
                <td>{$row['waktu']}</td>
                <td>{$row['status']}</td>
                <td><button type='button' onclick='editRow(this)'>Edit</button> <button type='button' onclick='deleteRow(this)'>Delete</button></td>
              </tr>";
    }
} else {
    echo "<tr><td colspan='5'>Tidak ada data</td></tr>";
}

$conn->close();
?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        fetch('getData.php')
            .then(response => response.text())
            .then(data => {
                document.getElementById('tableBody').innerHTML = data; // Isi tabel dengan data dari server
            })
            .catch(error => console.error('Error:', error));
    });

    // Fungsi lain seperti editRow dan deleteRow
</script>
