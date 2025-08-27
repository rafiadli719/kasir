<?php
require_once 'config.php';

echo "<h2>Debug Closing Dropdown Issue</h2>\n";

// Simulate the same logic as in pemasukan_kasir.php
$test_kode_karyawan = '2025050001'; // From the screenshot
$test_nama_cabang = 'FIT MOTOR CIKDITIRO'; // From the screenshot

echo "<h3>1. User Info</h3>\n";
echo "<p>Kode Karyawan: <strong>$test_kode_karyawan</strong></p>\n";
echo "<p>Nama Cabang: <strong>$test_nama_cabang</strong></p>\n";

// Get user's cabang info (same as pemasukan_kasir.php line 31-37)
$sql_user_cabang = "SELECT kode_cabang, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan LIMIT 1";
$stmt_user_cabang = $pdo->prepare($sql_user_cabang);
$stmt_user_cabang->bindParam(':kode_karyawan', $test_kode_karyawan);
$stmt_user_cabang->execute();
$user_cabang_data = $stmt_user_cabang->fetch(PDO::FETCH_ASSOC);
$user_kode_cabang = $user_cabang_data['kode_cabang'] ?? '';
$user_nama_cabang = $user_cabang_data['nama_cabang'] ?? '';

echo "<h3>2. User Cabang from Database</h3>\n";
echo "<p>User Kode Cabang: <strong>$user_kode_cabang</strong></p>\n";
echo "<p>User Nama Cabang: <strong>$user_nama_cabang</strong></p>\n";

// Test the exact query from pemasukan_kasir.php (line 155-169)
echo "<h3>3. Available Closing Transactions Query</h3>\n";
$sql_closing_available = "SELECT kode_transaksi, tanggal_transaksi, setoran_real, kode_karyawan 
                         FROM kasir_transactions 
                         WHERE nama_cabang = :nama_cabang 
                         AND status = 'end proses' 
                         AND (deposit_status IS NULL OR deposit_status = '' OR deposit_status = 'Belum Disetor')
                         AND kode_transaksi NOT IN (
                             SELECT DISTINCT nomor_transaksi_closing 
                             FROM pemasukan_kasir 
                             WHERE nomor_transaksi_closing IS NOT NULL
                         )
                         ORDER BY tanggal_transaksi DESC";

echo "<p>Query parameters:</p>\n";
echo "<p>nama_cabang: <strong>$user_nama_cabang</strong></p>\n";

$stmt_closing_available = $pdo->prepare($sql_closing_available);
$stmt_closing_available->bindParam(':nama_cabang', $user_nama_cabang);
$stmt_closing_available->execute();
$available_closing = $stmt_closing_available->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Results: <strong>" . count($available_closing) . " transactions found</strong></p>\n";

if (empty($available_closing)) {
    echo "<p>❌ No available closing transactions found</p>\n";
    
    // Debug step by step
    echo "<h4>Debug Steps:</h4>\n";
    
    // Step 1: Check total kasir_transactions for this cabang
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM kasir_transactions WHERE nama_cabang = :nama_cabang");
    $stmt->execute([':nama_cabang' => $user_nama_cabang]);
    $total = $stmt->fetch()['total'];
    echo "<p>1. Total kasir_transactions for cabang '$user_nama_cabang': <strong>$total</strong></p>\n";
    
    // Step 2: Check end proses transactions
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM kasir_transactions WHERE nama_cabang = :nama_cabang AND status = 'end proses'");
    $stmt->execute([':nama_cabang' => $user_nama_cabang]);
    $end_proses = $stmt->fetch()['total'];
    echo "<p>2. 'End proses' transactions for cabang '$user_nama_cabang': <strong>$end_proses</strong></p>\n";
    
    // Step 3: Check deposit status
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM kasir_transactions WHERE nama_cabang = :nama_cabang AND status = 'end proses' AND (deposit_status IS NULL OR deposit_status = '' OR deposit_status = 'Belum Disetor')");
    $stmt->execute([':nama_cabang' => $user_nama_cabang]);
    $available_deposit = $stmt->fetch()['total'];
    echo "<p>3. Available for deposit transactions: <strong>$available_deposit</strong></p>\n";
    
    // Step 4: Check what's already used in pemasukan_kasir
    $stmt = $pdo->query("SELECT COUNT(DISTINCT nomor_transaksi_closing) as total FROM pemasukan_kasir WHERE nomor_transaksi_closing IS NOT NULL");
    $used_closing = $stmt->fetch()['total'];
    echo "<p>4. Already used in pemasukan_kasir: <strong>$used_closing</strong> transactions</p>\n";
    
    // Step 5: Show some sample end proses transactions
    echo "<h4>Sample 'end proses' transactions for this cabang:</h4>\n";
    $stmt = $pdo->prepare("SELECT kode_transaksi, tanggal_transaksi, setoran_real, deposit_status FROM kasir_transactions WHERE nama_cabang = :nama_cabang AND status = 'end proses' ORDER BY tanggal_transaksi DESC LIMIT 5");
    $stmt->execute([':nama_cabang' => $user_nama_cabang]);
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($samples)) {
        echo "<table border='1' cellpadding='5'>\n";
        echo "<tr><th>Kode</th><th>Tanggal</th><th>Setoran</th><th>Deposit Status</th></tr>\n";
        foreach ($samples as $sample) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($sample['kode_transaksi']) . "</td>";
            echo "<td>" . $sample['tanggal_transaksi'] . "</td>";
            echo "<td>Rp " . number_format($sample['setoran_real'], 0, ',', '.') . "</td>";
            echo "<td>" . htmlspecialchars($sample['deposit_status'] ?? 'NULL') . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
} else {
    echo "<p>✅ Found available closing transactions</p>\n";
    echo "<table border='1' cellpadding='5'>\n";
    echo "<tr><th>Kode</th><th>Tanggal</th><th>Setoran</th><th>Karyawan</th></tr>\n";
    foreach ($available_closing as $closing) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($closing['kode_transaksi']) . "</td>";
        echo "<td>" . $closing['tanggal_transaksi'] . "</td>";
        echo "<td>Rp " . number_format($closing['setoran_real'], 0, ',', '.') . "</td>";
        echo "<td>" . htmlspecialchars($closing['kode_karyawan']) . "</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
}

// Additional check: Look for any transactions that might match
echo "<h3>4. Alternative Search - Any End Proses Transactions</h3>\n";
$stmt = $pdo->query("SELECT kode_transaksi, tanggal_transaksi, setoran_real, kode_karyawan, nama_cabang, deposit_status FROM kasir_transactions WHERE status = 'end proses' ORDER BY tanggal_transaksi DESC LIMIT 10");
$all_end_proses = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Recent 'end proses' transactions from any cabang:</p>\n";
if (!empty($all_end_proses)) {
    echo "<table border='1' cellpadding='5'>\n";
    echo "<tr><th>Kode</th><th>Tanggal</th><th>Setoran</th><th>Karyawan</th><th>Cabang</th><th>Deposit Status</th></tr>\n";
    foreach ($all_end_proses as $trans) {
        $highlight = ($trans['nama_cabang'] == $user_nama_cabang) ? 'style="background-color: yellow;"' : '';
        echo "<tr $highlight>";
        echo "<td>" . htmlspecialchars($trans['kode_transaksi']) . "</td>";
        echo "<td>" . $trans['tanggal_transaksi'] . "</td>";
        echo "<td>Rp " . number_format($trans['setoran_real'], 0, ',', '.') . "</td>";
        echo "<td>" . htmlspecialchars($trans['kode_karyawan']) . "</td>";
        echo "<td>" . htmlspecialchars($trans['nama_cabang']) . "</td>";
        echo "<td>" . htmlspecialchars($trans['deposit_status'] ?? 'NULL') . "</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
    echo "<p><em>Yellow highlighted rows are from the same cabang as the user.</em></p>\n";
}

echo "<h3>5. Conclusion</h3>\n";
if (empty($available_closing)) {
    echo "<p>❌ <strong>Issue Found:</strong> No available closing transactions for dropdown</p>\n";
    echo "<p><strong>Possible causes:</strong></p>\n";
    echo "<ul>\n";
    echo "<li>All 'end proses' transactions already have deposit_status set</li>\n";
    echo "<li>All 'end proses' transactions already used in pemasukan_kasir</li>\n";
    echo "<li>No 'end proses' transactions exist for this cabang</li>\n";
    echo "<li>Cabang name mismatch between users table and kasir_transactions table</li>\n";
    echo "</ul>\n";
} else {
    echo "<p>✅ <strong>No Issue:</strong> Available closing transactions found</p>\n";
}
?>
