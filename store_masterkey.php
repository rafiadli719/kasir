<?php
// Koneksi ke database
$conn = new mysqli('localhost', 'fitmotor_LOGIN', 'Sayalupa12', 'fitmotor_maintance-beta');

// Periksa koneksi
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Mendapatkan data dari form
$nama_karyawan = $_POST['nama_karyawan'];
$entry_year = $_POST['entry_year'];
$entry_month = str_pad($_POST['entry_month'], 2, '0', STR_PAD_LEFT);  // Pastikan 2 digit untuk bulan
$kode_cabang = $_POST['kode_cabang'];
$status_aktif = isset($_POST['status_aktif']) ? $_POST['status_aktif'] : 1;  // Default ke 1 jika tidak disediakan

// Mapping kode_cabang ke nama_cabang dari database
$nama_cabang = '';
$stmt = $conn->prepare("SELECT nama_cabang FROM cabang WHERE kode_cabang = ?");
$stmt->bind_param("s", $kode_cabang);
$stmt->execute();
$stmt->bind_result($nama_cabang);
$stmt->fetch();
$stmt->close();

if (empty($nama_cabang)) {
    die("Branch ID tidak valid.");
}

// Hitung angka urutan terakhir untuk tahun dan bulan yang sama
$stmt = $conn->prepare("SELECT COUNT(*) AS count FROM masterkeys WHERE entry_year = ? AND entry_month = ?");
$stmt->bind_param("ii", $entry_year, $entry_month);
$stmt->execute();
$result = $stmt->get_result();
$count = $result->fetch_assoc()['count'] + 1;  // Tambahkan 1 untuk nomor urut baru
$stmt->close();

// Buat kode karyawan dengan format YYYYMM000A (e.g., 2024100001)
$employee_code = $entry_year . str_pad($entry_month, 2, '0', STR_PAD_LEFT) . '000' . $count;

// Simpan data ke database
$stmt = $conn->prepare("INSERT INTO masterkeys (kode_karyawan, nama_karyawan, entry_year, entry_month, kode_cabang, nama_cabang, status_aktif) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssssi", $employee_code, $nama_karyawan, $entry_year, $entry_month, $kode_cabang, $nama_cabang, $status_aktif);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Data berhasil disimpan dengan kode karyawan: ' . $employee_code]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan data: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
