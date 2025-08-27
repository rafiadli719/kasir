<?php
require_once 'config.php';

echo "<h2>Checking Closing Transactions</h2>\n";

try {
    // Check for transactions with "DARI CLOSING" in nama_transaksi
    echo "<h3>1. Transactions with 'DARI CLOSING' in nama_transaksi</h3>\n";
    $stmt = $pdo->query("
        SELECT kode_transaksi, nama_transaksi, tanggal_transaksi, status, kode_karyawan, nama_cabang
        FROM pemasukan_kasir 
        WHERE nama_transaksi LIKE '%DARI CLOSING%'
        ORDER BY tanggal_transaksi DESC
        LIMIT 10
    ");
    
    $dari_closing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($dari_closing)) {
        echo "<p>❌ No transactions found with 'DARI CLOSING' in nama_transaksi</p>\n";
    } else {
        echo "<p>✅ Found " . count($dari_closing) . " transactions with 'DARI CLOSING'</p>\n";
        echo "<table border='1' cellpadding='5'>\n";
        echo "<tr><th>Kode</th><th>Nama Transaksi</th><th>Tanggal</th><th>Status</th><th>Karyawan</th><th>Cabang</th></tr>\n";
        foreach ($dari_closing as $trans) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($trans['kode_transaksi']) . "</td>";
            echo "<td>" . htmlspecialchars($trans['nama_transaksi']) . "</td>";
            echo "<td>" . $trans['tanggal_transaksi'] . "</td>";
            echo "<td>" . htmlspecialchars($trans['status']) . "</td>";
            echo "<td>" . htmlspecialchars($trans['kode_karyawan']) . "</td>";
            echo "<td>" . htmlspecialchars($trans['nama_cabang']) . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // Check kasir_transactions for end proses status
    echo "<h3>2. Kasir Transactions with 'end proses' status</h3>\n";
    $stmt = $pdo->query("
        SELECT kode_transaksi, tanggal_transaksi, status, kode_karyawan, nama_cabang
        FROM kasir_transactions 
        WHERE status = 'end proses'
        AND kode_karyawan = '2025050001'
        ORDER BY tanggal_transaksi DESC
        LIMIT 10
    ");
    
    $kasir_end_proses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($kasir_end_proses)) {
        echo "<p>❌ No kasir transactions found with 'end proses' status for user 2025050001</p>\n";
    } else {
        echo "<p>✅ Found " . count($kasir_end_proses) . " kasir transactions with 'end proses' status</p>\n";
        echo "<table border='1' cellpadding='5'>\n";
        echo "<tr><th>Kode</th><th>Tanggal</th><th>Status</th><th>Karyawan</th><th>Cabang</th></tr>\n";
        foreach ($kasir_end_proses as $trans) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($trans['kode_transaksi']) . "</td>";
            echo "<td>" . $trans['tanggal_transaksi'] . "</td>";
            echo "<td>" . htmlspecialchars($trans['status']) . "</td>";
            echo "<td>" . htmlspecialchars($trans['kode_karyawan']) . "</td>";
            echo "<td>" . htmlspecialchars($trans['nama_cabang']) . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // Check if there are any pemasukan_kasir records that reference kasir_transactions
    echo "<h3>3. Pemasukan Kasir with nomor_transaksi_closing</h3>\n";
    $stmt = $pdo->query("
        SELECT pk.kode_transaksi, pk.nama_transaksi, pk.nomor_transaksi_closing, pk.tanggal_transaksi
        FROM pemasukan_kasir pk
        WHERE pk.nomor_transaksi_closing IS NOT NULL
        ORDER BY pk.tanggal_transaksi DESC
        LIMIT 10
    ");
    
    $with_closing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($with_closing)) {
        echo "<p>❌ No pemasukan_kasir records found with nomor_transaksi_closing</p>\n";
    } else {
        echo "<p>✅ Found " . count($with_closing) . " pemasukan_kasir records with nomor_transaksi_closing</p>\n";
        echo "<table border='1' cellpadding='5'>\n";
        echo "<tr><th>Kode</th><th>Nama Transaksi</th><th>Nomor Transaksi Closing</th><th>Tanggal</th></tr>\n";
        foreach ($with_closing as $trans) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($trans['kode_transaksi']) . "</td>";
            echo "<td>" . htmlspecialchars($trans['nama_transaksi']) . "</td>";
            echo "<td>" . htmlspecialchars($trans['nomor_transaksi_closing']) . "</td>";
            echo "<td>" . $trans['tanggal_transaksi'] . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // Check the specific query that should populate the dropdown
    echo "<h3>4. Available Closing Transactions for Dropdown</h3>\n";
    $test_karyawan = '2025050001';
    $test_cabang = 'FIT MOTOR CIKDITIRO';
    
    $stmt = $pdo->prepare("
        SELECT kt.kode_transaksi, kt.tanggal_transaksi, kt.status
        FROM kasir_transactions kt
        WHERE kt.kode_karyawan = :kode_karyawan
        AND kt.nama_cabang = :nama_cabang
        AND kt.status = 'end proses'
        AND kt.kode_transaksi NOT IN (
            SELECT DISTINCT pk.nomor_transaksi_closing 
            FROM pemasukan_kasir pk 
            WHERE pk.nomor_transaksi_closing IS NOT NULL
        )
        ORDER BY kt.tanggal_transaksi DESC
    ");
    
    $stmt->execute([
        ':kode_karyawan' => $test_karyawan,
        ':nama_cabang' => $test_cabang
    ]);
    
    $available_closing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Query for kode_karyawan: <strong>$test_karyawan</strong>, nama_cabang: <strong>$test_cabang</strong></p>\n";
    
    if (empty($available_closing)) {
        echo "<p>❌ No available closing transactions found for dropdown</p>\n";
        
        // Debug: Check if user has any kasir_transactions at all
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM kasir_transactions WHERE kode_karyawan = :kode_karyawan");
        $stmt->execute([':kode_karyawan' => $test_karyawan]);
        $total = $stmt->fetch()['total'];
        echo "<p>Debug: User has $total total kasir_transactions</p>\n";
        
        // Debug: Check if user has any end proses transactions
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM kasir_transactions WHERE kode_karyawan = :kode_karyawan AND status = 'end proses'");
        $stmt->execute([':kode_karyawan' => $test_karyawan]);
        $end_proses = $stmt->fetch()['total'];
        echo "<p>Debug: User has $end_proses 'end proses' transactions</p>\n";
        
    } else {
        echo "<p>✅ Found " . count($available_closing) . " available closing transactions</p>\n";
        echo "<table border='1' cellpadding='5'>\n";
        echo "<tr><th>Kode Transaksi</th><th>Tanggal</th><th>Status</th></tr>\n";
        foreach ($available_closing as $trans) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($trans['kode_transaksi']) . "</td>";
            echo "<td>" . $trans['tanggal_transaksi'] . "</td>";
            echo "<td>" . htmlspecialchars($trans['status']) . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>\n";
}
?>
