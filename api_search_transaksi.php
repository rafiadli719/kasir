<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Start session to check authentication
session_start();

// Check if user is logged in
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

include 'config.php';

try {
    // Get search query from GET parameter
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    
    if (empty($query) || strlen($query) < 2) {
        echo json_encode([
            'success' => true,
            'data' => []
        ]);
        exit;
    }

    // Prepare SQL query to search nama transaksi
    $sql = "SELECT mnt.id, mnt.nama_transaksi, mnt.kode_akun, mnt.keterangan_default, 
                   ma.arti, ma.kategori, ma.require_umur_pakai, ma.min_umur_pakai
            FROM master_nama_transaksi mnt 
            JOIN master_akun ma ON mnt.kode_akun = ma.kode_akun 
            WHERE mnt.status = 'active' 
            AND (mnt.nama_transaksi LIKE :query OR ma.arti LIKE :query)
            ORDER BY 
                CASE 
                    WHEN mnt.nama_transaksi LIKE :exact_query THEN 1
                    WHEN mnt.nama_transaksi LIKE :start_query THEN 2
                    ELSE 3
                END,
                mnt.nama_transaksi
            LIMIT 10";

    $stmt = $pdo->prepare($sql);
    $searchParam = '%' . $query . '%';
    $exactParam = $query . '%';
    $startParam = $query . '%';
    
    $stmt->bindParam(':query', $searchParam, PDO::PARAM_STR);
    $stmt->bindParam(':exact_query', $exactParam, PDO::PARAM_STR);
    $stmt->bindParam(':start_query', $startParam, PDO::PARAM_STR);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the results
    $formattedResults = [];
    foreach ($results as $row) {
        $formattedResults[] = [
            'id' => $row['id'],
            'nama_transaksi' => $row['nama_transaksi'],
            'kode_akun' => $row['kode_akun'],
            'arti' => $row['arti'],
            'keterangan_default' => $row['keterangan_default'],
            'kategori' => $row['kategori'],
            'require_umur_pakai' => (bool)$row['require_umur_pakai'],
            'min_umur_pakai' => (int)$row['min_umur_pakai'],
            'display_text' => $row['nama_transaksi'],
            'display_subtext' => $row['kode_akun'] . ' - ' . $row['arti']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $formattedResults,
        'count' => count($formattedResults)
    ]);

} catch (PDOException $e) {
    error_log("Database error in search API: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    error_log("General error in search API: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while searching'
    ]);
}
?>