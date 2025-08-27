<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Koneksi ke database
$conn = new mysqli('localhost', 'fitmotor_LOGIN', 'Sayalupa12', 'fitmotor_prototype');
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Ambil parameter dari URL
$kode_karyawan = $_GET['kode_karyawan'] ?? '';
$siklus = $_GET['siklus'] ?? '';
$totalJamKerja = $_GET['total_jam_kerja'] ?? 0;
$minPersenJamKerja = $_GET['min_persen_jam_kerja'] ?? 100;

// Validasi parameter
if (empty($kode_karyawan) || empty($siklus)) {
    die("Kode Karyawan atau Siklus tidak valid.");
}

// Hitung minimum jam kerja berdasarkan parameter
$minJamKerja = ($totalJamKerja * $minPersenJamKerja) / 100;

// Query untuk mengambil informasi karyawan dan cabang
$queryKaryawan = "
    SELECT 
        k.kode_karyawan, 
        k.nama_karyawan, 
        k.kode_cabang, 
        c.nama_cabang 
    FROM koreksi k
    JOIN cabang c ON k.kode_cabang = c.kode_cabang
    WHERE k.kode_karyawan = ? 
    LIMIT 1
";
$stmtKaryawan = $conn->prepare($queryKaryawan);
$stmtKaryawan->bind_param("s", $kode_karyawan);
$stmtKaryawan->execute();
$resultKaryawan = $stmtKaryawan->get_result();
$karyawanData = $resultKaryawan->fetch_assoc();

if (!$karyawanData) {
    die("Data karyawan tidak ditemukan.");
}

// Query untuk detail absensi
$queryDetail = "
    SELECT 
        k.tanggal,
        k.masuk AS jam_masuk,
        k.pulang AS jam_pulang,
        CASE 
            WHEN k.masuk = '00:00:00' OR k.pulang = '00:00:00' THEN 'ABSEN BELUM LENGKAP'
            ELSE 'ABSEN LENGKAP'
        END AS status_absen,
        CASE 
            WHEN k.masuk = '00:00:00' OR k.pulang = '00:00:00' THEN 'JAM KERJA BELUM LENGKAP'
            WHEN TIME_TO_SEC(TIMEDIFF(k.pulang, k.masuk)) < ? THEN 'KURANG DARI JAM KERJA SEHARUSNYA'
            ELSE 'JAM KERJA SESUAI'
        END AS status_jam_kerja,
        (
            SELECT COUNT(DISTINCT kc.kode_cabang) 
            FROM koreksi kc 
            WHERE kc.kode_karyawan = k.kode_karyawan 
              AND kc.tanggal = k.tanggal
        ) AS jumlah_absen_cabang
    FROM koreksi k
    WHERE k.kode_karyawan = ?
      AND DATE_FORMAT(k.tanggal, '%Y-%m') = ?
    ORDER BY k.tanggal ASC
";

// Eksekusi query detail absensi
$stmtDetail = $conn->prepare($queryDetail);
$stmtDetail->bind_param("iss", $minJamKerja, $kode_karyawan, $siklus);
$stmtDetail->execute();
$resultDetail = $stmtDetail->get_result();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Koreksi Karyawan</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #121212; color: #f7f7f7; padding: 20px; }
        h1 { text-align: center; color: #f9c74f; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; border: 1px solid #444; text-align: center; }
        th { background-color: #f9c74f; color: #121212; }
        tr:nth-child(even) { background-color: #333; }
        tr:hover { background-color: #444b5e; }
        .btn-edit { background-color: #007bff; color: white; padding: 5px 10px; border: none; border-radius: 5px; text-decoration: none; cursor: pointer; }
        .btn-edit:hover { background-color: #0056b3; }
    </style>
</head>
<body>
    <h1>Detail Koreksi Karyawan</h1>
    <div>
        <p>Kode Karyawan: <?= htmlspecialchars($karyawanData['kode_karyawan']) ?></p>
        <p>Nama Karyawan: <?= htmlspecialchars($karyawanData['nama_karyawan']) ?></p>
        <p>Kode Cabang: <?= htmlspecialchars($karyawanData['kode_cabang']) ?></p>
        <p>Nama Cabang: <?= htmlspecialchars($karyawanData['nama_cabang']) ?></p>
        <p>Siklus: <?= htmlspecialchars($siklus) ?></p>
    </div>
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Status Absen</th>
                <th>Status Jam Kerja</th>
                <th>Jumlah Absen Cabang</th>
                <th>Edit</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $resultDetail->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['tanggal']) ?></td>
                    <td><?= htmlspecialchars($row['status_absen']) ?></td>
                    <td><?= htmlspecialchars($row['status_jam_kerja']) ?></td>
                    <td><?= htmlspecialchars($row['jumlah_absen_cabang']) ?></td>
                    <td>
                        <a href="edit_koreksi.php?kode_karyawan=<?= urlencode($kode_karyawan) ?>&tanggal=<?= urlencode($row['tanggal']) ?>" class="btn-edit">Edit</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</body>
</html>
