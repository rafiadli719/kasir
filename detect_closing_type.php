<?php
/**
 * Detect Closing Type - Standalone endpoint
 * File terpisah untuk menangani deteksi jenis closing via AJAX
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';

// Set header JSON
header('Content-Type: application/json');

// Cek autentikasi
if (!isset($_SESSION['kode_karyawan']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

if (!in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Hanya terima POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $kode_transaksi = $_POST['kode_transaksi'] ?? '';
    $nama_cabang = $_POST['nama_cabang'] ?? $_SESSION['nama_cabang'];
    
    if (empty($kode_transaksi)) {
        echo json_encode(['success' => false, 'message' => 'Kode transaksi tidak boleh kosong']);
        exit;
    }
    
    // Function untuk menentukan jenis closing
    function determineClosingType($pdo, $kode_transaksi, $nama_cabang) {
        try {
            // 1. Cek apakah transaksi ini sebelumnya dipinjam dari kasir lain
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM kasir_transactions 
                                  WHERE kode_transaksi = ? AND keterangan LIKE '%dipinjam%'");
            $stmt->execute([$kode_transaksi]);
            if ($stmt->fetchColumn() > 0) {
                return 'dipinjam';
            }
            
            // 2. Cek apakah ada transaksi sedang berjalan dengan pemasukan "DARI CLOSING" hari ini
            $sql = "SELECT COUNT(*) FROM pemasukan_kasir pk
                    WHERE pk.keterangan_transaksi LIKE '%DARI CLOSING%' 
                    AND pk.nomor_transaksi_closing IS NOT NULL
                    AND DATE(pk.tanggal) = CURDATE()";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            if ($stmt->fetchColumn() > 0) {
                return 'meminjam';
            }
            
            // 3. Default: closing normal
            return 'closing';
            
        } catch (PDOException $e) {
            error_log("Error in determineClosingType: " . $e->getMessage());
            return 'closing'; // default fallback
        }
    }
    
    $jenis_closing = determineClosingType($pdo, $kode_transaksi, $nama_cabang);
    
    echo json_encode([
        'success' => true, 
        'jenis_closing' => $jenis_closing,
        'message' => 'Jenis closing berhasil ditentukan'
    ]);
    
} catch (Exception $e) {
    error_log("Error in detect_closing_type.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>