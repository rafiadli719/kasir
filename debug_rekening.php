<?php
// File debug untuk melihat data rekening yang sebenarnya ada di database
// Simpan sebagai debug_rekening.php dan jalankan untuk melihat data

$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

echo "<h2>DEBUG: Data Rekening di Database</h2>";

// 1. Cek data di tabel setoran_ke_bank
echo "<h3>1. Data di tabel setoran_ke_bank:</h3>";
$sql = "SELECT DISTINCT rekening_tujuan FROM setoran_ke_bank WHERE rekening_tujuan IS NOT NULL ORDER BY rekening_tujuan";
$stmt = $pdo->query($sql);
$rekening_bank = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "<pre>";
print_r($rekening_bank);
echo "</pre>";

// 2. Cek join dengan detail dan setoran_keuangan
echo "<h3>2. Data rekening dengan cabang (JOIN):</h3>";
$sql = "
    SELECT 
        sb.rekening_tujuan,
        sk.nama_cabang,
        COUNT(*) as jumlah_transaksi
    FROM setoran_ke_bank sb
    JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
    JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
    WHERE sb.rekening_tujuan IS NOT NULL AND sb.rekening_tujuan != ''
    GROUP BY sb.rekening_tujuan, sk.nama_cabang
    ORDER BY sb.rekening_tujuan, sk.nama_cabang
";
$stmt = $pdo->query($sql);
$data_detail = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($data_detail);
echo "</pre>";

// 3. Data dengan GROUP BY rekening
echo "<h3>3. Data rekening dengan GROUP_CONCAT cabang:</h3>";
$sql = "
    SELECT 
        sb.rekening_tujuan,
        GROUP_CONCAT(DISTINCT sk.nama_cabang ORDER BY sk.nama_cabang SEPARATOR ' & ') as nama_cabang_combined,
        COUNT(DISTINCT sk.nama_cabang) as jumlah_cabang
    FROM setoran_ke_bank sb
    JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
    JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
    WHERE sb.rekening_tujuan IS NOT NULL AND sb.rekening_tujuan != ''
    GROUP BY sb.rekening_tujuan
    ORDER BY sb.rekening_tujuan
";
$stmt = $pdo->query($sql);
$data_grouped = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($data_grouped);
echo "</pre>";

// 4. Cek apakah ada data duplikat rekening
echo "<h3>4. Analisis rekening yang sama:</h3>";
$rekening_count = [];
foreach ($data_detail as $row) {
    $rek = $row['rekening_tujuan'];
    if (!isset($rekening_count[$rek])) {
        $rekening_count[$rek] = [];
    }
    $rekening_count[$rek][] = $row['nama_cabang'];
}

foreach ($rekening_count as $rek => $cabang_list) {
    if (count($cabang_list) > 1) {
        echo "Rekening: " . $rek . " digunakan oleh cabang: " . implode(', ', $cabang_list) . "<br>";
    }
}

// 5. Cek data master_rekening_cabang untuk referensi
echo "<h3>5. Data di master_rekening_cabang:</h3>";
$sql = "
    SELECT 
        mrc.no_rekening,
        c.nama_cabang
    FROM master_rekening_cabang mrc
    JOIN cabang c ON mrc.kode_cabang = c.kode_cabang
    WHERE mrc.no_rekening IS NOT NULL AND mrc.no_rekening != ''
    ORDER BY mrc.no_rekening, c.nama_cabang
";
$stmt = $pdo->query($sql);
$master_rekening = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($master_rekening);
echo "</pre>";

echo "<h3>6. Perbandingan nomor rekening antara tabel:</h3>";
echo "<h4>Rekening di setoran_ke_bank:</h4>";
foreach ($rekening_bank as $rek) {
    echo "- " . $rek . "<br>";
}

echo "<h4>Rekening di master_rekening_cabang:</h4>";
$master_rek_list = [];
foreach ($master_rekening as $row) {
    if (!in_array($row['no_rekening'], $master_rek_list)) {
        $master_rek_list[] = $row['no_rekening'];
        echo "- " . $row['no_rekening'] . "<br>";
    }
}
?>