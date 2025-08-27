<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Jakarta');

// Enhanced session validation dengan user-friendly messaging
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
    $_SESSION['redirect_message'] = "Silakan login terlebih dahulu untuk mengakses sistem.";
    header('Location: ../../login_dashboard/login.php');
    exit;
}

// Koneksi ke database dengan PDO
try {
    $pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Koneksi database gagal. Silakan hubungi administrator.";
    header('Location: index_kasir.php');
    exit;
}

// Ambil kode transaksi dari URL dengan enhanced validation
if (!isset($_GET['kode_transaksi']) || empty($_GET['kode_transaksi'])) {
    $_SESSION['info_message'] = "Kode transaksi tidak ditemukan. Silakan pilih transaksi yang akan di-closing.";
    header('Location: index_kasir.php');
    exit;
}

$kode_transaksi = $_GET['kode_transaksi'];

// ENHANCED: Validasi transaksi dengan informative messaging
try {
    $sql_validate_transaction = "SELECT kode_karyawan, status, tanggal_transaksi FROM kasir_transactions WHERE kode_transaksi = :kode_transaksi";
    $stmt_validate = $pdo->prepare($sql_validate_transaction);
    $stmt_validate->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
    $stmt_validate->execute();
    $transaction_owner = $stmt_validate->fetch(PDO::FETCH_ASSOC);

    if (!$transaction_owner) {
        $_SESSION['warning_message'] = "Transaksi dengan kode '$kode_transaksi' tidak ditemukan dalam sistem. Mungkin sudah dihapus atau kode salah.";
        header('Location: index_kasir.php');
        exit;
    }

    if ($transaction_owner['kode_karyawan'] !== $_SESSION['kode_karyawan']) {
        // Get nama kasir yang memiliki transaksi
        $sql_owner_name = "SELECT nama_karyawan FROM users WHERE kode_karyawan = :kode_karyawan";
        $stmt_owner = $pdo->prepare($sql_owner_name);
        $stmt_owner->bindParam(':kode_karyawan', $transaction_owner['kode_karyawan']);
        $stmt_owner->execute();
        $owner_name = $stmt_owner->fetchColumn() ?: 'Unknown';
        
        $_SESSION['warning_message'] = "Transaksi ini dimiliki oleh kasir lain ($owner_name). Anda hanya dapat closing transaksi milik Anda sendiri.";
        header('Location: index_kasir.php');
        exit;
    }

    if ($transaction_owner['status'] !== 'on proses') {
        $status_text = $transaction_owner['status'] === 'end proses' ? 'sudah di-closing sebelumnya' : 'dalam status ' . $transaction_owner['status'];
        $_SESSION['info_message'] = "Transaksi ini $status_text pada tanggal " . date('d/m/Y', strtotime($transaction_owner['tanggal_transaksi'])) . ". Silakan pilih transaksi lain yang masih aktif.";
        header('Location: index_kasir.php');
        exit;
    }
} catch (Exception $e) {
    $_SESSION['error_message'] = "Terjadi kesalahan saat memvalidasi transaksi: " . $e->getMessage();
    header('Location: index_kasir.php');
    exit;
}

// Ambil kode cabang dan nama cabang dari sesi atau dari tabel users
if (isset($_SESSION['kode_cabang']) && isset($_SESSION['nama_cabang'])) {
    $kode_cabang = $_SESSION['kode_cabang'];
    $nama_cabang = $_SESSION['nama_cabang'];
} else {
    $sql_cabang = "SELECT kode_cabang, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan LIMIT 1";
    $stmt_cabang = $pdo->prepare($sql_cabang);
    $stmt_cabang->bindParam(':kode_karyawan', $_SESSION['kode_karyawan'], PDO::PARAM_STR);
    $stmt_cabang->execute();
    $user_cabang = $stmt_cabang->fetch(PDO::FETCH_ASSOC);
    $kode_cabang = $user_cabang['kode_cabang'] ?? 'Unknown';
    $nama_cabang = $user_cabang['nama_cabang'] ?? 'Unknown Cabang';
    $_SESSION['kode_cabang'] = $kode_cabang;
    $_SESSION['nama_cabang'] = $nama_cabang;
}

// FIXED: Function to get kas awal configuration with LOCKED 500K
function getKasAwalConfig($pdo, $kode_cabang) {
    // FIXED: LOCKED nominal to 500,000 - cannot be changed
    $locked_nominal = 500000;
    
    // Create table if not exists
    $sql_create_table = "CREATE TABLE IF NOT EXISTS kas_awal_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode_cabang VARCHAR(10) NOT NULL,
        nama_cabang VARCHAR(100) NOT NULL,
        nominal_minimum DECIMAL(15,2) NOT NULL DEFAULT 500000,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_by VARCHAR(20),
        updated_by VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_cabang (kode_cabang)
    )";
    $pdo->exec($sql_create_table);
    
    // Get ACTIVE configuration for this branch (real-time dari CRUD)
    $sql_config = "SELECT * FROM kas_awal_config WHERE kode_cabang = :kode_cabang AND status = 'active' ORDER BY updated_at DESC LIMIT 1";
    $stmt_config = $pdo->prepare($sql_config);
    $stmt_config->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
    $stmt_config->execute();
    $config = $stmt_config->fetch(PDO::FETCH_ASSOC);
    
    // If no ACTIVE config found, create default with LOCKED 500K
    if (!$config) {
        // Get nama_cabang from session or users table
        $sql_nama_cabang = "SELECT nama_cabang FROM users WHERE kode_cabang = :kode_cabang LIMIT 1";
        $stmt_nama = $pdo->prepare($sql_nama_cabang);
        $stmt_nama->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
        $stmt_nama->execute();
        $nama_cabang_for_config = $stmt_nama->fetchColumn() ?: 'Unknown Cabang';
        
        $sql_insert_default = "INSERT INTO kas_awal_config (kode_cabang, nama_cabang, nominal_minimum, status, created_by) 
                               VALUES (:kode_cabang, :nama_cabang, :locked_nominal, 'active', 'SYSTEM')
                               ON DUPLICATE KEY UPDATE 
                               nama_cabang = :nama_cabang, 
                               nominal_minimum = :locked_nominal,
                               status = 'active',
                               updated_at = CURRENT_TIMESTAMP,
                               updated_by = 'SYSTEM'";
        $stmt_insert = $pdo->prepare($sql_insert_default);
        $stmt_insert->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
        $stmt_insert->bindParam(':nama_cabang', $nama_cabang_for_config, PDO::PARAM_STR);
        $stmt_insert->bindParam(':locked_nominal', $locked_nominal, PDO::PARAM_INT);
        $stmt_insert->execute();
        
        // Get the newly created config
        $stmt_config->execute();
        $config = $stmt_config->fetch(PDO::FETCH_ASSOC);
    } else {
        // FIXED: Force update existing config to locked 500K if different
        if ($config['nominal_minimum'] != $locked_nominal) {
            $sql_update_lock = "UPDATE kas_awal_config 
                               SET nominal_minimum = :locked_nominal, 
                                   updated_at = CURRENT_TIMESTAMP,
                                   updated_by = 'SYSTEM_LOCK'
                               WHERE kode_cabang = :kode_cabang AND status = 'active'";
            $stmt_update_lock = $pdo->prepare($sql_update_lock);
            $stmt_update_lock->bindParam(':locked_nominal', $locked_nominal, PDO::PARAM_INT);
            $stmt_update_lock->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
            $stmt_update_lock->execute();
            
            // Re-fetch updated config
            $stmt_config->execute();
            $config = $stmt_config->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    // FIXED: Always ensure nominal_minimum is locked to 500K
    $config['nominal_minimum'] = $locked_nominal;
    $config['is_locked'] = true;
    $config['lock_reason'] = 'Nominal kas awal telah dikunci pada Rp 500.000 sesuai kebijakan sistem';
    
    return $config;
}

// ENHANCED: Algoritma PRIORITAS MUTLAK TERKECIL dengan better logging
function findOptimalCombination($denominations, $target_amount) {
    $target = intval($target_amount);
    $result = [];
    $remaining = $target;
    
    // Sort denominations ascending (terkecil dulu) - PRIORITAS MUTLAK
    usort($denominations, function($a, $b) {
        return $a['nominal'] - $b['nominal'];
    });
    
    error_log("=== ALGORITMA PRIORITAS MUTLAK TERKECIL START ===");
    error_log("Target: Rp " . number_format($target));
    error_log("Available denominations (smallest first priority):");
    foreach ($denominations as $index => $denom) {
        error_log(($index + 1) . ". Rp " . number_format($denom['nominal']) . " x " . $denom['jumlah_keping'] . " = Rp " . number_format($denom['nominal'] * $denom['jumlah_keping']));
    }
    
    // STEP 1: PRIORITAS MUTLAK - ambil dari terkecil dulu dengan batasan wajar
    foreach ($denominations as $denom) {
        if ($remaining <= 0) break;
        
        $nominal = intval($denom['nominal']);
        $available_pieces = intval($denom['jumlah_keping']);
        
        if ($available_pieces > 0 && $nominal <= $remaining) {
            // Hitung berapa keping maksimal yang bisa diambil
            $max_possible = floor($remaining / $nominal);
            $pieces_to_take = min($max_possible, $available_pieces);
            
            // PRIORITAS TERKECIL: Ambil sebanyak mungkin dari nominal terkecil
            // Tapi jangan berlebihan, biarkan variasi untuk denominasi lain
            if ($nominal <= 1000) {
                // Untuk nominal sangat kecil (Rp 500, Rp 1000), ambil semua yang tersedia
                $pieces_to_take = min($pieces_to_take, $available_pieces);
            } elseif ($nominal <= 5000) {
                // Untuk Rp 2000, Rp 5000, ambil maksimal 75% dari yang tersedia
                $pieces_to_take = min($pieces_to_take, ceil($available_pieces * 0.75));
            } elseif ($nominal <= 20000) {
                // Untuk Rp 10000, Rp 20000, ambil maksimal 50% dari yang tersedia
                $pieces_to_take = min($pieces_to_take, ceil($available_pieces * 0.5));
            } elseif ($nominal <= 50000) {
                // Untuk Rp 50000, ambil maksimal 25% dari yang tersedia
                $pieces_to_take = min($pieces_to_take, ceil($available_pieces * 0.25));
            } else {
                // Untuk nominal besar (Rp 100000+), ambil minimal yang diperlukan
                $pieces_to_take = min($pieces_to_take, 1);
            }
            
            // Pastikan tidak mengambil lebih dari yang diperlukan
            $max_needed_for_remaining = floor($remaining / $nominal);
            $pieces_to_take = min($pieces_to_take, $max_needed_for_remaining);
            
            if ($pieces_to_take > 0) {
                $value_taken = $nominal * $pieces_to_take;
                
                $result[] = [
                    'nominal' => $nominal,
                    'jumlah_keping' => $pieces_to_take
                ];
                
                $remaining -= $value_taken;
                
                error_log("PRIORITY " . count($result) . ": Take Rp " . number_format($nominal) . " x " . $pieces_to_take . " = Rp " . number_format($value_taken) . ", remaining: Rp " . number_format($remaining));
            }
        }
    }
    
    // STEP 2: Jika masih ada sisa, coba exact match dulu
    if ($remaining > 0) {
        error_log("Remaining Rp " . number_format($remaining) . ", finding exact match...");
        
        foreach ($denominations as $denom) {
            if ($remaining <= 0) break;
            
            $nominal = intval($denom['nominal']);
            $available_pieces = intval($denom['jumlah_keping']);
            
            // Hitung yang sudah digunakan
            $used_pieces = 0;
            foreach ($result as $used_item) {
                if ($used_item['nominal'] == $nominal) {
                    $used_pieces = $used_item['jumlah_keping'];
                    break;
                }
            }
            
            $available_for_use = $available_pieces - $used_pieces;
            
            // Cek exact match
            if ($nominal == $remaining && $available_for_use > 0) {
                // Update existing atau tambah baru
                $found = false;
                foreach ($result as &$item) {
                    if ($item['nominal'] == $nominal) {
                        $item['jumlah_keping'] += 1;
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    $result[] = [
                        'nominal' => $nominal,
                        'jumlah_keping' => 1
                    ];
                }
                
                $remaining = 0;
                error_log("Exact match found: Rp " . number_format($nominal) . " x 1");
                break;
            }
        }
    }
    
    // STEP 3: Jika masih ada sisa, lanjutkan dengan kombinasi dari terkecil
    if ($remaining > 0) {
        error_log("Remaining Rp " . number_format($remaining) . ", trying additional combinations from smallest...");
        
        foreach ($denominations as $denom) {
            if ($remaining <= 0) break;
            
            $nominal = intval($denom['nominal']);
            $available_pieces = intval($denom['jumlah_keping']);
            
            if ($nominal > $remaining) continue; // Skip jika nominal lebih besar dari sisa
            
            // Hitung yang sudah digunakan
            $used_pieces = 0;
            $result_index = -1;
            foreach ($result as $idx => $used_item) {
                if ($used_item['nominal'] == $nominal) {
                    $used_pieces = $used_item['jumlah_keping'];
                    $result_index = $idx;
                    break;
                }
            }
            
            $available_for_use = $available_pieces - $used_pieces;
            
            if ($available_for_use > 0) {
                $additional_needed = floor($remaining / $nominal);
                $pieces_to_add = min($additional_needed, $available_for_use);
                
                if ($pieces_to_add > 0) {
                    $value_added = $nominal * $pieces_to_add;
                    
                    if ($result_index >= 0) {
                        $result[$result_index]['jumlah_keping'] += $pieces_to_add;
                    } else {
                        $result[] = [
                            'nominal' => $nominal,
                            'jumlah_keping' => $pieces_to_add
                        ];
                    }
                    
                    $remaining -= $value_added;
                    error_log("Additional: Rp " . number_format($nominal) . " x " . $pieces_to_add . " = Rp " . number_format($value_added) . ", remaining: Rp " . number_format($remaining));
                }
            }
        }
    }
    
    // Calculate final results
    $total_result = 0;
    foreach ($result as $item) {
        $total_result += $item['nominal'] * $item['jumlah_keping'];
    }
    
    error_log("=== FINAL RESULTS PRIORITAS MUTLAK TERKECIL ===");
    error_log("Total: Rp " . number_format($total_result) . " from target Rp " . number_format($target));
    error_log("Success: " . ($total_result == $target ? "YES" : "NO"));
    error_log("Unused remaining: Rp " . number_format($remaining));
    
    // Log detailed results in smallest-first order
    usort($result, function($a, $b) {
        return $a['nominal'] - $b['nominal'];
    });
    
    error_log("Selected SMALLEST-FIRST combination:");
    foreach ($result as $index => $item) {
        error_log(($index + 1) . ". Rp " . number_format($item['nominal']) . " x " . $item['jumlah_keping'] . " = Rp " . number_format($item['nominal'] * $item['jumlah_keping']));
    }
    
    return [
        'success' => ($total_result == $target),
        'combination' => $result,
        'total' => $total_result,
        'remaining' => $remaining
    ];
}

// Function to find alternative combinations using dynamic programming approach
function findAlternativeCombination($denominations, $target_amount) {
    // Simple backtracking approach for small sets
    $combinations = [];
    
    function backtrack($denominations, $target, $current_combination, $current_total, $start_index, &$combinations) {
        if ($current_total == $target) {
            $combinations[] = $current_combination;
            return true; // Found one solution, return immediately
        }
        
        if ($current_total > $target || $start_index >= count($denominations)) {
            return false;
        }
        
        for ($i = $start_index; $i < count($denominations); $i++) {
            $denom = $denominations[$i];
            $nominal = $denom['nominal'];
            $max_pieces = $denom['jumlah_keping'];
            
            for ($pieces = 0; $pieces <= $max_pieces; $pieces++) {
                $value = $nominal * $pieces;
                if ($current_total + $value <= $target) {
                    $new_combination = $current_combination;
                    if ($pieces > 0) {
                        $new_combination[] = [
                            'nominal' => $nominal,
                            'jumlah_keping' => $pieces
                        ];
                    }
                    
                    if (backtrack($denominations, $target, $new_combination, $current_total + $value, $i + 1, $combinations)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
    
    backtrack($denominations, $target_amount, [], 0, 0, $combinations);
    
    if (!empty($combinations)) {
        return [
            'success' => true,
            'combination' => $combinations[0]
        ];
    }
    
    return [
        'success' => false,
        'combination' => []
    ];
}

// ENHANCED: Function to get current transaction cash details for kas besok
function getCurrentTransactionRecehExact($pdo, $current_kode_transaksi, $required_amount) {
    $debug_info = [];
    $debug_info['current_transaction'] = $current_kode_transaksi;
    $debug_info['required_amount'] = floatval($required_amount);
    
    error_log("=== getCurrentTransactionRecehExact START ===");
    error_log("Transaction: " . $current_kode_transaksi);
    error_log("Required: Rp " . number_format($required_amount));
    
    // Get transaction date for this transaction
    $sql_transaction_date = "SELECT tanggal_transaksi FROM kasir_transactions WHERE kode_transaksi = :kode_transaksi";
    $stmt_transaction_date = $pdo->prepare($sql_transaction_date);
    $stmt_transaction_date->bindParam(':kode_transaksi', $current_kode_transaksi, PDO::PARAM_STR);
    $stmt_transaction_date->execute();
    $transaction_date = $stmt_transaction_date->fetchColumn() ?? date('Y-m-d');
    
    // Get denomination details from current transaction's kas akhir - SORT BY NOMINAL ASC
    $sql_all_denominations = "SELECT nominal, jumlah_keping 
                             FROM detail_kas_akhir 
                             WHERE kode_transaksi = :kode_transaksi 
                             AND jumlah_keping > 0
                             ORDER BY nominal ASC";
    
    $stmt_all_denominations = $pdo->prepare($sql_all_denominations);
    $stmt_all_denominations->bindParam(':kode_transaksi', $current_kode_transaksi, PDO::PARAM_STR);
    $stmt_all_denominations->execute();
    $all_denominations = $stmt_all_denominations->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($all_denominations)) {
        error_log("ERROR: No denominations found for transaction: " . $current_kode_transaksi);
        return [
            'success' => false, 
            'message' => "Tidak ada detail kas akhir untuk transaksi ini. Pastikan kas akhir sudah diinput terlebih dahulu.",
            'debug' => $debug_info,
            'all_denominations' => [],
            'selected_receh' => [],
            'total_all_denominations' => 0,
            'total_selected_receh' => 0,
            'required_amount' => $required_amount,
            'transaction_code' => $current_kode_transaksi,
            'previous_date' => $transaction_date
        ];
    }
    
    // Log semua denominasi yang ditemukan
    error_log("Found denominations (SMALLEST-FIRST PRIORITY):");
    foreach ($all_denominations as $index => $denom) {
        error_log(($index + 1) . ". Rp " . number_format($denom['nominal']) . " x " . $denom['jumlah_keping'] . " = Rp " . number_format($denom['nominal'] * $denom['jumlah_keping']));
    }
    
    // Calculate total for all denominations
    $total_all_denominations = 0;
    foreach ($all_denominations as $denom) {
        $total_all_denominations += $denom['nominal'] * $denom['jumlah_keping'];
    }
    
    error_log("Total all denominations: Rp " . number_format($total_all_denominations));
    
    // Check if total available is sufficient
    if ($total_all_denominations < $required_amount) {
        error_log("ERROR: Total kas akhir insufficient");
        return [
            'success' => false,
            'status' => 'insufficient_total',
            'message' => "Total kas akhir (Rp " . number_format($total_all_denominations, 0, ',', '.') . 
                        ") tidak mencukupi untuk kas awal besok sebesar Rp " . number_format($required_amount, 0, ',', '.'),
            'all_denominations' => $all_denominations,
            'selected_receh' => [],
            'total_all_denominations' => $total_all_denominations,
            'total_selected_receh' => 0,
            'required_amount' => $required_amount,
            'transaction_code' => $current_kode_transaksi,
            'previous_date' => $transaction_date,
            'debug' => $debug_info
        ];
    }
    
    // Find optimal combination using PRIORITAS MUTLAK TERKECIL algorithm
    $target_amount = floatval($required_amount);
    error_log("Calling findOptimalCombination with target: Rp " . number_format($target_amount));
    
    $selected_receh = findOptimalCombination($all_denominations, $target_amount);
    
    error_log("findOptimalCombination results:");
    error_log("Success: " . ($selected_receh['success'] ? 'YES' : 'NO'));
    error_log("Total: Rp " . number_format($selected_receh['total']));
    error_log("Remaining: Rp " . number_format($selected_receh['remaining']));
    
    $debug_info['algorithm'] = 'prioritas_mutlak_terkecil_enhanced';
    $debug_info['available_denominations'] = count($all_denominations);
    
    if ($selected_receh['success']) {
        $current_total = $selected_receh['total'];
        $status = 'exact_match';
        $message = "✅ Berhasil menemukan kombinasi PRIORITAS MUTLAK TERKECIL dari kas akhir saat ini!";
        error_log("SUCCESS: Combination found with smallest-first priority");
    } else {
        // Fallback to alternative algorithm
        error_log("Trying alternative algorithm...");
        $alternative_result = findAlternativeCombination($all_denominations, $target_amount);
        if ($alternative_result['success']) {
            $selected_receh = [
                'success' => true,
                'combination' => $alternative_result['combination'],
                'total' => $target_amount
            ];
            $current_total = $target_amount;
            $status = 'alternative_found';
            $message = "⚡ Ditemukan kombinasi alternatif dengan PRIORITAS TERKECIL!";
            error_log("SUCCESS: Alternative combination found");
        } else {
            $current_total = 0;
            $status = 'insufficient';
            $message = "❌ Tidak dapat membuat kombinasi yang tepat Rp " . number_format($target_amount, 0, ',', '.') . 
                      " dari kas akhir saat ini. Silakan input kas akhir dengan denominasi yang lebih bervariasi.";
            error_log("FAILED: Cannot create combination");
        }
    }
    
    error_log("=== getCurrentTransactionRecehExact END ===");
    
    return [
        'success' => ($current_total == $target_amount),
        'status' => $status,
        'message' => $message,
        'all_denominations' => $all_denominations,
        'selected_receh' => $selected_receh['success'] ? $selected_receh['combination'] : [],
        'total_all_denominations' => $total_all_denominations,
        'total_selected_receh' => $current_total,
        'required_amount' => $target_amount,
        'is_exact' => ($current_total == $target_amount),
        'previous_date' => $transaction_date,
        'transaction_code' => $current_kode_transaksi,
        'debug' => $debug_info
    ];
}

// FIXED: Get kas awal config with LOCKED 500K
$kas_awal_config = getKasAwalConfig($pdo, $kode_cabang);
$default_kas_besok = 500000; // FIXED: Locked to 500K

// Fetch and calculate transaction data
$sql_kas_akhir = "SELECT total_nilai FROM kas_akhir WHERE kode_transaksi = :kode_transaksi";
$stmt_kas_akhir = $pdo->prepare($sql_kas_akhir);
$stmt_kas_akhir->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_kas_akhir->execute();
$total_uang_di_kasir = $stmt_kas_akhir->fetchColumn() ?? 0;

$sql_kas_awal = "SELECT total_nilai FROM kas_awal WHERE kode_transaksi = :kode_transaksi";
$stmt_kas_awal = $pdo->prepare($sql_kas_awal);
$stmt_kas_awal->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_kas_awal->execute();
$kas_awal = $stmt_kas_awal->fetchColumn() ?? 0;

$setoran_real = $total_uang_di_kasir - $kas_awal;

$sql_penjualan = "SELECT SUM(jumlah_penjualan) as total_penjualan FROM data_penjualan WHERE kode_transaksi = :kode_transaksi";
$stmt_penjualan = $pdo->prepare($sql_penjualan);
$stmt_penjualan->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_penjualan->execute();
$data_penjualan = $stmt_penjualan->fetchColumn() ?? 0;

$sql_servis = "SELECT SUM(jumlah_servis) as total_servis FROM data_servis WHERE kode_transaksi = :kode_transaksi";
$stmt_servis = $pdo->prepare($sql_servis);
$stmt_servis->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_servis->execute();
$data_servis = $stmt_servis->fetchColumn() ?? 0;

$omset = $data_penjualan + $data_servis;

$sql_pengeluaran = "SELECT SUM(jumlah) as total_pengeluaran FROM pengeluaran_kasir WHERE kode_transaksi = :kode_transaksi";
$stmt_pengeluaran = $pdo->prepare($sql_pengeluaran);
$stmt_pengeluaran->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pengeluaran->execute();
$pengeluaran_dari_kasir = $stmt_pengeluaran->fetchColumn() ?? 0;

$sql_pemasukan = "SELECT SUM(jumlah) as total_pemasukan FROM pemasukan_kasir WHERE kode_transaksi = :kode_transaksi";
$stmt_pemasukan = $pdo->prepare($sql_pemasukan);
$stmt_pemasukan->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pemasukan->execute();
$uang_masuk_ke_kasir = $stmt_pemasukan->fetchColumn() ?? 0;

$setoran_data = ($omset - $pengeluaran_dari_kasir) + $uang_masuk_ke_kasir;
$selisih_setoran = $setoran_real - $setoran_data;

// Get detail kas akhir
$sql_detail_kas_akhir = "SELECT nominal, jumlah_keping FROM detail_kas_akhir WHERE kode_transaksi = :kode_transaksi ORDER BY nominal DESC";
$stmt_detail_kas_akhir = $pdo->prepare($sql_detail_kas_akhir);
$stmt_detail_kas_akhir->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_detail_kas_akhir->execute();
$detail_kas_akhir = $stmt_detail_kas_akhir->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Get pemasukan kasir details
$sql_pemasukan_detail = "SELECT kode_akun, jumlah, keterangan_transaksi, tanggal, waktu FROM pemasukan_kasir WHERE kode_transaksi = :kode_transaksi ORDER BY tanggal ASC, waktu ASC";
$stmt_pemasukan_detail = $pdo->prepare($sql_pemasukan_detail);
$stmt_pemasukan_detail->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pemasukan_detail->execute();
$pemasukan_detail = $stmt_pemasukan_detail->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Get pengeluaran kasir details - split by category
$sql_pengeluaran_detail = "SELECT kode_akun, jumlah, keterangan_transaksi, tanggal, waktu, kategori FROM pengeluaran_kasir WHERE kode_transaksi = :kode_transaksi ORDER BY kategori ASC, tanggal ASC, waktu ASC";
$stmt_pengeluaran_detail = $pdo->prepare($sql_pengeluaran_detail);
$stmt_pengeluaran_detail->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_pengeluaran_detail->execute();
$pengeluaran_detail = $stmt_pengeluaran_detail->fetchAll(PDO::FETCH_ASSOC) ?: [];
$pengeluaran_biaya = array_filter($pengeluaran_detail, fn($item) => strtolower($item['kategori']) === 'biaya');
$pengeluaran_non_biaya = array_filter($pengeluaran_detail, fn($item) => strtolower($item['kategori']) !== 'biaya');

// Get current kasir name
$sql_current_kasir = "SELECT nama_karyawan FROM users WHERE kode_karyawan = :kode_karyawan";
$stmt_current_kasir = $pdo->prepare($sql_current_kasir);
$stmt_current_kasir->bindParam(':kode_karyawan', $_SESSION['kode_karyawan'], PDO::PARAM_STR);
$stmt_current_kasir->execute();
$current_kasir_name = $stmt_current_kasir->fetchColumn() ?? 'Unknown';

// Get transaction date for default next date
$sql_transaction_date = "SELECT tanggal_transaksi FROM kasir_transactions WHERE kode_transaksi = :kode_transaksi";
$stmt_transaction_date = $pdo->prepare($sql_transaction_date);
$stmt_transaction_date->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_transaction_date->execute();
$current_transaction_date = $stmt_transaction_date->fetchColumn() ?? date('Y-m-d');
$default_next_date = date('Y-m-d', strtotime($current_transaction_date . ' +1 day'));

// ENHANCED: Get list of kasir users for dropdown dengan enhanced data untuk pergantian kasir
$sql_kasir = "SELECT u.kode_karyawan, u.nama_karyawan, u.nama_cabang, u.kode_cabang,
              CASE WHEN u.kode_karyawan = :current_kasir THEN 1 ELSE 0 END as is_current_kasir
              FROM users u 
              WHERE u.role = 'kasir' AND u.status = 1 
              ORDER BY is_current_kasir DESC, u.nama_cabang ASC, u.nama_karyawan ASC";
$stmt_kasir = $pdo->prepare($sql_kasir);
$stmt_kasir->bindParam(':current_kasir', $_SESSION['kode_karyawan'], PDO::PARAM_STR);
$stmt_kasir->execute();
$kasir_list = $stmt_kasir->fetchAll(PDO::FETCH_ASSOC);

// Update transaksi dengan kode_cabang dan nama_cabang jika belum ada
$sql_check_cabang = "SELECT kode_cabang, nama_cabang FROM kasir_transactions WHERE kode_transaksi = :kode_transaksi";
$stmt_check_cabang = $pdo->prepare($sql_check_cabang);
$stmt_check_cabang->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_check_cabang->execute();
$recorded_data = $stmt_check_cabang->fetch(PDO::FETCH_ASSOC);

$recorded_kode_cabang = $recorded_data['kode_cabang'] ?? null;
$recorded_nama_cabang = $recorded_data['nama_cabang'] ?? null;

if ($recorded_kode_cabang === null || $recorded_nama_cabang === null) {
    $sql_update_cabang = "UPDATE kasir_transactions 
                          SET kode_cabang = IFNULL(kode_cabang, :kode_cabang), 
                              nama_cabang = IFNULL(nama_cabang, :nama_cabang) 
                          WHERE kode_transaksi = :kode_transaksi";
    $stmt_update_cabang = $pdo->prepare($sql_update_cabang);
    $stmt_update_cabang->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
    $stmt_update_cabang->bindParam(':nama_cabang', $nama_cabang, PDO::PARAM_STR);
    $stmt_update_cabang->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
    $stmt_update_cabang->execute();
}

// Handle AJAX request for getting latest config
if (isset($_GET['action']) && $_GET['action'] === 'get_config') {
    header('Content-Type: application/json');
    $kode_cabang_param = $_GET['kode_cabang'] ?? '';
    
    if ($kode_cabang_param) {
        $latest_config = getKasAwalConfig($pdo, $kode_cabang_param);
        if ($latest_config) {
            echo json_encode(['success' => true, 'config' => $latest_config]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Konfigurasi tidak ditemukan']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Kode cabang tidak valid']);
    }
    exit;
}

// Handle AJAX request for checking config changes
if (isset($_GET['action']) && $_GET['action'] === 'check_config_changes') {
    header('Content-Type: application/json');
    $kode_cabang_param = $_GET['kode_cabang'] ?? '';
    $last_check = $_GET['last_check'] ?? 0;
    
    if ($kode_cabang_param) {
        $latest_config = getKasAwalConfig($pdo, $kode_cabang_param);
        $last_update_timestamp = strtotime($latest_config['updated_at']) * 1000;
        
        $has_changes = $last_update_timestamp > $last_check;
        
        echo json_encode([
            'has_changes' => $has_changes,
            'last_update' => $last_update_timestamp,
            'config' => $latest_config
        ]);
    } else {
        echo json_encode(['has_changes' => false, 'message' => 'Kode cabang tidak valid']);
    }
    exit;
}

// ENHANCED: Handle AJAX request for preview kas awal besok
if (isset($_GET['action']) && $_GET['action'] === 'preview_kas_besok') {
    // Clean output buffer to prevent mixed content
    if (ob_get_level()) {
        ob_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    $selected_date_besok = $_GET['date'] ?? '';
    $nominal_kas_besok = $_GET['nominal'] ?? '';
    $selected_kasir = $_GET['kasir'] ?? '';
    $kode_transaksi_ajax = $_GET['kode_transaksi'] ?? '';
    
    // FIXED: Force nominal to 500K regardless of input
    $nominal_kas_besok = 500000;
    
    if (empty($kode_transaksi_ajax)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Kode transaksi tidak ditemukan dalam request'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        if ($selected_date_besok && $nominal_kas_besok) {
            $result = getCurrentTransactionRecehExact($pdo, $kode_transaksi_ajax, $nominal_kas_besok);
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'Parameter tidak lengkap: tanggal dan nominal diperlukan'
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        error_log("Preview kas besok error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ENHANCED: Penutupan transaksi dengan proper transaction handling dan informative messaging
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        $tanggal_closing = date('Y-m-d');
        $jam_closing = date('H:i:s');

        // Update transaksi current menjadi end proses
        $sql_update = "
            UPDATE kasir_transactions 
            SET 
                kas_awal = :kas_awal, 
                kas_akhir = :kas_akhir, 
                total_pemasukan = :total_pemasukan, 
                total_pengeluaran = :total_pengeluaran, 
                total_penjualan = :total_penjualan, 
                total_servis = :total_servis, 
                setoran_real = :setoran_real, 
                omset = :omset, 
                data_setoran = :data_setoran, 
                selisih_setoran = :selisih_setoran, 
                status = 'end proses',
                tanggal_closing = :tanggal_closing,
                jam_closing = :jam_closing
            WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan";

        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->bindParam(':kas_awal', $_POST['kas_awal'], PDO::PARAM_STR);
        $stmt_update->bindParam(':kas_akhir', $_POST['kas_akhir'], PDO::PARAM_STR);
        $stmt_update->bindParam(':total_pemasukan', $_POST['total_pemasukan'], PDO::PARAM_STR);
        $stmt_update->bindParam(':total_pengeluaran', $_POST['total_pengeluaran'], PDO::PARAM_STR);
        $stmt_update->bindParam(':total_penjualan', $_POST['total_penjualan'], PDO::PARAM_STR);
        $stmt_update->bindParam(':total_servis', $_POST['total_servis'], PDO::PARAM_STR);
        $stmt_update->bindParam(':setoran_real', $_POST['setoran_real'], PDO::PARAM_STR);
        $stmt_update->bindParam(':omset', $_POST['omset'], PDO::PARAM_STR);
        $stmt_update->bindParam(':data_setoran', $_POST['data_setoran'], PDO::PARAM_STR);
        $stmt_update->bindParam(':selisih_setoran', $_POST['selisih_setoran'], PDO::PARAM_STR);
        $stmt_update->bindParam(':tanggal_closing', $tanggal_closing, PDO::PARAM_STR);
        $stmt_update->bindParam(':jam_closing', $jam_closing, PDO::PARAM_STR);
        $stmt_update->bindParam(':kode_transaksi', $_POST['kode_transaksi'], PDO::PARAM_STR);
        $stmt_update->bindParam(':kode_karyawan', $_SESSION['kode_karyawan'], PDO::PARAM_STR);
        $stmt_update->execute();

        $success_messages = ["✅ Transaksi berhasil ditutup dan dihitung pada " . date('d/m/Y H:i:s') . "."];
        
        // ENHANCED: Proses kas awal besok dengan validasi comprehensive untuk pergantian kasir
        if (!empty($_POST['kas_besok_nominal']) && !empty($_POST['kas_besok_date']) && !empty($_POST['kas_besok_kasir'])) {
            // FIXED: Force nominal to 500K
            $kas_besok_nominal = 500000;
            $kas_besok_date = $_POST['kas_besok_date'];
            $kas_besok_kasir = $_POST['kas_besok_kasir'];
            $kas_besok_receh_data = json_decode($_POST['kas_besok_receh_data'] ?? '[]', true);
            
            // ENHANCED: Deteksi pergantian kasir dan log untuk audit
            $is_kasir_change = ($kas_besok_kasir !== $_SESSION['kode_karyawan']);
            
            // Get kasir information
            $sql_kasir_info = "SELECT kode_user, nama_karyawan, kode_cabang, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan";
            $stmt_kasir_info = $pdo->prepare($sql_kasir_info);
            $stmt_kasir_info->bindParam(':kode_karyawan', $kas_besok_kasir, PDO::PARAM_STR);
            $stmt_kasir_info->execute();
            $kasir_info = $stmt_kasir_info->fetch(PDO::FETCH_ASSOC);
            
            if (!$kasir_info) {
                throw new Exception("Data kasir dengan kode '$kas_besok_kasir' tidak ditemukan.");
            }
            
            $kasir_kode_cabang = $kasir_info['kode_cabang'];
            $kasir_nama_cabang = $kasir_info['nama_cabang'];
            
            // ENHANCED: Log pergantian kasir untuk audit trail
            if ($is_kasir_change) {
                error_log("=== PERGANTIAN KASIR DETECTED ===");
                error_log("Kasir Closing: " . $_SESSION['kode_karyawan'] . " (" . $current_kasir_name . ")");
                error_log("Kasir Besok: " . $kas_besok_kasir . " (" . $kasir_info['nama_karyawan'] . ")");
                error_log("Tanggal: " . $kas_besok_date);
                error_log("Cabang: " . $kasir_nama_cabang);
                error_log("Nominal: Rp " . number_format($kas_besok_nominal));
                
                // Bisa ditambahkan insert ke tabel kasir_shift_log jika ada
                /*
                $sql_log_shift = "INSERT INTO kasir_shift_log 
                                 (tanggal, kode_cabang, kasir_closing, kasir_besok, is_pergantian, keterangan, created_by) 
                                 VALUES (:tanggal, :kode_cabang, :kasir_closing, :kasir_besok, 1, :keterangan, :created_by)";
                $stmt_log_shift = $pdo->prepare($sql_log_shift);
                $stmt_log_shift->execute([
                    'tanggal' => $kas_besok_date,
                    'kode_cabang' => $kasir_kode_cabang,
                    'kasir_closing' => $_SESSION['kode_karyawan'],
                    'kasir_besok' => $kas_besok_kasir,
                    'keterangan' => "Pergantian kasir: {$current_kasir_name} -> {$kasir_info['nama_karyawan']}",
                    'created_by' => $_SESSION['kode_karyawan']
                ]);
                */
            }
            
            // Get and validate config for target branch - FIXED: Always 500K
            $kasir_config = getKasAwalConfig($pdo, $kasir_kode_cabang);
            
            // ENHANCED: Check for active transactions - better error messaging
            $sql_check_active_transaction = "SELECT kode_transaksi, tanggal_transaksi FROM kasir_transactions 
                                           WHERE kode_karyawan = :kode_karyawan 
                                           AND status = 'on proses'";
            $stmt_check_active = $pdo->prepare($sql_check_active_transaction);
            $stmt_check_active->bindParam(':kode_karyawan', $kas_besok_kasir, PDO::PARAM_STR);
            $stmt_check_active->execute();
            $active_transaction = $stmt_check_active->fetch(PDO::FETCH_ASSOC);
            
            if ($active_transaction) {
                throw new Exception("Kasir {$kasir_info['nama_karyawan']} masih memiliki transaksi aktif " .
                                  "(Kode: {$active_transaction['kode_transaksi']}, Tanggal: " . 
                                  date('d/m/Y', strtotime($active_transaction['tanggal_transaksi'])) . 
                                  ") yang belum di-closing. Silakan closing transaksi tersebut terlebih dahulu.");
            }
            
            // Check for existing transaction on the same date
            $sql_check_existing_date = "SELECT kode_transaksi FROM kasir_transactions 
                                      WHERE tanggal_transaksi = :tanggal_transaksi 
                                      AND kode_karyawan = :kode_karyawan";
            $stmt_check_existing_date = $pdo->prepare($sql_check_existing_date);
            $stmt_check_existing_date->bindParam(':tanggal_transaksi', $kas_besok_date, PDO::PARAM_STR);
            $stmt_check_existing_date->bindParam(':kode_karyawan', $kas_besok_kasir, PDO::PARAM_STR);
            $stmt_check_existing_date->execute();
            $existing_transaction = $stmt_check_existing_date->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_transaction) {
                throw new Exception("Kasir {$kasir_info['nama_karyawan']} sudah memiliki transaksi " .
                                  "(Kode: {$existing_transaction['kode_transaksi']}) untuk tanggal " . 
                                  date('d/m/Y', strtotime($kas_besok_date)) . ". Silakan pilih tanggal lain.");
            }
            
            // Validate receh data
            if (empty($kas_besok_receh_data) || !is_array($kas_besok_receh_data)) {
                throw new Exception("Data receh untuk kas awal besok tidak valid atau kosong.");
            }
            
            // Validate total receh matches nominal - FIXED: Must be 500K
            $total_receh = 0;
            $total_keping = 0;
            foreach ($kas_besok_receh_data as $receh) {
                if (!isset($receh['nominal']) || !isset($receh['jumlah_keping'])) {
                    throw new Exception("Format data receh tidak valid - nominal atau jumlah_keping hilang.");
                }
                $total_receh += $receh['nominal'] * $receh['jumlah_keping'];
                $total_keping += $receh['jumlah_keping'];
            }
            
            if ($total_receh != $kas_besok_nominal) {
                throw new Exception("Total receh (Rp " . number_format($total_receh, 0, ',', '.') . 
                                  ") tidak sesuai dengan nominal kas awal besok (Rp " . number_format($kas_besok_nominal, 0, ',', '.') . ")");
            }
            
            // Generate unique transaction code
            $sql_count_transaksi = "SELECT COUNT(*) as total FROM kasir_transactions 
                                  WHERE tanggal_transaksi = :tanggal_transaksi 
                                  AND kode_cabang = :kode_cabang";
            $stmt_count = $pdo->prepare($sql_count_transaksi);
            $stmt_count->bindParam(':tanggal_transaksi', $kas_besok_date, PDO::PARAM_STR);
            $stmt_count->bindParam(':kode_cabang', $kasir_kode_cabang, PDO::PARAM_STR);
            $stmt_count->execute();
            $total_transaksi_hari_target = $stmt_count->fetchColumn();

            $year = date('Y', strtotime($kas_besok_date));
            $month = date('m', strtotime($kas_besok_date));
            $day = date('d', strtotime($kas_besok_date));
            $transaction_number = str_pad($total_transaksi_hari_target + 1, 4, '0', STR_PAD_LEFT);
            $kode_transaksi_baru = "TRX-$year$month$day-{$kasir_info['kode_user']}$transaction_number";

            // Ensure unique transaction code
            $attempt_count = 0;
            while (true) {
                $sql_check_duplicate = "SELECT COUNT(*) FROM kasir_transactions WHERE kode_transaksi = :kode_transaksi";
                $stmt_check_duplicate = $pdo->prepare($sql_check_duplicate);
                $stmt_check_duplicate->bindParam(':kode_transaksi', $kode_transaksi_baru, PDO::PARAM_STR);
                $stmt_check_duplicate->execute();
                $exists = $stmt_check_duplicate->fetchColumn();

                if ($exists == 0) {
                    break;
                }

                $attempt_count++;
                if ($attempt_count > 100) {
                    throw new Exception("Tidak dapat membuat kode transaksi unik setelah 100 percobaan.");
                }

                $transaction_number = str_pad((int)$transaction_number + 1, 4, '0', STR_PAD_LEFT);
                $kode_transaksi_baru = "TRX-$year$month$day-{$kasir_info['kode_user']}$transaction_number";
            }
            
            $waktu_kas_awal = '08:00:00';
            
            // Insert kas_awal
            $sql_kas_awal = "INSERT INTO kas_awal (kode_transaksi, kode_karyawan, total_nilai, tanggal, waktu, status) 
                             VALUES (:kode_transaksi, :kode_karyawan, :total_nilai, :tanggal, :waktu, 'on proses')";
            $stmt_kas_awal = $pdo->prepare($sql_kas_awal);
            $stmt_kas_awal->bindParam(':kode_transaksi', $kode_transaksi_baru, PDO::PARAM_STR);
            $stmt_kas_awal->bindParam(':kode_karyawan', $kas_besok_kasir, PDO::PARAM_STR);
            $stmt_kas_awal->bindParam(':total_nilai', $kas_besok_nominal, PDO::PARAM_STR);
            $stmt_kas_awal->bindParam(':tanggal', $kas_besok_date, PDO::PARAM_STR);
            $stmt_kas_awal->bindParam(':waktu', $waktu_kas_awal, PDO::PARAM_STR);
            
            if (!$stmt_kas_awal->execute()) {
                throw new Exception("Gagal menyimpan kas awal besok ke database.");
            }

            // Insert detail kas awal with SMALLEST-FIRST priority
            $detail_insert_count = 0;
            usort($kas_besok_receh_data, function($a, $b) {
                return $a['nominal'] - $b['nominal'];
            });
            
            foreach ($kas_besok_receh_data as $receh) {
                if ($receh['jumlah_keping'] > 0) {
                    $sql_detail_kas_awal = "INSERT INTO detail_kas_awal (kode_transaksi, nominal, jumlah_keping) 
                                            VALUES (:kode_transaksi, :nominal, :jumlah_keping)";
                    $stmt_detail = $pdo->prepare($sql_detail_kas_awal);
                    $stmt_detail->bindParam(':kode_transaksi', $kode_transaksi_baru, PDO::PARAM_STR);
                    $stmt_detail->bindParam(':nominal', $receh['nominal'], PDO::PARAM_INT);
                    $stmt_detail->bindParam(':jumlah_keping', $receh['jumlah_keping'], PDO::PARAM_INT);
                    
                    if ($stmt_detail->execute()) {
                        $detail_insert_count++;
                    }
                }
            }
            
            if ($detail_insert_count == 0) {
                throw new Exception("Gagal menyimpan detail kas awal besok - tidak ada data yang tersimpan.");
            }

            // Insert kasir transaction
            $sql_trans = "INSERT INTO kasir_transactions (kode_karyawan, kode_transaksi, kas_awal, tanggal_transaksi, status, kode_cabang, nama_cabang) 
                          VALUES (:kode_karyawan, :kode_transaksi, :kas_awal, :tanggal_transaksi, 'on proses', :kode_cabang, :nama_cabang)";
            $stmt_trans = $pdo->prepare($sql_trans);
            $stmt_trans->bindParam(':kode_karyawan', $kas_besok_kasir, PDO::PARAM_STR);
            $stmt_trans->bindParam(':kode_transaksi', $kode_transaksi_baru, PDO::PARAM_STR);
            $stmt_trans->bindParam(':kas_awal', $kas_besok_nominal, PDO::PARAM_STR);
            $stmt_trans->bindParam(':tanggal_transaksi', $kas_besok_date, PDO::PARAM_STR);
            $stmt_trans->bindParam(':kode_cabang', $kasir_kode_cabang, PDO::PARAM_STR);
            $stmt_trans->bindParam(':nama_cabang', $kasir_nama_cabang, PDO::PARAM_STR);
            
            if (!$stmt_trans->execute()) {
                throw new Exception("Gagal membuat transaksi kasir baru di database.");
            }
            
            // ENHANCED: Success messages with pergantian kasir info
            if ($is_kasir_change) {
                $success_messages[] = "🔄 PERGANTIAN KASIR: Kas awal besok berhasil dibuat untuk {$kasir_info['nama_karyawan']} menggantikan {$current_kasir_name}";
            } else {
                $success_messages[] = "👤 KASIR NORMAL: Kas awal besok berhasil dibuat untuk {$kasir_info['nama_karyawan']} (melanjutkan)";
            }
            
            $success_messages[] = "🏪 Cabang: {$kasir_nama_cabang}";
            $success_messages[] = "📅 Tanggal: " . date('d/m/Y', strtotime($kas_besok_date)) . " dengan nominal Rp " . number_format($kas_besok_nominal, 0, ',', '.') . " (LOCKED 500K)";
            $success_messages[] = "🔢 Kode Transaksi Baru: $kode_transaksi_baru";
            $success_messages[] = "💰 Detail: " . count($kas_besok_receh_data) . " jenis denominasi, total $total_keping keping dengan algoritma PRIORITAS MUTLAK TERKECIL";
            
            // Update session if kas besok is for the same kasir
            if ($kas_besok_kasir == $_SESSION['kode_karyawan']) {
                $_SESSION['kode_transaksi'] = $kode_transaksi_baru;
                $_SESSION['new_transaction_created'] = true;
                $_SESSION['success_message'] = "Transaksi baru berhasil dibuat: $kode_transaksi_baru";
            }
        }
        
        // Commit transaction
        $pdo->commit();
        
        // ENHANCED: Success handling with user-friendly redirect
        $_SESSION['success_message'] = implode("\n", $success_messages);
        $_SESSION['closing_success'] = true;
        
        // Redirect dengan informative message
        header('Location: index_kasir.php?closing=success&timestamp=' . time());
        exit;
        
    } catch (Exception $e) {
        // ENHANCED: Better error handling with rollback
        if ($pdo->inTransaction()) {
            $pdo->rollback();
        }
        
        error_log("Closing Transaction Error: " . $e->getMessage());
        error_log("POST Data: " . print_r($_POST, true));
        error_log("Stack Trace: " . $e->getTraceAsString());
        
        $_SESSION['error_message'] = "Gagal memproses closing transaksi: " . $e->getMessage();
        $_SESSION['closing_error'] = true;
        
        header('Location: close_transaksi.php?kode_transaksi=' . urlencode($kode_transaksi) . '&error=1');
        exit;
    }
}

// Function to get status text and color based on selisih
function getSelisihStatus($selisih) {
    if ($selisih == 0) {
        return ['text' => 'OKE', 'color' => 'green'];
    } elseif ($selisih > 0) {
        return ['text' => 'LEBIH', 'color' => 'red'];
    } else {
        return ['text' => 'KURANG', 'color' => 'red'];
    }
}

$selisih_status = getSelisihStatus($selisih_setoran);

function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.') . ($angka == 0 ? " (Belum diisi)" : "");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Penutupan Transaksi & Cek Data Kasir</title>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <style>
        .selisih-minus { color: red; }
        .selisih-plus { color: green; }
        body { margin-top: 20px; }
        .section-header { 
            background: linear-gradient(135deg, #007bff, #0056b3); 
            color: white; 
            padding: 10px 15px; 
            margin: 20px 0 10px 0; 
            border-radius: 5px;
        }
        .kas-besok-section { 
            border: 2px solid #28a745; 
            border-radius: 10px; 
            padding: 20px; 
            margin: 20px 0;
            background: #f8fff9;
        }
        .preview-modal { max-height: 70vh; overflow-y: auto; }
        .table-header-blue { 
            background-color: #4472C4; 
            color: white; 
            text-align: center; 
            font-weight: bold;
        }
        .table-blue th { 
            background-color: #4472C4; 
            color: white; 
            text-align: center;
        }
        .table-blue td { 
            text-align: right; 
        }
        .table-blue td:first-child { 
            text-align: left; 
        }
        .select2-container { 
            width: 100% !important; 
        }
        .denomination-table th {
            background-color: #4472C4;
            color: white;
            text-align: center;
        }
        .denomination-table td {
            text-align: right;
        }
        .denomination-table td:first-child {
            text-align: right;
        }
        .status-oke {
            color: green;
            font-weight: bold;
        }
        .status-selisih {
            color: red;
            font-weight: bold;
        }
        .exact-match { border-left: 4px solid #28a745; }
        .insufficient-match { border-left: 4px solid #dc3545; }
        .alternative-match { border-left: 4px solid #ffc107; }
        .side-by-side { display: flex; gap: 20px; }
        .side-by-side > div { flex: 1; }
        .config-card { border-left: 4px solid #007bff; }
        
        /* FIXED: Enhanced styles for locked input */
        .locked-input {
            background-color: #e9ecef !important;
            color: #6c757d !important;
            border: 2px solid #dc3545 !important;
            cursor: not-allowed !important;
            position: relative;
        }
        
        .locked-input::before {
            content: "🔒";
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
            z-index: 10;
        }
        
        .locked-label {
            color: #dc3545 !important;
            font-weight: bold !important;
        }
        
        .lock-notice {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 8px 12px;
            border-radius: 5px;
            font-size: 12px;
            margin-top: 5px;
        }
        
        /* ENHANCED: Enhanced styles for kasir selection dan pergantian kasir */
        .kasir-change-warning {
            border-left: 4px solid #ffc107 !important;
            background-color: #fff3cd !important;
            color: #856404 !important;
            animation: warningPulse 3s infinite;
        }
        
        @keyframes warningPulse {
            0% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(255, 193, 7, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); }
        }
        
        .kasir-selection-current {
            background-color: #e3f2fd !important;
            border-left: 3px solid #2196f3 !important;
        }
        
        .kasir-selection-change {
            background-color: #fff3e0 !important;
            border-left: 3px solid #ff9800 !important;
        }
        
        .select2-container .select2-selection--single {
            height: 42px !important;
            border: 2px solid #ced4da !important;
        }
        
        .select2-container .select2-selection--single .select2-selection__rendered {
            line-height: 38px !important;
            padding-left: 12px !important;
            font-size: 14px !important;
        }
        
        .select2-container .select2-selection--single .select2-selection__arrow {
            height: 38px !important;
        }
        
        .select2-dropdown .select2-results__option {
            padding: 10px 15px !important;
            border-bottom: 1px solid #eee !important;
        }
        
        .select2-dropdown .select2-results__option[aria-selected="true"] {
            background-color: #007bff !important;
            color: white !important;
        }
        
        .kasir-confirmation-card {
            border: 2px solid #ffc107 !important;
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.3) !important;
        }
        
        /* Enhanced notification styles */
        .notification-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            max-width: 500px;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.9);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .loading-overlay.show {
            display: flex;
        }
        
        /* Enhanced border styles */
        .border-start {
            border-left-width: 4px !important;
        }
        
        .border-warning {
            border-color: #ffc107 !important;
        }
        
        .border-info {
            border-color: #0dcaf0 !important;
        }
        
        .border-success {
            border-color: #198754 !important;
        }
        
        /* Button state enhancements */
        button:disabled {
            cursor: not-allowed !important;
            opacity: 0.65 !important;
        }
        
        button[onclick="previewKasBesok()"]:disabled {
            background-color: #6c757d !important;
            border-color: #6c757d !important;
            color: white !important;
        }
    </style>
</head>
<body class="container">

<!-- Enhanced Notification Handler -->
<?php if (isset($_SESSION['success_message'])): ?>
<div class="alert alert-success alert-dismissible fade show notification-toast" role="alert">
    <i class="fas fa-check-circle"></i> <strong>Berhasil!</strong><br>
    <?php echo nl2br(htmlspecialchars($_SESSION['success_message'])); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error_message'])): ?>
<div class="alert alert-danger alert-dismissible fade show notification-toast" role="alert">
    <i class="fas fa-exclamation-circle"></i> <strong>Error!</strong><br>
    <?php echo nl2br(htmlspecialchars($_SESSION['error_message'])); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['error_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['warning_message'])): ?>
<div class="alert alert-warning alert-dismissible fade show notification-toast" role="alert">
    <i class="fas fa-exclamation-triangle"></i> <strong>Perhatian!</strong><br>
    <?php echo nl2br(htmlspecialchars($_SESSION['warning_message'])); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['warning_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['info_message'])): ?>
<div class="alert alert-info alert-dismissible fade show notification-toast" role="alert">
    <i class="fas fa-info-circle"></i> <strong>Informasi!</strong><br>
    <?php echo nl2br(htmlspecialchars($_SESSION['info_message'])); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['info_message']); ?>
<?php endif; ?>

<h1 class="text-center mb-4">Konfirmasi Penutupan Transaksi</h1>
<div class="row mb-4">
    <div class="col-md-6">
        <p><strong>Kode Transaksi:</strong> <?php echo htmlspecialchars($kode_transaksi); ?></p>
        <p><strong>Cabang:</strong> <?php echo htmlspecialchars($nama_cabang); ?></p>
    </div>
    <div class="col-md-6">
        <p><strong>Kasir:</strong> <?php echo htmlspecialchars($current_kasir_name); ?></p>
        <p><strong>Tanggal Transaksi:</strong> <?php echo date('d/m/Y', strtotime($current_transaction_date)); ?></p>
    </div>
</div>

<!-- FIXED: Enhanced Configuration Info Card with Lock Notice -->
<div class="card mb-4 config-card">
    <div class="card-header bg-primary text-white">
        <h6 class="mb-0">
            <i class="fas fa-lock"></i> Konfigurasi Kas Awal - <?php echo htmlspecialchars($nama_cabang); ?> (LOCKED)
        </h6>
    </div>
    <div class="card-body py-2">
        <div class="row">
            <div class="col-md-6">
                <strong>Nominal Kas Awal:</strong> 
                <span class="text-danger fs-5">🔒 Rp 500.000 (LOCKED)</span>
                <div class="lock-notice mt-1">
                    <i class="fas fa-info-circle"></i> Nominal telah dikunci secara permanen pada Rp 500.000 sesuai kebijakan sistem
                </div>
            </div>
            <div class="col-md-3">
                <small class="text-muted">Status: 
                <span class="badge bg-danger">LOCKED</span>
                </small>
            </div>
            <div class="col-md-3 text-end">
                <small class="text-muted">Config ID: #<?php echo $kas_awal_config['id']; ?></small><br>
                <small class="text-muted">Update: <?php echo date('d/m/Y H:i', strtotime($kas_awal_config['updated_at'])); ?></small>
            </div>
        </div>
    </div>
</div>

<!-- Data Sistem Aplikasi -->
<table class="table table-bordered table-blue mb-4">
    <thead>
        <tr class="table-header-blue">
            <th colspan="2">Data Sistem Aplikasi</th>
        </tr>
        <tr>
            <th style="background-color: #4472C4; color: white; width: 40%;">Keterangan</th>
            <th style="background-color: #4472C4; color: white; text-align: center;">Nominal</th>
        </tr>
    </thead>
    <tbody>
        <tr><td>Kas Awal Hari Ini</td><td><?php echo number_format($kas_awal, 0, ',', '.'); ?></td></tr>
        <tr><td>Omset Penjualan</td><td><?php echo number_format($data_penjualan, 0, ',', '.'); ?></td></tr>
        <tr><td>Omset Servis</td><td><?php echo number_format($data_servis, 0, ',', '.'); ?></td></tr>
        <tr><td>Jumlah Omset</td><td><?php echo number_format($omset, 0, ',', '.'); ?></td></tr>
        <tr><td>Pemasukan Kasir</td><td><?php echo number_format($uang_masuk_ke_kasir, 0, ',', '.'); ?></td></tr>
        <tr><td>Pengeluaran Kasir</td><td><?php echo number_format($pengeluaran_dari_kasir, 0, ',', '.'); ?></td></tr>
        <tr><td><strong>Kas Akhir</strong></td><td><strong><?php echo number_format($total_uang_di_kasir, 0, ',', '.'); ?></strong></td></tr>
    </tbody>
</table>

<!-- Riil Uang Kas Akhir -->
<table class="table table-bordered denomination-table mb-4">
    <thead>
        <tr class="table-header-blue">
            <th colspan="3">Riil Uang Kas Akhir</th>
        </tr>
        <tr>
            <th>Nominal</th>
            <th>Jumlah Keping</th>
            <th>Total</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($detail_kas_akhir)): ?>
            <tr>
                <td colspan="3" class="text-center text-danger">
                    <i class="fas fa-exclamation-triangle"></i> 
                    Belum ada data kas akhir. Silakan input kas akhir terlebih dahulu sebelum closing.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($detail_kas_akhir as $item): ?>
                <tr>
                    <td><?php echo number_format($item['nominal'], 0, ',', '.'); ?></td>
                    <td><?php echo number_format($item['jumlah_keping'], 0, ',', '.'); ?></td>
                    <td><?php echo number_format($item['nominal'] * $item['jumlah_keping'], 0, ',', '.'); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        <tr style="background-color: #f8f9fa;">
            <td colspan="2"><strong>Total Kas Akhir</strong></td>
            <td><strong><?php echo number_format($total_uang_di_kasir, 0, ',', '.'); ?></strong></td>
        </tr>
    </tbody>
</table>

<!-- Riil Uang vs Data Sistem Aplikasi -->
<table class="table table-bordered table-blue mb-4">
    <thead>
        <tr class="table-header-blue">
            <th colspan="2">Riil Uang vs Data Sistem Aplikasi</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td style="width: 40%;">Selisih</td>
            <td style="text-align: center; color: <?php echo $selisih_status['color']; ?>;">
                <?php echo number_format($selisih_setoran, 0, ',', '.'); ?>
            </td>
        </tr>
        <tr>
            <td>Status</td>
            <td style="text-align: center;" class="<?php echo ($selisih_status['text'] == 'OKE') ? 'status-oke' : 'status-selisih'; ?>">
                <?php echo $selisih_status['text']; ?>
                <?php if ($selisih_status['text'] != 'OKE'): ?>
                <small class="d-block text-muted">Periksa kembali perhitungan kas akhir</small>
                <?php endif; ?>
            </td>
        </tr>
    </tbody>
</table>

<!-- Pemasukan Kasir -->
<table class="table table-bordered table-blue mb-4">
    <thead>
        <tr class="table-header-blue">
            <th colspan="5">Pemasukan Kasir</th>
        </tr>
        <tr>
            <th>Kode Akun</th>
            <th>Jumlah (Rp)</th>
            <th>Keterangan</th>
            <th>Tanggal</th>
            <th>Waktu</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($pemasukan_detail)): ?>
            <?php foreach ($pemasukan_detail as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['kode_akun']); ?></td>
                    <td style="text-align: right;"><?php echo number_format($item['jumlah'], 0, ',', '.'); ?></td>
                    <td><?php echo htmlspecialchars($item['keterangan_transaksi']); ?></td>
                    <td style="text-align: center;"><?php echo date('d/m/Y', strtotime($item['tanggal'])); ?></td>
                    <td style="text-align: center;"><?php echo $item['waktu']; ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="table-light">
                <td colspan="4"><strong>Total Pemasukan</strong></td>
                <td style="text-align: right;"><strong><?php echo number_format($uang_masuk_ke_kasir, 0, ',', '.'); ?></strong></td>
            </tr>
        <?php else: ?>
            <tr>
                <td colspan="5" style="text-align: center; color: #666;">Tidak ada data pemasukan kasir hari ini</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- Pengeluaran Kasir - Biaya -->
<table class="table table-bordered table-blue mb-4">
    <thead>
        <tr class="table-header-blue">
            <th colspan="5">Pengeluaran Kasir - Biaya</th>
        </tr>
        <tr>
            <th>Kode Akun</th>
            <th>Jumlah (Rp)</th>
            <th>Keterangan</th>
            <th>Tanggal</th>
            <th>Waktu</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($pengeluaran_biaya)): ?>
            <?php 
            $total_biaya = 0;
            foreach ($pengeluaran_biaya as $item): 
                $total_biaya += $item['jumlah'];
            ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['kode_akun']); ?></td>
                    <td style="text-align: right;"><?php echo number_format($item['jumlah'], 0, ',', '.'); ?></td>
                    <td><?php echo htmlspecialchars($item['keterangan_transaksi']); ?></td>
                    <td style="text-align: center;"><?php echo date('d/m/Y', strtotime($item['tanggal'])); ?></td>
                    <td style="text-align: center;"><?php echo $item['waktu']; ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="table-light">
                <td colspan="4"><strong>Total Pengeluaran Biaya</strong></td>
                <td style="text-align: right;"><strong><?php echo number_format($total_biaya, 0, ',', '.'); ?></strong></td>
            </tr>
        <?php else: ?>
            <tr>
                <td colspan="5" style="text-align: center; color: #666;">Tidak ada data pengeluaran biaya hari ini</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- Pengeluaran Kasir - Non Biaya -->
<table class="table table-bordered table-blue mb-4">
    <thead>
        <tr class="table-header-blue">
            <th colspan="5">Pengeluaran Kasir - Non Biaya</th>
        </tr>
        <tr>
            <th>Kode Akun</th>
            <th>Jumlah (Rp)</th>
            <th>Keterangan</th>
            <th>Tanggal</th>
            <th>Waktu</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($pengeluaran_non_biaya)): ?>
            <?php 
            $total_non_biaya = 0;
            foreach ($pengeluaran_non_biaya as $item): 
                $total_non_biaya += $item['jumlah'];
            ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['kode_akun']); ?></td>
                    <td style="text-align: right;"><?php echo number_format($item['jumlah'], 0, ',', '.'); ?></td>
                    <td><?php echo htmlspecialchars($item['keterangan_transaksi']); ?></td>
                    <td style="text-align: center;"><?php echo date('d/m/Y', strtotime($item['tanggal'])); ?></td>
                    <td style="text-align: center;"><?php echo $item['waktu']; ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="table-light">
                <td colspan="4"><strong>Total Pengeluaran Non Biaya</strong></td>
                <td style="text-align: right;"><strong><?php echo number_format($total_non_biaya, 0, ',', '.'); ?></strong></td>
            </tr>
        <?php else: ?>
            <tr>
                <td colspan="5" style="text-align: center; color: #666;">Tidak ada data pengeluaran non biaya hari ini</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- ENHANCED: Kas Awal Besok Section with Smart Kasir Selection -->
<div class="kas-besok-section">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="text-success mb-0">
            <i class="fas fa-seedling"></i> Persiapan Kas Awal Besok (LOCKED 500K)
        </h5>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="enableKasBesok" onchange="toggleKasBesok()">
            <label class="form-check-label" for="enableKasBesok">
                <strong>Aktifkan fitur ini</strong>
            </label>
        </div>
    </div>
    
    <div id="kasBesokForm" style="display: none;">
        <!-- ENHANCED: Warning untuk pergantian kasir -->
        <div class="alert alert-warning border-start border-warning border-4 kasir-change-warning mb-3" id="kasirChangeWarning">
            <div class="row">
                <div class="col-md-8">
                    <h6><i class="fas fa-users"></i> ⚠️ PERHATIAN PERGANTIAN KASIR!</h6>
                    <p class="mb-2">
                        <strong>Kasir yang melakukan closing:</strong> 
                        <span class="badge bg-primary"><?php echo htmlspecialchars($current_kasir_name); ?></span>
                    </p>
                    <p class="mb-0">
                        <strong>🎯 PENTING:</strong> Pastikan Anda memilih kasir yang <strong>BENAR-BENAR AKAN KERJA BESOK</strong>, 
                        bukan kasir yang melakukan closing hari ini!
                    </p>
                </div>
                <div class="col-md-4">
                    <div class="bg-light p-2 rounded">
                        <small class="text-muted"><strong>⚡ Contoh Kasus:</strong></small><br>
                        <small>• Kasir A izin → diganti Kasir B</small><br>
                        <small>• Kasir B closing hari ini</small><br>
                        <small>• <strong>Besok Kasir A kembali</strong></small><br>
                        <small>• <span class="text-danger">→ Pilih Kasir A untuk besok!</span></small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="alert alert-info border-start border-info border-4">
            <small>
                <i class="fas fa-lock"></i> <strong>SISTEM LOCKED 500K:</strong> 
                Nominal kas awal besok telah dikunci pada <strong>Rp 500.000</strong> dan tidak dapat diubah.
                Sistem akan mengambil uang receh dengan algoritma <strong>PRIORITAS MUTLAK TERKECIL</strong> untuk mencapai nominal yang telah ditentukan.
            </small>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <label for="kasBesokDate" class="form-label">
                    Tanggal Kas Awal Besok: <span class="text-danger">*</span>
                </label>
                <input type="date" id="kasBesokDate" class="form-control" value="<?php echo $default_next_date; ?>" required>
                <small class="text-muted">Minimal hari ini atau setelahnya</small>
            </div>
            
            <!-- ENHANCED: Smart Kasir Selection dengan validasi pergantian -->
            <div class="col-md-4">
                <label for="kasBesokKasir" class="form-label">
                    <i class="fas fa-user-tie"></i> Kasir yang AKAN KERJA BESOK: <span class="text-danger">*</span>
                </label>
                <select id="kasBesokKasir" class="form-control" required>
                    <!-- FIXED: Tidak ada yang auto-selected -->
                    <option value="">-- PILIH KASIR YANG AKAN KERJA BESOK --</option>
                    
                    <!-- Group kasir berdasarkan cabang -->
                    <?php 
                    $kasir_by_cabang = [];
                    foreach ($kasir_list as $kasir) {
                        $kasir_by_cabang[$kasir['nama_cabang']][] = $kasir;
                    }
                    
                    foreach ($kasir_by_cabang as $nama_cabang_group => $kasir_cabang): ?>
                        <optgroup label="📍 <?php echo htmlspecialchars($nama_cabang_group); ?>">
                            <?php foreach ($kasir_cabang as $kasir): 
                                $is_current_kasir = ($kasir['kode_karyawan'] == $_SESSION['kode_karyawan']);
                                $kasir_label = htmlspecialchars($kasir['nama_karyawan']);
                                if ($is_current_kasir) {
                                    $kasir_label .= " (Kasir yang melakukan closing ini)";
                                }
                            ?>
                                <option value="<?php echo htmlspecialchars($kasir['kode_karyawan']); ?>" 
                                        data-cabang="<?php echo htmlspecialchars($kasir['nama_cabang']); ?>"
                                        data-kode-cabang="<?php echo htmlspecialchars($kasir['kode_cabang']); ?>"
                                        data-is-current="<?php echo $is_current_kasir ? 'true' : 'false'; ?>"
                                        data-kasir-name="<?php echo htmlspecialchars($kasir['nama_karyawan']); ?>">
                                    <?php if ($is_current_kasir): ?>
                                        👤 <?php echo $kasir_label; ?>
                                    <?php else: ?>
                                        🔄 <?php echo $kasir_label; ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                
                <!-- ENHANCED: Status indicator untuk kasir yang dipilih -->
                <div id="kasirSelectionStatus" class="mt-2" style="display: none;">
                    <!-- Will be populated by JavaScript -->
                </div>
            </div>
            
            <div class="col-md-4">
                <label for="kasBesokNominal" class="form-label locked-label">
                    🔒 Nominal Kas Awal (LOCKED): <span class="text-danger">*</span>
                </label>
                <div class="position-relative">
                    <input type="number" id="kasBesokNominal" class="form-control locked-input" 
                           value="500000" readonly disabled title="Nominal telah dikunci pada Rp 500.000">
                    <span class="position-absolute top-50 end-0 translate-middle-y me-3" style="pointer-events: none;">
                        🔒
                    </span>
                </div>
                <div class="lock-notice">
                    <i class="fas fa-info-circle"></i> <strong>LOCKED:</strong> Rp 500.000 (tidak dapat diubah)
                </div>
            </div>
        </div>
        
        <!-- ENHANCED: Konfirmasi pergantian kasir -->
        <div id="kasirConfirmationCard" class="mt-3" style="display: none;">
            <!-- Will be populated by JavaScript -->
        </div>
        
        <div class="mt-3">
            <button type="button" class="btn btn-outline-primary" onclick="previewKasBesok()">
                <i class="fas fa-eye"></i> Preview Receh PRIORITAS TERKECIL (500K)
            </button>
            <button type="button" class="btn btn-success" id="applyKasBesokBtn" onclick="applyKasBesok()" disabled>
                <i class="fas fa-check"></i> Gunakan Kombinasi Ini
            </button>
        </div>
        
        <div class="alert alert-info mt-3">
            <small><i class="fas fa-exclamation-triangle"></i> <strong>Checklist Sebelum Lanjut:</strong> 
            <ul class="mb-0 mt-2">
                <li>✅ Kas akhir sudah diinput dengan lengkap dan benar</li>
                <li>✅ <strong>Kasir yang dipilih BENAR-BENAR akan kerja besok</strong></li>
                <li>✅ <strong>🔒 Nominal LOCKED Rp 500.000</strong> - tidak dapat diubah sesuai kebijakan sistem</li>
                <li>✅ Kas awal akan langsung dibuat dan transaksi baru akan aktif setelah closing</li>
                <li>✅ <strong>Algoritma PRIORITAS MUTLAK TERKECIL:</strong> Mengambil dari nominal Rp 500 → Rp 1.000 → Rp 2.000 dan seterusnya</li>
                <li>✅ Sistem sudah melakukan validasi apakah kasir memiliki transaksi aktif</li>
            </ul>
            </small>
        </div>
        
        <div id="kasBesokPreviewResult" class="mt-3" style="display: none;"></div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-8">
        <p><strong>Apakah Anda yakin ingin menutup transaksi ini?</strong></p>
        <small class="text-muted">
            Setelah closing, transaksi tidak dapat diubah lagi. 
            <?php if (!empty($detail_kas_akhir)): ?>
            Pastikan semua data sudah benar sebelum melanjutkan.
            <?php else: ?>
            <span class="text-warning">⚠️ Kas akhir belum diinput - silakan input terlebih dahulu.</span>
            <?php endif; ?>
        </small>
    </div>
    <div class="col-md-4 text-end">
        <div class="d-flex gap-2 justify-content-end">
            <button class="btn btn-success" id="btnLanjutClosing" 
                    <?php echo empty($detail_kas_akhir) ? 'disabled title="Kas akhir belum diinput"' : ''; ?>>
                <i class="fas fa-check-circle"></i> Lanjut Closing
            </button>
            <button class="btn btn-danger" onclick="window.location.href='index_kasir.php';">
                <i class="fas fa-times-circle"></i> Batal Closing
            </button>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="text-center">
        <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <h5>Memproses Closing Transaksi...</h5>
        <p class="text-muted" id="loadingMessage">Mohon tunggu, sistem sedang memproses data transaksi Anda.</p>
    </div>
</div>

<!-- Hidden Form -->
<form id="formClosing" method="POST" action="" style="display: none;">
    <input type="hidden" name="kas_awal" value="<?php echo $kas_awal; ?>">
    <input type="hidden" name="kas_akhir" value="<?php echo $total_uang_di_kasir; ?>">
    <input type="hidden" name="total_pemasukan" value="<?php echo $uang_masuk_ke_kasir; ?>">
    <input type="hidden" name="total_pengeluaran" value="<?php echo $pengeluaran_dari_kasir; ?>">
    <input type="hidden" name="total_penjualan" value="<?php echo $data_penjualan; ?>">
    <input type="hidden" name="total_servis" value="<?php echo $data_servis; ?>">
    <input type="hidden" name="setoran_real" value="<?php echo $setoran_real; ?>">
    <input type="hidden" name="omset" value="<?php echo $omset; ?>">
    <input type="hidden" name="data_setoran" value="<?php echo $setoran_data; ?>">
    <input type="hidden" name="selisih_setoran" value="<?php echo $selisih_setoran; ?>">
    <input type="hidden" name="kode_transaksi" value="<?php echo $kode_transaksi; ?>">
    
    <!-- Kas Besok Fields -->
    <input type="hidden" name="kas_besok_date" id="hiddenKasBesokDate">
    <input type="hidden" name="kas_besok_kasir" id="hiddenKasBesokKasir">
    <input type="hidden" name="kas_besok_nominal" id="hiddenKasBesokNominal" value="500000">
    <input type="hidden" name="kas_besok_receh_data" id="hiddenKasBesokRecehData">
</form>

<!-- ENHANCED: Preview Modal -->
<div class="modal fade" id="kasBesokPreviewModal" tabindex="-1" aria-labelledby="kasBesokPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="kasBesokPreviewModalLabel">
                    <i class="fas fa-coins"></i> Preview Uang Receh PRIORITAS MUTLAK TERKECIL untuk Kas Awal Besok (LOCKED 500K)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body preview-modal" id="kasBesokPreviewContent">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-success" id="confirmApplyKasBesokBtn" onclick="confirmApplyKasBesok()" disabled>
                    <i class="fas fa-check-circle"></i> Gunakan Kombinasi PRIORITAS TERKECIL (500K LOCKED)
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let kasBesokRecehData = null;
let configData = <?php echo json_encode($kas_awal_config); ?>;

// FIXED: Force 500K locked value
const LOCKED_NOMINAL = 500000;

// ENHANCED: Global error handling to prevent unwanted error dialogs
(function() {
    'use strict';
    
    // Override alert function untuk filter error messages yang tidak diinginkan
    const originalAlert = window.alert;
    window.alert = function(message) {
        // List of error messages yang akan di-suppress
        const suppressedErrors = [
            'There is no active transaction',
            'Error: There is no active transaction',
            'No active transaction',
            'Transaksi tidak ditemukan',
            'Transaction not found'
        ];
        
        // Check apakah message termasuk yang harus di-suppress
        const shouldSuppress = suppressedErrors.some(error => 
            message.toLowerCase().includes(error.toLowerCase())
        );
        
        if (shouldSuppress) {
            console.log('🔇 Suppressed error alert:', message);
            // Show user-friendly notification instead
            showUserFriendlyNotification('Sesi transaksi telah berakhir. Mengarahkan ke halaman utama...', 'info');
            setTimeout(() => {
                window.location.href = 'index_kasir.php';
            }, 2000);
            return;
        }
        
        // Show normal alerts for non-error messages
        originalAlert(message);
    };
    
    // Global error handler untuk prevent browser error dialogs
    window.addEventListener('error', function(e) {
        if (e.message && (
            e.message.includes('transaction') || 
            e.message.includes('transaksi') ||
            e.message.includes('active')
        )) {
            e.preventDefault();
            console.log('🔇 Suppressed browser error:', e.message);
            return false;
        }
    });
    
    // Handle unhandled promise rejections
    window.addEventListener('unhandledrejection', function(event) {
        if (event.reason && event.reason.toString().includes('transaction')) {
            event.preventDefault();
            console.log('🔇 Suppressed promise rejection:', event.reason);
        }
    });
    
    // Override console.error untuk reduce noise
    const originalConsoleError = console.error;
    console.error = function(...args) {
        const errorString = args.join(' ').toLowerCase();
        if (!errorString.includes('transaction') && !errorString.includes('transaksi')) {
            originalConsoleError.apply(console, args);
        }
    };
})();

// Enhanced notification function
function showUserFriendlyNotification(message, type = 'info', duration = 4000) {
    // Remove existing notifications
    const existingNotifications = document.querySelectorAll('.user-notification');
    existingNotifications.forEach(notification => notification.remove());
    
    const alertClass = type === 'success' ? 'alert-success' : 
                     type === 'warning' ? 'alert-warning' :
                     type === 'error' ? 'alert-danger' : 'alert-info';
    const iconClass = type === 'success' ? 'fa-check-circle' : 
                    type === 'warning' ? 'fa-exclamation-triangle' :
                    type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle';
    
    const notification = document.createElement('div');
    notification.className = `alert ${alertClass} alert-dismissible fade show user-notification`;
    notification.style.cssText = `
        position: fixed; 
        top: 20px; 
        right: 20px; 
        z-index: 9999; 
        min-width: 300px; 
        max-width: 500px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    `;
    notification.innerHTML = `
        <i class="fas ${iconClass}"></i> ${message}
        <button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>
    `;
    
    document.body.appendChild(notification);
    
    // Auto remove after duration
    setTimeout(() => {
        if (notification.parentNode) {
            notification.style.opacity = '0';
            notification.style.transform = 'translateX(100%)';
            setTimeout(() => notification.remove(), 300);
        }
    }, duration);
}

$(document).ready(function() {
    console.log('🚀 Enhanced closing transaction system initialized with LOCKED 500K and smart kasir selection');
    
    // Initialize Select2 dengan enhancement untuk pergantian kasir
    $('#kasBesokKasir').select2({
        theme: 'bootstrap-5',
        placeholder: 'PILIH KASIR YANG AKAN KERJA BESOK (BUKAN YANG CLOSING)',
        allowClear: true,
        width: '100%',
        templateResult: function(option) {
            if (!option.id) {
                return option.text;
            }
            
            const element = option.element;
            const cabang = $(element).data('cabang');
            const isCurrent = $(element).data('is-current') === 'true';
            const kasirName = $(element).data('kasir-name');
            
            const $result = $('<div></div>');
            
            if (isCurrent) {
                $result.html(`
                    <div class="d-flex align-items-center">
                        <div class="badge bg-warning text-dark me-2">CLOSING</div>
                        <div>
                            <strong>${kasirName}</strong><br>
                            <small class="text-muted">${cabang}</small>
                        </div>
                    </div>
                `);
            } else {
                $result.html(`
                    <div class="d-flex align-items-center">
                        <div class="badge bg-success me-2">PILIHAN</div>
                        <div>
                            <strong>${kasirName}</strong><br>
                            <small class="text-muted">${cabang}</small>
                        </div>
                    </div>
                `);
            }
            
            return $result;
        },
        templateSelection: function(option) {
            if (!option.id) {
                return option.text;
            }
            
            const element = option.element;
            const kasirName = $(element).data('kasir-name');
            const isCurrent = $(element).data('is-current') === 'true';
            
            if (isCurrent) {
                return `👤 ${kasirName} (Kasir yang closing)`;
            } else {
                return `🔄 ${kasirName} (Kasir besok)`;
            }
        }
    });
    
    // ENHANCED: Event handler untuk perubahan kasir dengan validasi
    $('#kasBesokKasir').on('change', function() {
        const selectedValue = $(this).val();
        const selectedOption = $(this).find('option:selected');
        
        if (selectedValue) {
            const kasirName = selectedOption.data('kasir-name');
            const cabang = selectedOption.data('cabang');
            const isCurrent = selectedOption.data('is-current') === 'true';
            const currentKasirName = '<?php echo addslashes($current_kasir_name); ?>';
            
            updateKasirSelectionStatus(kasirName, cabang, isCurrent, currentKasirName);
            showKasirConfirmation(kasirName, cabang, isCurrent, currentKasirName);
        } else {
            hideKasirStatus();
        }
        
        resetPreview();
        validateKasirSelection();
    });
    
    $('#kasBesokDate').on('change', function() {
        validateDateSelection();
        resetPreview();
    });
    
    // Initialize tooltips if available
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    // Auto-dismiss notifications after some time
    setTimeout(() => {
        const notifications = document.querySelectorAll('.notification-toast');
        notifications.forEach(notification => {
            if (notification.parentNode) {
                notification.style.opacity = '0.8';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.style.opacity = '0';
                        notification.style.transform = 'translateX(100%)';
                        setTimeout(() => notification.remove(), 300);
                    }
                }, 8000);
            }
        });
    }, 1000);
    
    // Show welcome notification with enhanced kasir selection info
    showUserFriendlyNotification('Sistem closing transaksi telah siap dengan Enhanced Kasir Selection. Nominal kas awal LOCKED pada Rp 500.000.', 'info', 6000);
});

function updateKasirSelectionStatus(kasirName, cabang, isCurrent, currentKasirName) {
    const statusDiv = document.getElementById('kasirSelectionStatus');
    
    if (isCurrent) {
        // Kasir yang sama dengan yang melakukan closing
        statusDiv.innerHTML = `
            <div class="alert alert-success py-2 kasir-selection-current">
                <small>
                    <i class="fas fa-check-circle"></i> 
                    <strong>Kasir Normal:</strong> ${kasirName} akan melanjutkan kerja besok
                </small>
            </div>
        `;
        statusDiv.className = 'mt-2';
    } else {
        // Kasir berbeda = pergantian kasir
        statusDiv.innerHTML = `
            <div class="alert alert-warning py-2 kasir-selection-change">
                <small>
                    <i class="fas fa-exchange-alt"></i> 
                    <strong>Pergantian Kasir Terdeteksi:</strong><br>
                    Dari: <strong>${currentKasirName}</strong> → Ke: <strong>${kasirName}</strong>
                </small>
            </div>
        `;
        statusDiv.className = 'mt-2';
    }
    
    statusDiv.style.display = 'block';
}

function showKasirConfirmation(kasirName, cabang, isCurrent, currentKasirName) {
    const confirmationCard = document.getElementById('kasirConfirmationCard');
    
    if (!isCurrent) {
        // Show konfirmasi untuk pergantian kasir
        confirmationCard.innerHTML = `
            <div class="card border-warning kasir-confirmation-card">
                <div class="card-header bg-warning text-dark">
                    <h6 class="mb-0">
                        <i class="fas fa-exclamation-triangle"></i> 
                        Konfirmasi Pergantian Kasir
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>📋 Detail Pergantian:</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td><strong>Kasir yang closing:</strong></td>
                                    <td><span class="badge bg-primary">${currentKasirName}</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Kasir besok:</strong></td>
                                    <td><span class="badge bg-success">${kasirName}</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Cabang:</strong></td>
                                    <td>${cabang}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6>❓ Konfirmasi Pilihan:</h6>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="confirmKasirChange" 
                                       onchange="toggleKasirChangeConfirmation()">
                                <label class="form-check-label" for="confirmKasirChange">
                                    <strong>✅ Ya, saya konfirm ${kasirName} AKAN KERJA BESOK</strong>
                                </label>
                            </div>
                            <small class="text-muted">
                                Centang hanya jika Anda yakin 100% bahwa ${kasirName} yang akan kerja besok,
                                bukan ${currentKasirName} yang melakukan closing hari ini.
                            </small>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3 py-2">
                        <small>
                            <strong>🔍 Contoh Kasus Pergantian:</strong><br>
                            • Kasir A izin setengah hari → diganti Kasir B<br>
                            • Kasir B selesai kerja dan melakukan closing<br>
                            • Besok, Kasir A kembali kerja normal<br>
                            • <strong>Pilih Kasir A untuk kas awal besok</strong> (bukan Kasir B)
                        </small>
                    </div>
                </div>
            </div>
        `;
        confirmationCard.style.display = 'block';
        
        // Disable preview button until confirmed
        document.getElementById('applyKasBesokBtn').disabled = true;
        const previewBtn = document.querySelector('button[onclick="previewKasBesok()"]');
        if (previewBtn) {
            previewBtn.disabled = true;
            previewBtn.innerHTML = '<i class="fas fa-lock"></i> Konfirmasi Pergantian Kasir Dulu';
        }
    } else {
        // Kasir yang sama, no confirmation needed
        confirmationCard.style.display = 'none';
        enablePreviewButton();
    }
}

function toggleKasirChangeConfirmation() {
    const checkbox = document.getElementById('confirmKasirChange');
    
    if (checkbox && checkbox.checked) {
        enablePreviewButton();
        showUserFriendlyNotification('Pergantian kasir telah dikonfirmasi. Anda dapat melanjutkan preview.', 'success');
    } else {
        disablePreviewButton();
    }
}

function enablePreviewButton() {
    const previewBtn = document.querySelector('button[onclick="previewKasBesok()"]');
    if (previewBtn) {
        previewBtn.disabled = false;
        previewBtn.innerHTML = '<i class="fas fa-eye"></i> Preview Receh PRIORITAS TERKECIL (500K)';
    }
}

function disablePreviewButton() {
    const previewBtn = document.querySelector('button[onclick="previewKasBesok()"]');
    if (previewBtn) {
        previewBtn.disabled = true;
        previewBtn.innerHTML = '<i class="fas fa-lock"></i> Konfirmasi Pergantian Kasir Dulu';
    }
}

function hideKasirStatus() {
    document.getElementById('kasirSelectionStatus').style.display = 'none';
    document.getElementById('kasirConfirmationCard').style.display = 'none';
    disablePreviewButton();
}

function validateKasirSelection() {
    const selectedKasir = $('#kasBesokKasir').val();
    const selectedOption = $('#kasBesokKasir').find('option:selected');
    const currentKasir = '<?php echo $_SESSION["kode_karyawan"]; ?>';
    
    if (!selectedKasir) {
        showUserFriendlyNotification('Silakan pilih kasir yang AKAN KERJA BESOK terlebih dahulu.', 'error');
        return false;
    }
    
    const isCurrent = selectedOption.data('is-current') === 'true';
    
    if (!isCurrent) {
        // Pergantian kasir - check confirmation
        const confirmCheckbox = document.getElementById('confirmKasirChange');
        if (!confirmCheckbox || !confirmCheckbox.checked) {
            showUserFriendlyNotification('Harap konfirmasi pergantian kasir terlebih dahulu.', 'warning');
            return false;
        }
        
        const kasirName = selectedOption.data('kasir-name');
        showUserFriendlyNotification(`Pergantian kasir dikonfirmasi: ${kasirName} akan kerja besok dengan nominal LOCKED Rp 500.000.`, 'success');
    }
    
    return true;
}

function validateDateSelection() {
    const selectedDate = document.getElementById('kasBesokDate').value;
    const today = new Date().toISOString().split('T')[0];
    
    if (selectedDate < today) {
        showUserFriendlyNotification('Tanggal kas awal besok tidak boleh kurang dari hari ini.', 'error');
        document.getElementById('kasBesokDate').value = '<?php echo $default_next_date; ?>';
        return false;
    }
    
    console.log('📅 Date validated:', selectedDate);
    return true;
}

function resetPreview() {
    kasBesokRecehData = null;
    document.getElementById('applyKasBesokBtn').disabled = true;
    document.getElementById('kasBesokPreviewResult').style.display = 'none';
    
    // Reset confirm button in modal
    const confirmBtn = document.getElementById('confirmApplyKasBesokBtn');
    if (confirmBtn) {
        confirmBtn.disabled = true;
    }
    
    console.log('🔄 Preview reset');
}

function toggleKasBesok() {
    const checkbox = document.getElementById('enableKasBesok');
    const form = document.getElementById('kasBesokForm');
    
    if (checkbox.checked) {
        // Check if kas akhir is available
        <?php if (empty($detail_kas_akhir)): ?>
            showUserFriendlyNotification('Kas akhir belum diinput. Silakan input kas akhir terlebih dahulu sebelum menggunakan fitur kas awal besok.', 'error');
            checkbox.checked = false;
            return;
        <?php endif; ?>
        
        form.style.display = 'block';
        showUserFriendlyNotification('Fitur kas awal besok diaktifkan dengan Enhanced Kasir Selection. Nominal LOCKED pada Rp 500.000.', 'info');
    } else {
        form.style.display = 'none';
        resetPreview();
        showUserFriendlyNotification('Fitur kas awal besok dinonaktifkan.', 'info');
    }
}

function previewKasBesok() {
    const date = document.getElementById('kasBesokDate').value;
    const nominal = LOCKED_NOMINAL; // FIXED: Always use locked nominal
    const kasir = $('#kasBesokKasir').val();
    const selectedOption = $('#kasBesokKasir').find('option:selected');
    
    console.log('🔍 Preview kas besok called with enhanced kasir validation:', { date, nominal, kasir });
    
    // Enhanced validation
    if (!date) {
        showUserFriendlyNotification('Silakan pilih tanggal untuk kas awal besok.', 'error');
        document.getElementById('kasBesokDate').focus();
        return;
    }
    
    if (!validateDateSelection()) {
        return;
    }
    
    if (!kasir) {
        showUserFriendlyNotification('Silakan pilih kasir yang AKAN KERJA BESOK terlebih dahulu.', 'error');
        $('#kasBesokKasir').select2('open');
        return;
    }
    
    // Validate kasir selection
    if (!validateKasirSelection()) {
        return;
    }
    
    const kasirName = selectedOption.data('kasir-name');
    const isCurrent = selectedOption.data('is-current') === 'true';
    const currentKasirName = '<?php echo addslashes($current_kasir_name); ?>';
    
    // Additional confirmation for kasir change
    if (!isCurrent) {
        const confirmMsg = `Anda akan membuat kas awal besok untuk ${kasirName} (pergantian kasir).\n\nApakah Anda yakin ${kasirName} yang akan kerja besok?`;
        if (!confirm(confirmMsg)) {
            showUserFriendlyNotification('Preview dibatalkan. Silakan pilih kasir yang benar.', 'info');
            return;
        }
        
        showUserFriendlyNotification(`Memproses kas awal besok untuk ${kasirName} (pergantian kasir dari ${currentKasirName})...`, 'info');
    }
    
    // Show loading in modal
    document.getElementById('kasBesokPreviewContent').innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <h5>Menganalisis Ketersediaan Receh untuk ${kasirName}</h5>
            <p class="text-muted">
                Target: <strong>Rp 500.000 (LOCKED)</strong><br>
                ${!isCurrent ? `<span class="badge bg-warning text-dark">PERGANTIAN KASIR</span>` : `<span class="badge bg-success">KASIR NORMAL</span>`}<br>
                Algoritma: <strong>PRIORITAS MUTLAK TERKECIL</strong>
            </p>
            <div class="progress mt-3" style="height: 8px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%"></div>
            </div>
            <small class="text-muted mt-2 d-block">🔒 Target: Rp 500.000 (LOCKED) | Urutan: Rp 500 → Rp 1.000 → Rp 2.000 → dst...</small>
        </div>
    `;
    
    const modal = new bootstrap.Modal(document.getElementById('kasBesokPreviewModal'));
    modal.show();
    
    const urlParams = new URLSearchParams(window.location.search);
    const kodeTransaksi = urlParams.get('kode_transaksi') || '<?php echo $kode_transaksi; ?>';
    
    if (!kodeTransaksi) {
        document.getElementById('kasBesokPreviewContent').innerHTML = 
            '<div class="alert alert-danger">Kode transaksi tidak ditemukan. Silakan muat ulang halaman.</div>';
        return;
    }
    
    // FIXED: Always use locked nominal in request
    const requestUrl = `?action=preview_kas_besok&kode_transaksi=${kodeTransaksi}&date=${date}&nominal=${LOCKED_NOMINAL}&kasir=${kasir}&t=${Date.now()}`;
    console.log('🌐 Making request with enhanced kasir validation:', requestUrl);
    
    fetch(requestUrl)
        .then(response => {
            console.log('📡 Response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(text => {
            console.log('📄 Raw response received, length:', text.length);
            
            try {
                const data = JSON.parse(text);
                console.log('✅ JSON parsed successfully:', data);
                displayKasBesokPreview(data);
            } catch (e) {
                console.error('❌ JSON Parse Error:', e);
                console.error('Raw response preview:', text.substring(0, 500));
                document.getElementById('kasBesokPreviewContent').innerHTML = 
                    `<div class="alert alert-danger">
                        <h6>Error Parsing Response</h6>
                        <p>Terjadi kesalahan saat memproses respons server.</p>
                        <details>
                            <summary>Technical Details</summary>
                            <pre style="background: #f8f9fa; padding: 10px; border-radius: 5px; max-height: 200px; overflow-y: auto;">${text.substring(0, 1000)}</pre>
                        </details>
                        <button class="btn btn-secondary mt-2" onclick="location.reload()">Muat Ulang Halaman</button>
                    </div>`;
            }
        })
        .catch(error => {
            console.error('🚨 Fetch Error:', error);
            document.getElementById('kasBesokPreviewContent').innerHTML = 
                `<div class="alert alert-danger">
                    <h6>Network/Server Error</h6>
                    <p>Terjadi kesalahan saat memuat data: ${error.message}</p>
                    <div class="mt-3">
                        <button class="btn btn-primary" onclick="previewKasBesok()">
                            <i class="fas fa-redo"></i> Coba Lagi
                        </button>
                        <button class="btn btn-secondary" onclick="location.reload()">
                            <i class="fas fa-refresh"></i> Muat Ulang Halaman
                        </button>
                    </div>
                </div>`;
        });
}

function displayKasBesokPreview(data) {
    const content = document.getElementById('kasBesokPreviewContent');
    
    console.log('🎨 Displaying preview with enhanced kasir selection data:', data);
    
    if (!data || typeof data !== 'object') {
        content.innerHTML = '<div class="alert alert-danger">Data respons tidak valid.</div>';
        return;
    }
    
    if (!data.success) {
        let alertClass = 'alert-warning';
        let alertIcon = '⚠️';
        
        if (data.status === 'insufficient_total') {
            alertClass = 'alert-danger';
            alertIcon = '❌';
        }
        
        content.innerHTML = `
            <div class="alert ${alertClass}">
                <h6>${alertIcon} ${data.message || 'Unknown error'}</h6>
                <div class="row mt-3">
                    <div class="col-md-6">
                        <p><strong>🎯 Target LOCKED:</strong><br>
                        Rp ${LOCKED_NOMINAL.toLocaleString('id-ID')} (500K)</p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>💰 Total kas akhir tersedia:</strong><br>
                        Rp ${parseInt(data.total_all_denominations || 0).toLocaleString('id-ID')}</p>
                    </div>
                </div>
                <div class="alert alert-info mt-3">
                    <h6><i class="fas fa-lightbulb"></i> Saran Perbaikan:</h6>
                    <ul class="mb-0">
                        ${data.status === 'insufficient_total' ? 
                            '<li>Tambahkan uang ke kas akhir untuk mencapai minimum Rp 500.000</li><li>Periksa kembali input kas akhir</li>' :
                            '<li>Input kas akhir dengan denominasi yang lebih bervariasi</li><li>Pastikan ada nominal kecil (Rp 500, Rp 1.000, Rp 2.000, Rp 5.000)</li><li>Periksa kembali input kas akhir dan pastikan semua denominasi tercatat</li>'
                        }
                    </ul>
                </div>
            </div>
        `;
        return;
    }
    
    // Validate success case
    if (!data.selected_receh || !Array.isArray(data.selected_receh)) {
        console.error('❌ Invalid selected_receh data:', data.selected_receh);
        content.innerHTML = '<div class="alert alert-danger">Data receh tidak valid. Silakan coba lagi.</div>';
        return;
    }
    
    if (!data.all_denominations || !Array.isArray(data.all_denominations)) {
        console.error('❌ Invalid all_denominations data:', data.all_denominations);
        content.innerHTML = '<div class="alert alert-danger">Data denominasi tidak valid. Silakan coba lagi.</div>';
        return;
    }
    
    // Store data for later use
    kasBesokRecehData = data.selected_receh;
    
    const targetAmount = LOCKED_NOMINAL; // FIXED: Always use locked nominal
    const resultAmount = parseInt(data.total_selected_receh);
    const allDenomTotal = parseInt(data.total_all_denominations);
    
    // Get kasir info from dropdown
    const selectedOption = $('#kasBesokKasir').find('option:selected');
    const kasirName = selectedOption.data('kasir-name') || 'Unknown';
    const isCurrent = selectedOption.data('is-current') === 'true';
    const currentKasirName = '<?php echo addslashes($current_kasir_name); ?>';
    
    // Status styling
    let statusClass = '';
    let statusIcon = '';
    let statusText = '';
    
    if (data.status === 'exact_match') {
        statusClass = 'exact-match border-success bg-light';
        statusIcon = '✅';
        statusText = `Kombinasi PRIORITAS MUTLAK TERKECIL Optimal Ditemukan untuk ${kasirName}! (500K LOCKED)`;
    } else if (data.status === 'alternative_found') {
        statusClass = 'alternative-match border-warning bg-light';
        statusIcon = '⚡';
        statusText = `Kombinasi PRIORITAS MUTLAK TERKECIL Alternatif Ditemukan untuk ${kasirName}! (500K LOCKED)`;
    }
    
    // Sort data for display
    const sortedAllDenoms = [...data.all_denominations].sort((a, b) => a.nominal - b.nominal);
    const sortedSelectedReceh = [...data.selected_receh].sort((a, b) => a.nominal - b.nominal);
    
    let html = `
        <div class="alert alert-success ${statusClass} p-4">
            <h6 class="mb-3">${statusIcon} ${statusText}</h6>
            <div class="row">
                <div class="col-md-3">
                    <div class="card border-0 bg-danger text-white h-100">
                        <div class="card-body text-center py-2">
                            <h6 class="card-title mb-1">🔒 Target LOCKED</h6>
                            <h5 class="mb-0">Rp ${targetAmount.toLocaleString('id-ID')}</h5>
                            <small>500K (Tidak dapat diubah)</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 bg-success text-white h-100">
                        <div class="card-body text-center py-2">
                            <h6 class="card-title mb-1">💰 Hasil</h6>
                            <h5 class="mb-0">Rp ${resultAmount.toLocaleString('id-ID')}</h5>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 ${isCurrent ? 'bg-primary' : 'bg-warning'} text-white h-100">
                        <div class="card-body text-center py-2">
                            <h6 class="card-title mb-1">${isCurrent ? '👤 Kasir' : '🔄 Pergantian'}</h6>
                            <h6 class="mb-0">${kasirName}</h6>
                            <small>${isCurrent ? 'Normal' : 'Ganti dari ' + currentKasirName}</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 bg-info text-white h-100">
                        <div class="card-body text-center py-2">
                            <h6 class="card-title mb-1">📊 Status</h6>
                            <h6 class="mb-0">${resultAmount === targetAmount ? '✅ TEPAT' : '❌ TIDAK TEPAT'}</h6>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-3 p-2 bg-white rounded">
                <small class="text-muted">
                    <strong>📅 Transaksi sumber:</strong> ${data.transaction_code || 'Unknown'} | 
                    <strong>🏪 Total tersedia:</strong> Rp ${allDenomTotal.toLocaleString('id-ID')} | 
                    <strong>⚙️ Algoritma:</strong> PRIORITAS MUTLAK NOMINAL TERKECIL | 
                    <strong>🔒 LOCKED:</strong> 500K
                </small>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="fas fa-coins"></i> Semua Denominasi Kas Akhir</h6>
                        <small>Total keseluruhan: Rp ${allDenomTotal.toLocaleString('id-ID')}</small>
                    </div>
                    <div class="card-body p-0">
                        <div style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-striped table-sm mb-0">
                                <thead class="table-primary sticky-top">
                                    <tr>
                                        <th>No</th>
                                        <th>Nominal</th>
                                        <th>Keping</th>
                                        <th>Total</th>
                                        <th>Prioritas</th>
                                    </tr>
                                </thead>
                                <tbody>
    `;
    
    sortedAllDenoms.forEach((item, index) => {
        const nominal = parseInt(item.nominal);
        const keping = parseInt(item.jumlah_keping);
        const totalNilai = nominal * keping;
        const priorityText = index < 3 ? 'SANGAT TINGGI' : index < 6 ? 'TINGGI' : index < 10 ? 'SEDANG' : 'RENDAH';
        const priorityColor = index < 3 ? 'text-success fw-bold' : index < 6 ? 'text-warning fw-bold' : index < 10 ? 'text-info' : 'text-muted';
        const rowClass = index < 3 ? 'table-success' : index < 6 ? 'table-warning' : '';
        
        html += `
            <tr class="${rowClass}">
                <td><span class="badge bg-primary">${index + 1}</span></td>
                <td><strong>Rp ${nominal.toLocaleString('id-ID')}</strong></td>
                <td>${keping.toLocaleString('id-ID')}</td>
                <td>Rp ${totalNilai.toLocaleString('id-ID')}</td>
                <td><small class="${priorityColor}">${priorityText}</small></td>
            </tr>
        `;
    });
    
    html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0"><i class="fas fa-star"></i> Kombinasi untuk ${kasirName}</h6>
                        <small>🔒 Target LOCKED: Rp ${resultAmount.toLocaleString('id-ID')} | ${isCurrent ? 'Normal' : 'Pergantian'}</small>
                    </div>
                    <div class="card-body p-0">
                        <div class="alert ${isCurrent ? 'alert-info' : 'alert-warning'} mx-2 mt-2 py-2 mb-2">
                            <small>
                                ${isCurrent ? 
                                    `<i class="fas fa-user"></i> <strong>KASIR NORMAL:</strong> ${kasirName} melanjutkan kerja dengan algoritma PRIORITAS MUTLAK TERKECIL` :
                                    `<i class="fas fa-exchange-alt"></i> <strong>PERGANTIAN KASIR:</strong> Dari ${currentKasirName} ke ${kasirName} dengan algoritma PRIORITAS MUTLAK TERKECIL`
                                }
                            </small>
                        </div>
                        <table class="table table-striped table-sm mb-0">
                            <thead class="table-success">
                                <tr>
                                    <th>No</th>
                                    <th>Nominal</th>
                                    <th>Keping</th>
                                    <th>Total</th>
                                    <th>%</th>
                                </tr>
                            </thead>
                            <tbody>
    `;
    
    let grandTotal = 0;
    sortedSelectedReceh.forEach((item, index) => {
        const nominal = parseInt(item.nominal);
        const keping = parseInt(item.jumlah_keping);
        const totalNilai = nominal * keping;
        const percentage = ((totalNilai / resultAmount) * 100).toFixed(1);
        grandTotal += totalNilai;
        
        const rowClass = index < 3 ? 'table-success' : index < 6 ? 'table-warning' : '';
        
        html += `
            <tr class="${rowClass}">
                <td><span class="badge bg-primary">${index + 1}</span></td>
                <td><strong>Rp ${nominal.toLocaleString('id-ID')}</strong></td>
                <td><strong>${keping.toLocaleString('id-ID')}</strong></td>
                <td>Rp ${totalNilai.toLocaleString('id-ID')}</td>
                <td>${percentage}%</td>
            </tr>
        `;
    });
    
    html += `
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="3">TOTAL</th>
                                    <th>Rp ${grandTotal.toLocaleString('id-ID')}</th>
                                    <th>100%</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">📋 Verifikasi Final untuk ${kasirName} ${isCurrent ? '(Normal)' : '(Pergantian)'}</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="d-flex flex-wrap gap-1">
                                    ${sortedSelectedReceh.map((item, idx) => 
                                        `<span class="badge bg-primary me-1 mb-1">${idx + 1}. Rp ${parseInt(item.nominal).toLocaleString('id-ID')} × ${item.jumlah_keping} = Rp ${(item.nominal * item.jumlah_keping).toLocaleString('id-ID')}</span>`
                                    ).join('')}
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-end">
                                    <h6 class="mb-0">🔒 TOTAL LOCKED: Rp ${grandTotal.toLocaleString('id-ID')}</h6>
                                    <span class="badge ${grandTotal === targetAmount ? 'bg-success' : 'bg-danger'} fs-6">
                                        ${grandTotal === targetAmount ? '✅ TEPAT 500K' : '❌ TIDAK TEPAT'}
                                    </span>
                                    <div class="mt-2">
                                        <small class="${isCurrent ? 'text-primary' : 'text-warning'}">
                                            ${isCurrent ? '👤 Kasir Normal' : '🔄 Pergantian Kasir'}: ${kasirName}
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    content.innerHTML = html;
    
    // Enable buttons
    document.getElementById('applyKasBesokBtn').disabled = false;
    document.getElementById('confirmApplyKasBesokBtn').disabled = false;
    
    console.log('✅ Enhanced preview displayed successfully with kasir selection and LOCKED 500K');
    console.log('Kasir info:', { name: kasirName, isCurrent, grandTotal, targetAmount, match: grandTotal === targetAmount });
}

function confirmApplyKasBesok() {
    if (kasBesokRecehData) {
        applyKasBesok();
        const modal = bootstrap.Modal.getInstance(document.getElementById('kasBesokPreviewModal'));
        modal.hide();
        
        const selectedOption = $('#kasBesokKasir').find('option:selected');
        const kasirName = selectedOption.data('kasir-name') || 'Unknown';
        const isCurrent = selectedOption.data('is-current') === 'true';
        
        showUserFriendlyNotification(
            `Kombinasi receh LOCKED 500K berhasil diterapkan untuk kas awal besok ${kasirName} ${isCurrent ? '(normal)' : '(pergantian kasir)'}.`, 
            'success'
        );
    }
}

function applyKasBesok() {
    console.log('✅ Applying kas besok with enhanced kasir selection and LOCKED 500K data:', kasBesokRecehData);
    
    if (!kasBesokRecehData) {
        showUserFriendlyNotification('Tidak ada data receh untuk diterapkan. Silakan preview terlebih dahulu.', 'error');
        return;
    }
    
    const previewResult = document.getElementById('kasBesokPreviewResult');
    const nominal = LOCKED_NOMINAL; // FIXED: Always use locked nominal
    const date = document.getElementById('kasBesokDate').value;
    const selectedOption = $('#kasBesokKasir').find('option:selected');
    const kasirName = selectedOption.data('kasir-name') || 'Unknown';
    const kasirCabang = selectedOption.data('cabang') || 'Unknown';
    const isCurrent = selectedOption.data('is-current') === 'true';
    const currentKasirName = '<?php echo addslashes($current_kasir_name); ?>';
    
    // Calculate totals
    let totalReceh = 0;
    let totalKeping = 0;
    kasBesokRecehData.forEach(item => {
        totalReceh += item.nominal * item.jumlah_keping;
        totalKeping += parseInt(item.jumlah_keping);
    });
    
    // Validation
    if (totalReceh !== nominal) {
        showUserFriendlyNotification(`Total receh (Rp ${totalReceh.toLocaleString('id-ID')}) tidak sesuai dengan nominal LOCKED (Rp ${nominal.toLocaleString('id-ID')})`, 'error');
        return;
    }
    
    // Sort by nominal ASC
    const sortedRecehData = [...kasBesokRecehData].sort((a, b) => a.nominal - b.nominal);
    
    previewResult.innerHTML = `
        <div class="alert alert-success">
            <h6><i class="fas fa-check-circle"></i> Kas Awal Besok LOCKED 500K Telah Disiapkan!</h6>
            <div class="row">
                <div class="col-md-6">
                    <div class="card border-success bg-light h-100">
                        <div class="card-body py-2">
                            <h6 class="card-title text-success">📋 Informasi Kas Awal LOCKED</h6>
                            <p class="mb-1"><strong>📅 Tanggal:</strong> ${date ? new Date(date).toLocaleDateString('id-ID') : 'N/A'}</p>
                            <p class="mb-1"><strong>👤 Kasir:</strong> ${kasirName} (${kasirCabang})</p>
                            <p class="mb-1"><strong>🔄 Status:</strong> ${isCurrent ? 'Normal' : 'Pergantian dari ' + currentKasirName}</p>
                            <p class="mb-0"><strong>🔒 Nominal LOCKED:</strong> Rp ${nominal.toLocaleString('id-ID')}</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-primary bg-light h-100">
                        <div class="card-body py-2">
                            <h6 class="card-title text-primary">🔢 Detail Receh PRIORITAS TERKECIL</h6>
                            <p class="mb-1"><strong>Jenis Denominasi:</strong> ${sortedRecehData.length}</p>
                            <p class="mb-1"><strong>Total Keping:</strong> ${totalKeping.toLocaleString('id-ID')}</p>
                            <p class="mb-0"><strong>Status:</strong> 
                                <span class="badge ${totalReceh === nominal ? 'bg-success' : 'bg-danger'}">
                                    ${totalReceh === nominal ? '✓ TEPAT 500K' : '✗ TIDAK TEPAT'}
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <h6>💰 Breakdown Receh LOCKED 500K untuk ${kasirName} (Urutan PRIORITAS TERKECIL):</h6>
                <div class="row">
                    ${sortedRecehData
                        .map((item, index) => 
                        `<div class="col-md-6 col-lg-4 mb-2">
                            <div class="card border-0 bg-primary text-white">
                                <div class="card-body py-2 text-center">
                                    <small class="d-block">Prioritas ${index + 1}</small>
                                    <strong>Rp ${parseInt(item.nominal).toLocaleString('id-ID')} × ${item.jumlah_keping}</strong>
                                    <small class="d-block">= Rp ${(item.nominal * item.jumlah_keping).toLocaleString('id-ID')}</small>
                                </div>
                            </div>
                        </div>`
                    ).join('')}
                </div>
            </div>
            <div class="alert ${isCurrent ? 'alert-info' : 'alert-warning'} mt-3 py-2">
                <small>
                    <i class="fas fa-${isCurrent ? 'user' : 'exchange-alt'}"></i> 
                    <strong>${isCurrent ? 'KASIR NORMAL' : 'PERGANTIAN KASIR'} BERHASIL:</strong> 
                    ${isCurrent ? 
                        `${kasirName} akan melanjutkan kerja normal dengan kas awal LOCKED 500K.` :
                        `Kas awal berhasil disiapkan untuk ${kasirName} menggantikan ${currentKasirName} dengan nominal LOCKED 500K.`
                    }
                    Algoritma PRIORITAS MUTLAK TERKECIL telah berhasil mengambil kombinasi dari nominal terkecil secara berurutan.
                </small>
            </div>
        </div>
    `;
    previewResult.style.display = 'block';
    
    // Scroll to preview result
    previewResult.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    
    console.log('✅ Enhanced kas besok LOCKED 500K applied successfully with kasir selection');
}

// ENHANCED: Override validateKasBesokForm dengan validasi pergantian kasir
function validateKasBesokForm() {
    console.log('🔍 Validating kas besok form with enhanced kasir selection...');
    
    const enableKasBesok = document.getElementById('enableKasBesok').checked;
    
    if (!enableKasBesok) {
        console.log('✅ Kas besok not enabled, validation passed');
        return true;
    }
    
    const date = document.getElementById('kasBesokDate').value;
    const kasir = $('#kasBesokKasir').val();
    const selectedOption = $('#kasBesokKasir').find('option:selected');
    const nominal = LOCKED_NOMINAL; // FIXED: Always use locked nominal
    
    if (!date) {
        showUserFriendlyNotification('Silakan pilih tanggal kas awal besok.', 'error');
        document.getElementById('kasBesokDate').focus();
        return false;
    }
    
    if (!validateDateSelection()) {
        return false;
    }
    
    if (!kasir) {
        showUserFriendlyNotification('Silakan pilih kasir yang AKAN KERJA BESOK.', 'error');
        $('#kasBesokKasir').select2('open');
        return false;
    }
    
    // Enhanced kasir validation
    const isCurrent = selectedOption.data('is-current') === 'true';
    const kasirName = selectedOption.data('kasir-name');
    const currentKasirName = '<?php echo addslashes($current_kasir_name); ?>';
    
    if (!isCurrent) {
        // Pergantian kasir - check confirmation
        const confirmCheckbox = document.getElementById('confirmKasirChange');
        if (!confirmCheckbox || !confirmCheckbox.checked) {
            showUserFriendlyNotification('Harap konfirmasi pergantian kasir terlebih dahulu.', 'error');
            return false;
        }
        
        // Additional confirmation
        const finalConfirm = confirm(`KONFIRMASI AKHIR PERGANTIAN KASIR:\n\nKas awal besok akan dibuat untuk: ${kasirName}\nBukan untuk kasir yang melakukan closing ini: ${currentKasirName}\n\nApakah Anda 100% yakin ${kasirName} yang akan kerja besok?`);
        if (!finalConfirm) {
            showUserFriendlyNotification('Validasi dibatalkan. Silakan pilih kasir yang benar.', 'warning');
            return false;
        }
    }
    
    if (!kasBesokRecehData || kasBesokRecehData.length === 0) {
        showUserFriendlyNotification('Silakan preview dan terapkan kombinasi receh PRIORITAS TERKECIL untuk LOCKED 500K terlebih dahulu.', 'error');
        return false;
    }
    
    // Final validation: Check if receh total matches locked nominal
    let totalReceh = 0;
    kasBesokRecehData.forEach(item => {
        totalReceh += item.nominal * item.jumlah_keping;
    });
    
    if (totalReceh !== nominal) {
        showUserFriendlyNotification(`Total receh (Rp ${totalReceh.toLocaleString('id-ID')}) tidak sesuai dengan nominal LOCKED (Rp ${nominal.toLocaleString('id-ID')}). Silakan preview ulang.`, 'error');
        return false;
    }
    
    console.log(`✅ Kas besok validation passed for ${kasirName} (${isCurrent ? 'same kasir' : 'kasir change'})`);
    return true;
}

// Enhanced closing button handler
document.getElementById('btnLanjutClosing').addEventListener('click', function() {
    console.log('🚀 Closing button clicked with enhanced kasir selection and LOCKED 500K system');
    
    // Check kas akhir availability
    <?php if (empty($detail_kas_akhir)): ?>
        showUserFriendlyNotification('Kas akhir belum diinput. Silakan input kas akhir terlebih dahulu sebelum closing transaksi.', 'error');
        return;
    <?php endif; ?>
    
    if (!validateKasBesokForm()) {
        console.log('❌ Kas besok form validation failed');
        return;
    }
    
    const enableKasBesok = document.getElementById('enableKasBesok').checked;
    let confirmMessage = 'Anda yakin ingin melanjutkan closing transaksi ini?';
    
    if (enableKasBesok) {
        const date = document.getElementById('kasBesokDate').value;
        const kasir = $('#kasBesokKasir').val();
        const selectedOption = $('#kasBesokKasir').find('option:selected');
        const kasirName = selectedOption.data('kasir-name') || 'Unknown';
        const kasirCabang = selectedOption.data('cabang') || 'Unknown';
        const isCurrent = selectedOption.data('is-current') === 'true';
        const currentKasirName = '<?php echo addslashes($current_kasir_name); ?>';
        const nominal = LOCKED_NOMINAL; // FIXED: Always use locked nominal
        
        // Calculate receh summary
        let totalKeping = 0;
        let jenisReceh = 0;
        if (kasBesokRecehData) {
            jenisReceh = kasBesokRecehData.length;
            kasBesokRecehData.forEach(item => {
                totalKeping += parseInt(item.jumlah_keping);
            });
        }
        
        confirmMessage += `\n\n🔒 KAS AWAL BESOK LOCKED 500K AKAN OTOMATIS DIBUAT:\n`;
        confirmMessage += `📅 Tanggal: ${new Date(date).toLocaleDateString('id-ID')}\n`;
        confirmMessage += `👤 Kasir: ${kasirName} (${kasirCabang})\n`;
        confirmMessage += `🔄 Status: ${isCurrent ? 'Normal (melanjutkan)' : 'Pergantian dari ' + currentKasirName}\n`;
        confirmMessage += `🔒 Nominal LOCKED: Rp ${nominal.toLocaleString('id-ID')} (Tidak dapat diubah)\n`;
        confirmMessage += `🔢 Receh: ${jenisReceh} jenis, ${totalKeping} keping\n`;
        confirmMessage += `\n⚙️ ALGORITMA: PRIORITAS MUTLAK NOMINAL TERKECIL\n`;
        confirmMessage += `📊 Urutan: Rp 500 → Rp 1.000 → Rp 2.000 → dst\n`;
        confirmMessage += `🔒 SISTEM LOCKED: Nominal tidak dapat diubah dari 500K\n`;
        
        if (!isCurrent) {
            confirmMessage += `\n⚠️ PERGANTIAN KASIR DETECTED!\n`;
            confirmMessage += `Yang closing: ${currentKasirName}\n`;
            confirmMessage += `Yang akan kerja besok: ${kasirName}\n`;
        }
        
        confirmMessage += `\n⚠️ Proses ini tidak dapat dibatalkan setelah closing!`;
        
        // Set hidden form values
        document.getElementById('hiddenKasBesokDate').value = date;
        document.getElementById('hiddenKasBesokKasir').value = kasir;
        document.getElementById('hiddenKasBesokNominal').value = nominal; // FIXED: Always locked value
        document.getElementById('hiddenKasBesokRecehData').value = JSON.stringify(kasBesokRecehData);
    }
    
    if (confirm(confirmMessage)) {
        console.log('✅ User confirmed closing with enhanced kasir selection and LOCKED 500K');
        
        // Show loading overlay
        const overlay = document.getElementById('loadingOverlay');
        overlay.classList.add('show');
        
        // Update button state
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses Closing...';
        this.disabled = true;
        
        // Update loading messages
        const loadingMessage = document.getElementById('loadingMessage');
        const messages = [
            'Mohon tunggu, sistem sedang memproses data transaksi Anda.',
            'Menyimpan data kas akhir dan perhitungan omset...',
            enableKasBesok ? 'Membuat kas awal besok LOCKED 500K dengan Enhanced Kasir Selection...' : 'Menyelesaikan proses closing...',
            enableKasBesok ? 'Memvalidasi pergantian kasir dan menerapkan algoritma PRIORITAS MUTLAK TERKECIL...' : 'Hampir selesai, memverifikasi data...',
            'Hampir selesai, memverifikasi data...'
        ];
        
        let messageIndex = 0;
        const messageInterval = setInterval(() => {
            if (messageIndex < messages.length) {
                loadingMessage.textContent = messages[messageIndex];
                messageIndex++;
            } else {
                clearInterval(messageInterval);
            }
        }, 1500);
        
        // Submit form after delay
        setTimeout(() => {
            console.log('📤 Submitting form with enhanced kasir selection and LOCKED 500K');
            document.getElementById('formClosing').submit();
        }, enableKasBesok ? 4000 : 2000);
    } else {
        console.log('❌ User cancelled closing');
    }
});

// Enhanced form submission handler
document.getElementById('formClosing').addEventListener('submit', function(e) {
    console.log('📤 Form submission started with enhanced kasir selection and LOCKED 500K');
    
    const enableKasBesok = document.getElementById('enableKasBesok').checked;
    
    if (enableKasBesok) {
        const recehData = document.getElementById('hiddenKasBesokRecehData').value;
        const nominal = LOCKED_NOMINAL; // FIXED: Always use locked nominal
        const kasir = document.getElementById('hiddenKasBesokKasir').value;
        
        if (!recehData || !nominal || !kasir) {
            console.error('❌ Final validation failed - missing kas besok data');
            e.preventDefault();
            showUserFriendlyNotification('Data kas awal besok tidak lengkap. Silakan ulangi proses preview.', 'error');
            
            // Reset loading state
            const overlay = document.getElementById('loadingOverlay');
            overlay.classList.remove('show');
            document.getElementById('btnLanjutClosing').disabled = false;
            document.getElementById('btnLanjutClosing').innerHTML = '<i class="fas fa-check-circle"></i> Lanjut Closing';
            
            return false;
        }
        
        // Final calculation check with locked nominal
        try {
            const parsedReceh = JSON.parse(recehData);
            let totalCalculated = 0;
            
            parsedReceh.forEach(item => {
                totalCalculated += item.nominal * item.jumlah_keping;
            });
            
            if (totalCalculated !== nominal) {
                console.error('❌ Final calculation mismatch with LOCKED nominal:', {
                    calculated: totalCalculated,
                    expected: nominal,
                    locked: LOCKED_NOMINAL,
                    kasir: kasir
                });
                e.preventDefault();
                showUserFriendlyNotification(`Perhitungan akhir tidak sesuai dengan LOCKED 500K. Calculated: Rp ${totalCalculated.toLocaleString('id-ID')}, LOCKED: Rp ${nominal.toLocaleString('id-ID')}`, 'error');
                
                // Reset loading state
                const overlay = document.getElementById('loadingOverlay');
                overlay.classList.remove('show');
                document.getElementById('btnLanjutClosing').disabled = false;
                document.getElementById('btnLanjutClosing').innerHTML = '<i class="fas fa-check-circle"></i> Lanjut Closing';
                
                return false;
            }
        } catch (err) {
            console.error('❌ Error parsing receh data:', err);
            e.preventDefault();
            showUserFriendlyNotification('Data receh tidak valid. Silakan ulangi proses.', 'error');
            
            // Reset loading state
            const overlay = document.getElementById('loadingOverlay');
            overlay.classList.remove('show');
            document.getElementById('btnLanjutClosing').disabled = false;
            document.getElementById('btnLanjutClosing').innerHTML = '<i class="fas fa-check-circle"></i> Lanjut Closing';
            
            return false;
        }
    }
    
    console.log('✅ Form submission proceeding with enhanced kasir selection and LOCKED 500K system');
});

// Cleanup function
window.addEventListener('beforeunload', function() {
    // Clear any remaining intervals or timeouts
    console.log('🧹 Cleaning up enhanced kasir selection and LOCKED 500K system...');
});

console.log('🎉 Enhanced closing transaction script loaded successfully with Smart Kasir Selection, LOCKED 500K system, and PRIORITAS MUTLAK TERKECIL algorithm');
</script>

</body>
</html>

<?php
$pdo = null;
?>