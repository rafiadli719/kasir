<?php
/**
 * Session Helper - Complete utility untuk standardisasi session management
 * Letakkan file ini di folder yang sama dengan file-file kasir
 */

/**
 * Validasi dan ambil data cabang dari berbagai sumber
 */
function validateAndGetBranchData($pdo, $kode_karyawan) {
    $branch_data = [
        'kode_cabang' => null,
        'nama_cabang' => null,
        'is_valid' => false,
        'source' => null
    ];
    
    // 1. Cek session terlebih dahulu
    if (isset($_SESSION['kode_cabang']) && isset($_SESSION['nama_cabang']) && 
        !empty($_SESSION['kode_cabang']) && !empty($_SESSION['nama_cabang'])) {
        
        $branch_data['kode_cabang'] = $_SESSION['kode_cabang'];
        $branch_data['nama_cabang'] = $_SESSION['nama_cabang'];
        $branch_data['is_valid'] = true;
        $branch_data['source'] = 'session';
        
        return $branch_data;
    }
    
    // 2. Jika session kosong, ambil dari database users
    try {
        $sql_user = "SELECT kode_cabang, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan LIMIT 1";
        $stmt_user = $pdo->prepare($sql_user);
        $stmt_user->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
        $stmt_user->execute();
        $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
        
        if ($user_data && !empty($user_data['kode_cabang']) && !empty($user_data['nama_cabang'])) {
            // Update session dengan data dari database
            $_SESSION['kode_cabang'] = $user_data['kode_cabang'];
            $_SESSION['nama_cabang'] = $user_data['nama_cabang'];
            $_SESSION['cabang'] = $user_data['nama_cabang']; // Untuk backward compatibility
            
            $branch_data['kode_cabang'] = $user_data['kode_cabang'];
            $branch_data['nama_cabang'] = $user_data['nama_cabang'];
            $branch_data['is_valid'] = true;
            $branch_data['source'] = 'users_table';
            
            return $branch_data;
        }
        
        // 3. Jika masih tidak ada, cek dari kasir_transactions terakhir
        $sql_trans = "SELECT kode_cabang, nama_cabang FROM kasir_transactions 
                      WHERE kode_karyawan = :kode_karyawan 
                      AND kode_cabang IS NOT NULL AND nama_cabang IS NOT NULL 
                      AND kode_cabang != '' AND nama_cabang != ''
                      ORDER BY tanggal_transaksi DESC, id DESC LIMIT 1";
        $stmt_trans = $pdo->prepare($sql_trans);
        $stmt_trans->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
        $stmt_trans->execute();
        $trans_data = $stmt_trans->fetch(PDO::FETCH_ASSOC);
        
        if ($trans_data && !empty($trans_data['kode_cabang']) && !empty($trans_data['nama_cabang'])) {
            // Update session dan database users
            $_SESSION['kode_cabang'] = $trans_data['kode_cabang'];
            $_SESSION['nama_cabang'] = $trans_data['nama_cabang'];
            $_SESSION['cabang'] = $trans_data['nama_cabang'];
            
            // Update tabel users juga
            $sql_update_user = "UPDATE users SET kode_cabang = :kode_cabang, nama_cabang = :nama_cabang 
                               WHERE kode_karyawan = :kode_karyawan";
            $stmt_update = $pdo->prepare($sql_update_user);
            $stmt_update->bindParam(':kode_cabang', $trans_data['kode_cabang'], PDO::PARAM_STR);
            $stmt_update->bindParam(':nama_cabang', $trans_data['nama_cabang'], PDO::PARAM_STR);
            $stmt_update->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
            $stmt_update->execute();
            
            $branch_data['kode_cabang'] = $trans_data['kode_cabang'];
            $branch_data['nama_cabang'] = $trans_data['nama_cabang'];
            $branch_data['is_valid'] = true;
            $branch_data['source'] = 'transactions_history';
            
            return $branch_data;
        }
        
    } catch (Exception $e) {
        error_log("Error in validateAndGetBranchData: " . $e->getMessage());
    }
    
    return $branch_data;
}

/**
 * Pastikan data cabang lengkap atau redirect ke login
 */
function ensureBranchDataComplete($pdo, $kode_karyawan) {
    $branch_data = validateAndGetBranchData($pdo, $kode_karyawan);
    
    if (!$branch_data['is_valid']) {
        // Log error untuk debugging
        error_log("Missing branch data for karyawan: $kode_karyawan");
        
        // Redirect ke halaman untuk set cabang atau logout
        session_destroy();
        header('Location: ../../login_dashboard/login.php?error=missing_branch_data');
        exit();
    }
    
    return $branch_data;
}

/**
 * Update records yang missing branch data
 */
function updateMissingBranchData($pdo) {
    try {
        // Update records yang missing branch data
        $sql_missing = "SELECT DISTINCT kode_karyawan FROM kasir_transactions 
                       WHERE (kode_cabang IS NULL OR nama_cabang IS NULL OR kode_cabang = '' OR nama_cabang = '')";
        $stmt_missing = $pdo->query($sql_missing);
        $missing_records = $stmt_missing->fetchAll(PDO::FETCH_ASSOC);
        
        $updated_count = 0;
        
        foreach ($missing_records as $record) {
            $kode_karyawan = $record['kode_karyawan'];
            
            // Ambil data cabang dari users
            $sql_user = "SELECT kode_cabang, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan";
            $stmt_user = $pdo->prepare($sql_user);
            $stmt_user->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
            $stmt_user->execute();
            $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
            
            if ($user_data && !empty($user_data['kode_cabang']) && !empty($user_data['nama_cabang'])) {
                // Update kasir_transactions yang missing data
                $sql_update = "UPDATE kasir_transactions 
                              SET kode_cabang = :kode_cabang, nama_cabang = :nama_cabang 
                              WHERE kode_karyawan = :kode_karyawan 
                              AND (kode_cabang IS NULL OR nama_cabang IS NULL OR kode_cabang = '' OR nama_cabang = '')";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->bindParam(':kode_cabang', $user_data['kode_cabang'], PDO::PARAM_STR);
                $stmt_update->bindParam(':nama_cabang', $user_data['nama_cabang'], PDO::PARAM_STR);
                $stmt_update->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
                $stmt_update->execute();
                
                $updated_count += $stmt_update->rowCount();
            }
        }
        
        return $updated_count;
        
    } catch (Exception $e) {
        error_log("Error in updateMissingBranchData: " . $e->getMessage());
        return 0;
    }
}

/**
 * Validasi data cabang sebelum insert/update transaksi
 */
function validateBranchBeforeTransaction($pdo, $kode_karyawan, $kode_cabang, $nama_cabang) {
    // Jika data cabang kosong, coba ambil dari helper
    if (empty($kode_cabang) || empty($nama_cabang)) {
        $branch_data = validateAndGetBranchData($pdo, $kode_karyawan);
        if ($branch_data['is_valid']) {
            return [
                'kode_cabang' => $branch_data['kode_cabang'],
                'nama_cabang' => $branch_data['nama_cabang'],
                'is_valid' => true
            ];
        }
        return ['is_valid' => false];
    }
    
    // Data sudah ada, validasi format
    if (strlen($kode_cabang) >= 3 && strlen($nama_cabang) >= 3) {
        return [
            'kode_cabang' => $kode_cabang,
            'nama_cabang' => $nama_cabang,
            'is_valid' => true
        ];
    }
    
    return ['is_valid' => false];
}

/**
 * Setup session dengan validasi lengkap
 */
function setupValidatedSession($pdo, $kode_karyawan) {
    // Pastikan data cabang tersedia
    $branch_data = ensureBranchDataComplete($pdo, $kode_karyawan);
    
    // Setup semua session keys yang diperlukan
    $_SESSION['kode_cabang'] = $branch_data['kode_cabang'];
    $_SESSION['nama_cabang'] = $branch_data['nama_cabang'];
    $_SESSION['cabang'] = $branch_data['nama_cabang']; // Backward compatibility
    
    return $branch_data;
}

/**
 * Log branch data untuk debugging
 */
function logBranchData($kode_karyawan, $action, $branch_data) {
    $log_message = "Branch Data - Karyawan: $kode_karyawan, Action: $action, ";
    $log_message .= "Kode: " . ($branch_data['kode_cabang'] ?? 'NULL') . ", ";
    $log_message .= "Nama: " . ($branch_data['nama_cabang'] ?? 'NULL') . ", ";
    $log_message .= "Valid: " . ($branch_data['is_valid'] ? 'YES' : 'NO') . ", ";
    $log_message .= "Source: " . ($branch_data['source'] ?? 'NONE');
    
    error_log($log_message);
}
?>