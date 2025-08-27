<?php
require_once 'config.php';

echo "<h2>Create Test Closing Transaction</h2>\n";

try {
    // Create a test closing transaction that should appear in dropdown
    $test_kode_karyawan = '2025050001';
    $test_nama_cabang = 'FIT MOTOR CIKDITIRO';
    $test_kode_transaksi = 'TRX-' . date('Ymd') . '-TEST' . rand(1000, 9999);
    
    echo "<p>Creating test transaction:</p>\n";
    echo "<ul>\n";
    echo "<li>Kode Transaksi: <strong>$test_kode_transaksi</strong></li>\n";
    echo "<li>Kode Karyawan: <strong>$test_kode_karyawan</strong></li>\n";
    echo "<li>Nama Cabang: <strong>$test_nama_cabang</strong></li>\n";
    echo "<li>Status: <strong>end proses</strong></li>\n";
    echo "<li>Deposit Status: <strong>Belum Disetor</strong></li>\n";
    echo "</ul>\n";
    
    $stmt = $pdo->prepare("
        INSERT INTO kasir_transactions (
            kode_transaksi, 
            kode_karyawan, 
            nama_cabang,
            kode_cabang,
            tanggal_transaksi,
            tanggal_closing,
            jam_closing,
            kas_awal,
            kas_akhir,
            total_pemasukan,
            total_pengeluaran,
            total_penjualan,
            total_servis,
            setoran_real,
            omset,
            data_setoran,
            selisih_setoran,
            status,
            deposit_status
        ) VALUES (
            :kode_transaksi,
            :kode_karyawan,
            :nama_cabang,
            'CIKD',
            CURDATE(),
            CURDATE(),
            CURTIME(),
            100000,
            150000,
            50000,
            0,
            30000,
            20000,
            50000,
            50000,
            50000,
            0,
            'end proses',
            'Belum Disetor'
        )
    ");
    
    $result = $stmt->execute([
        ':kode_transaksi' => $test_kode_transaksi,
        ':kode_karyawan' => $test_kode_karyawan,
        ':nama_cabang' => $test_nama_cabang
    ]);
    
    if ($result) {
        echo "<p>✅ <strong>Test transaction created successfully!</strong></p>\n";
        echo "<p>This transaction should now appear in the 'Nomor Transaksi Closing' dropdown.</p>\n";
        
        // Verify it appears in the query
        echo "<h3>Verification</h3>\n";
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
        
        $stmt_verify = $pdo->prepare($sql_closing_available);
        $stmt_verify->bindParam(':nama_cabang', $test_nama_cabang);
        $stmt_verify->execute();
        $available_closing = $stmt_verify->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<p>Available closing transactions now: <strong>" . count($available_closing) . "</strong></p>\n";
        
        if (!empty($available_closing)) {
            echo "<table border='1' cellpadding='5'>\n";
            echo "<tr><th>Kode</th><th>Tanggal</th><th>Setoran</th><th>Karyawan</th></tr>\n";
            foreach ($available_closing as $closing) {
                $highlight = ($closing['kode_transaksi'] == $test_kode_transaksi) ? 'style="background-color: lightgreen;"' : '';
                echo "<tr $highlight>";
                echo "<td>" . htmlspecialchars($closing['kode_transaksi']) . "</td>";
                echo "<td>" . $closing['tanggal_transaksi'] . "</td>";
                echo "<td>Rp " . number_format($closing['setoran_real'], 0, ',', '.') . "</td>";
                echo "<td>" . htmlspecialchars($closing['kode_karyawan']) . "</td>";
                echo "</tr>\n";
            }
            echo "</table>\n";
            echo "<p><em>Green highlighted row is the newly created test transaction.</em></p>\n";
        }
        
        echo "<h3>Next Steps</h3>\n";
        echo "<p>1. Refresh the pemasukan kasir page</p>\n";
        echo "<p>2. Select 'DARI CLOSING' as nama transaksi</p>\n";
        echo "<p>3. The dropdown should now show the test transaction</p>\n";
        echo "<p>4. You can delete this test transaction later if needed</p>\n";
        
        echo "<h4>Delete Test Transaction (Optional)</h4>\n";
        echo "<p>To delete this test transaction, run:</p>\n";
        echo "<code>DELETE FROM kasir_transactions WHERE kode_transaksi = '$test_kode_transaksi'</code>\n";
        
    } else {
        echo "<p>❌ <strong>Failed to create test transaction</strong></p>\n";
    }
    
} catch (Exception $e) {
    echo "<p>❌ <strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>\n";
}
?>
