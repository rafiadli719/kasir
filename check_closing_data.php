<?php
include 'config.php';

try {
    // Check for available closing transactions
    $sql = "SELECT kode_transaksi, tanggal_transaksi, setoran_real, status, nama_cabang 
            FROM kasir_transactions 
            WHERE status = 'end proses' 
            LIMIT 10";
    $stmt = $pdo->query($sql);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Available closing transactions:\n";
    if (empty($results)) {
        echo "No closing transactions found\n";
        
        // Create a test closing transaction
        $insert_sql = "INSERT INTO kasir_transactions 
                      (kode_transaksi, tanggal_transaksi, nama_cabang, kode_karyawan, setoran_real, status) 
                      VALUES ('TRX-TEST-CLOSING-002', CURDATE(), 'WINONG', 'KRY001', 150000, 'end proses')";
        $pdo->exec($insert_sql);
        echo "Created test closing transaction: TRX-TEST-CLOSING-002\n";
    } else {
        foreach ($results as $row) {
            echo "- {$row['kode_transaksi']} | {$row['nama_cabang']} | {$row['tanggal_transaksi']} | Rp {$row['setoran_real']} | {$row['status']}\n";
        }
    }
    
    // Test form submission simulation
    echo "\n=== TESTING FORM SUBMISSION ===\n";
    
    // Simulate form data
    $_POST = [
        'submit_pemasukan' => true,
        'nama_transaksi' => 'DARI CLOSING',
        'nomor_transaksi_closing' => 'TRX-TEST-CLOSING-001',
        'kode_akun' => 'KASBSR',
        'jumlah' => '100000',
        'keterangan_transaksi' => 'TEST PENGAMBILAN UANG DARI CLOSING'
    ];
    
    echo "Form data prepared for testing\n";
    echo "- Nama Transaksi: {$_POST['nama_transaksi']}\n";
    echo "- Nomor Closing: {$_POST['nomor_transaksi_closing']}\n";
    echo "- Jumlah: {$_POST['jumlah']}\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
