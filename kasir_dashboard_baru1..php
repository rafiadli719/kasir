<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set timezone
date_default_timezone_set('Asia/Jakarta');

// Ensure user is logged in and has the correct role
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

// Database connection
$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$kode_karyawan = $_SESSION['kode_karyawan'];
$kode_cabang = $_SESSION['kode_cabang'];
$nama_cabang = $_SESSION['nama_cabang'];

// Fetch kode_user based on kode_karyawan
$sql_kode_user = "SELECT kode_user FROM users WHERE kode_karyawan = :kode_karyawan";
$stmt_kode_user = $pdo->prepare($sql_kode_user);
$stmt_kode_user->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_kode_user->execute();
$kode_user = $stmt_kode_user->fetchColumn();

if (!$kode_user) {
    die("Error: User code (kode_user) not found.");
}

// Function to get kas awal configuration for branch with real-time sync
function getKasAwalConfig($pdo, $kode_cabang) {
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
    
    // If no ACTIVE config found, create default
    if (!$config) {
        $sql_insert_default = "INSERT INTO kas_awal_config (kode_cabang, nama_cabang, nominal_minimum, status, created_by) 
                               VALUES (:kode_cabang, :nama_cabang, 500000, 'active', 'SYSTEM')
                               ON DUPLICATE KEY UPDATE 
                               nama_cabang = :nama_cabang, 
                               status = 'active',
                               updated_at = CURRENT_TIMESTAMP,
                               updated_by = 'SYSTEM'";
        $stmt_insert = $pdo->prepare($sql_insert_default);
        $stmt_insert->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
        $stmt_insert->bindParam(':nama_cabang', $_SESSION['nama_cabang'], PDO::PARAM_STR);
        $stmt_insert->execute();
        
        // Get the newly created config
        $stmt_config->execute();
        $config = $stmt_config->fetch(PDO::FETCH_ASSOC);
    }
    
    return $config;
}

// Function to get previous day's closing cash details with exact amount logic - DIPERBAIKI
function getPreviousDayRecehExact($pdo, $kode_cabang, $selected_date, $required_amount) {
    $debug_info = [];
    $previous_date = date('Y-m-d', strtotime($selected_date . ' -1 day'));
    $debug_info['previous_date'] = $previous_date;
    $debug_info['required_amount'] = floatval($required_amount); // Pastikan numeric
    
    // Check if there are any transactions for this branch
    $sql_debug_branch = "SELECT COUNT(*) as total FROM kasir_transactions WHERE kode_cabang = :kode_cabang";
    $stmt_debug = $pdo->prepare($sql_debug_branch);
    $stmt_debug->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
    $stmt_debug->execute();
    $total_branch_trans = $stmt_debug->fetchColumn();
    $debug_info['total_branch_transactions'] = $total_branch_trans;
    
    if ($total_branch_trans == 0) {
        return [
            'success' => false, 
            'message' => "Tidak ada transaksi untuk cabang ini (kode: $kode_cabang)",
            'debug' => $debug_info
        ];
    }
    
    // Try multiple approaches to find previous day transaction
    $sql_prev_trans_v1 = "SELECT kode_transaksi, tanggal_closing, jam_closing 
                          FROM kasir_transactions 
                          WHERE kode_cabang = :kode_cabang 
                          AND tanggal_closing = :previous_date 
                          AND status = 'end proses' 
                          ORDER BY jam_closing DESC LIMIT 1";
    
    $stmt_prev_trans_v1 = $pdo->prepare($sql_prev_trans_v1);
    $stmt_prev_trans_v1->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
    $stmt_prev_trans_v1->bindParam(':previous_date', $previous_date, PDO::PARAM_STR);
    $stmt_prev_trans_v1->execute();
    $prev_kode_transaksi = $stmt_prev_trans_v1->fetch(PDO::FETCH_ASSOC);
    
    if (!$prev_kode_transaksi) {
        $sql_prev_trans_v2 = "SELECT kode_transaksi, tanggal_transaksi, tanggal_closing 
                              FROM kasir_transactions 
                              WHERE kode_cabang = :kode_cabang 
                              AND tanggal_transaksi = :previous_date 
                              AND status = 'end proses' 
                              ORDER BY id DESC LIMIT 1";
        
        $stmt_prev_trans_v2 = $pdo->prepare($sql_prev_trans_v2);
        $stmt_prev_trans_v2->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
        $stmt_prev_trans_v2->bindParam(':previous_date', $previous_date, PDO::PARAM_STR);
        $stmt_prev_trans_v2->execute();
        $prev_kode_transaksi = $stmt_prev_trans_v2->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$prev_kode_transaksi) {
        $sql_prev_trans_v3 = "SELECT kode_transaksi, tanggal_closing, tanggal_transaksi 
                              FROM kasir_transactions 
                              WHERE kode_cabang = :kode_cabang 
                              AND status = 'end proses' 
                              AND tanggal_closing < :selected_date
                              ORDER BY tanggal_closing DESC, id DESC LIMIT 1";
        
        $stmt_prev_trans_v3 = $pdo->prepare($sql_prev_trans_v3);
        $stmt_prev_trans_v3->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
        $stmt_prev_trans_v3->bindParam(':selected_date', $selected_date, PDO::PARAM_STR);
        $stmt_prev_trans_v3->execute();
        $prev_kode_transaksi = $stmt_prev_trans_v3->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$prev_kode_transaksi) {
        return [
            'success' => false, 
            'message' => "Tidak ada kas akhir yang sudah di-closing untuk cabang ini",
            'debug' => $debug_info,
            'suggestion' => 'Pastikan ada transaksi yang sudah di-closing sebelumnya'
        ];
    }
    
    $kode_transaksi_found = $prev_kode_transaksi['kode_transaksi'];
    $debug_info['found_transaction'] = $prev_kode_transaksi;
    
    // Get all denomination details sorted by nominal ASC (PRIORITAS TERKECIL DULU)
    $sql_all_denominations = "SELECT dka.nominal, dka.jumlah_keping 
                             FROM detail_kas_akhir dka 
                             WHERE dka.kode_transaksi = :kode_transaksi 
                             AND dka.jumlah_keping > 0
                             ORDER BY dka.nominal ASC";
    
    $stmt_all_denominations = $pdo->prepare($sql_all_denominations);
    $stmt_all_denominations->bindParam(':kode_transaksi', $kode_transaksi_found, PDO::PARAM_STR);
    $stmt_all_denominations->execute();
    $all_denominations = $stmt_all_denominations->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($all_denominations)) {
        return [
            'success' => false, 
            'message' => "Tidak ada detail kas akhir untuk transaksi ini",
            'debug' => $debug_info
        ];
    }
    
    // Calculate total for all denominations
    $total_all_denominations = 0;
    foreach ($all_denominations as $denom) {
        $total_all_denominations += $denom['nominal'] * $denom['jumlah_keping'];
    }
    
    // ALGORITMA BARU: Prioritas Nominal Terkecil dengan Kombinasi Optimal
    $target_amount = floatval($required_amount);
    $selected_receh = findOptimalCombination($all_denominations, $target_amount);
    
    $debug_info['algorithm'] = 'optimal_combination_from_smallest';
    $debug_info['available_denominations'] = count($all_denominations);
    
    if ($selected_receh['success']) {
        $current_total = $selected_receh['total'];
        $status = 'exact_match';
        $message = "Berhasil menemukan kombinasi optimal dari nominal terkecil!";
    } else {
        // Fallback ke algorithm alternatif
        $alternative_result = findAlternativeCombination($all_denominations, $target_amount);
        if ($alternative_result['success']) {
            $selected_receh = [
                'success' => true,
                'combination' => $alternative_result['combination'],
                'total' => $target_amount
            ];
            $current_total = $target_amount;
            $status = 'alternative_found';
            $message = "Ditemukan kombinasi alternatif yang tepat!";
        } else {
            $current_total = 0;
            $status = 'insufficient';
            $message = "Tidak dapat membuat kombinasi yang tepat Rp " . number_format($target_amount, 0, ',', '.') . 
                      " dari denominasi yang tersedia.";
        }
    }
    
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
        'previous_date' => $prev_kode_transaksi['tanggal_closing'] ?? $prev_kode_transaksi['tanggal_transaksi'],
        'transaction_code' => $kode_transaksi_found,
        'debug' => $debug_info
    ];
}

// ALGORITMA BARU: Kombinasi Optimal dengan Prioritas Nominal Terkecil
function findOptimalCombination($denominations, $target_amount) {
    $target = intval($target_amount);
    $result = [];
    $remaining = $target;
    
    // STEP 1: Gunakan Dynamic Programming untuk mencari kombinasi terbaik
    // yang memprioritaskan nominal kecil dengan jumlah keping yang wajar
    
    // Sort denominations ascending (terkecil dulu)
    usort($denominations, function($a, $b) {
        return $a['nominal'] - $b['nominal'];
    });
    
    // Coba kombinasi optimal mulai dari nominal terkecil
    foreach ($denominations as $denom) {
        if ($remaining <= 0) break;
        
        $nominal = intval($denom['nominal']);
        $available_pieces = intval($denom['jumlah_keping']);
        
        if ($available_pieces > 0 && $nominal <= $remaining) {
            // Hitung berapa keping maksimal yang dibutuhkan
            $max_needed = floor($remaining / $nominal);
            
            // Batasi agar tidak mengambil terlalu banyak dari satu denominasi
            // Prioritaskan variasi denominasi
            $pieces_to_take = min($max_needed, $available_pieces);
            
            // Untuk nominal kecil, batasi jumlah maksimal agar ada variasi
            if ($nominal <= 5000) {
                $pieces_to_take = min($pieces_to_take, 50); // Max 50 keping untuk nominal <= 5000
            } elseif ($nominal <= 20000) {
                $pieces_to_take = min($pieces_to_take, 25); // Max 25 keping untuk nominal <= 20000
            } elseif ($nominal <= 50000) {
                $pieces_to_take = min($pieces_to_take, 10); // Max 10 keping untuk nominal <= 50000
            } else {
                $pieces_to_take = min($pieces_to_take, 5);  // Max 5 keping untuk nominal besar
            }
            
            if ($pieces_to_take > 0) {
                $value_taken = $nominal * $pieces_to_take;
                
                $result[] = [
                    'nominal' => $nominal,
                    'jumlah_keping' => $pieces_to_take
                ];
                
                $remaining -= $value_taken;
            }
        }
    }
    
    // STEP 2: Jika masih ada sisa, coba optimasi ulang
    if ($remaining > 0) {
        // Cari denominasi yang bisa menutupi sisa
        foreach ($denominations as $denom) {
            if ($remaining <= 0) break;
            
            $nominal = intval($denom['nominal']);
            $available_pieces = intval($denom['jumlah_keping']);
            
            if ($nominal == $remaining && $available_pieces > 0) {
                // Perfect match found
                $result[] = [
                    'nominal' => $nominal,
                    'jumlah_keping' => 1
                ];
                $remaining = 0;
                break;
            }
        }
    }
    
    // STEP 3: Hitung total hasil
    $total_result = 0;
    foreach ($result as $item) {
        $total_result += $item['nominal'] * $item['jumlah_keping'];
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

// Get kas awal configuration for current branch
$kas_awal_config = getKasAwalConfig($pdo, $kode_cabang);

// Handle AJAX request for preview
if (isset($_GET['action']) && $_GET['action'] === 'preview_receh') {
    header('Content-Type: application/json');
    $selected_date = $_GET['date'] ?? '';
    
    if ($selected_date) {
        $result = getPreviousDayRecehExact($pdo, $kode_cabang, $selected_date, $kas_awal_config['nominal_minimum']);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'message' => 'Tanggal tidak valid']);
    }
    exit;
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
        $last_update_timestamp = strtotime($latest_config['updated_at']) * 1000; // Convert to milliseconds
        
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

// Handle AJAX request for updating config
if (isset($_POST['action']) && $_POST['action'] === 'update_config') {
    header('Content-Type: application/json');
    $new_nominal = floatval($_POST['nominal_minimum']);
    
    if ($new_nominal >= 100000) { // Minimum 100k
        $sql_update = "UPDATE kas_awal_config SET nominal_minimum = :nominal WHERE kode_cabang = :kode_cabang";
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->bindParam(':nominal', $new_nominal, PDO::PARAM_STR);
        $stmt_update->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
        
        if ($stmt_update->execute()) {
            echo json_encode(['success' => true, 'message' => 'Konfigurasi berhasil diperbarui']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal memperbarui konfigurasi']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Nominal minimum harus minimal Rp 100.000']);
    }
    exit;
}

// Part 1: Verify Starting Cash and Block Dates with Existing Transactions
if (!isset($_SESSION['selected_date'])) {
    $sql_existing_dates = "SELECT DISTINCT DATE(tanggal_transaksi) as tanggal_transaksi 
                           FROM kasir_transactions WHERE kode_karyawan = :kode_karyawan AND kode_cabang = :kode_cabang";
    $stmt_existing_dates = $pdo->prepare($sql_existing_dates);
    $stmt_existing_dates->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
    $stmt_existing_dates->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
    $stmt_existing_dates->execute();
    $existing_dates = $stmt_existing_dates->fetchAll(PDO::FETCH_COLUMN);

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Verify Starting Cash</title>
        <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var dateInput = document.getElementById('selected_date');
                var blockedDates = <?php echo json_encode($existing_dates); ?>;

                dateInput.addEventListener('input', function() {
                    var selectedDate = this.value;
                    if (blockedDates.includes(selectedDate)) {
                        alert('Tanggal yang Anda pilih sudah memiliki kas awal untuk cabang ini. Silakan pilih tanggal lain.');
                        this.value = '';
                    }
                });
            });
        </script>
    </head>
    <body>
        <div class="container mt-5">
            <h1>Verify Starting Cash</h1>
            <p>Select a new transaction date. You cannot select a date that already has a transaction in the same branch.</p>
            
            <div class="alert alert-info">
                <h6>Konfigurasi Kas Awal - <?php echo htmlspecialchars($nama_cabang); ?></h6>
                <p><strong>Nominal Minimum Kas Awal: Rp <?php echo number_format($kas_awal_config['nominal_minimum'], 0, ',', '.'); ?></strong></p>
                <small class="text-muted">Sistem akan secara otomatis mengambil uang receh dari closing sebelumnya sesuai nominal yang diwajibkan.</small>
            </div>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="selected_date">Select Date:</label>
                    <input type="date" id="selected_date" name="selected_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <button type="submit" class="btn btn-primary mt-3">Continue</button>
            </form>

            <div class="mt-3">
                <button class="btn btn-danger" onclick="window.location.href='index_kasir.php'">Return to Dashboard</button>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Retrieve the selected date from the previous form input
$tanggal_transaksi_baru = $_POST['selected_date'] ?? $_SESSION['selected_date'];
$_SESSION['selected_date'] = $tanggal_transaksi_baru;

// Part 2: Process New Transaction Creation
$sql_count_transaksi = "SELECT COUNT(*) as total FROM kasir_transactions WHERE tanggal_transaksi = :tanggal_transaksi AND kode_cabang = :kode_cabang";
$stmt_count = $pdo->prepare($sql_count_transaksi);
$stmt_count->bindParam(':tanggal_transaksi', $tanggal_transaksi_baru, PDO::PARAM_STR);
$stmt_count->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
$stmt_count->execute();
$total_transaksi_hari_ini = $stmt_count->fetchColumn();

$year = date('Y', strtotime($tanggal_transaksi_baru));
$month = date('m', strtotime($tanggal_transaksi_baru));
$day = date('d', strtotime($tanggal_transaksi_baru));
$transaction_number = str_pad($total_transaksi_hari_ini + 1, 4, '0', STR_PAD_LEFT);
$kode_transaksi = "TRX-$year$month$day-$kode_user$transaction_number";

while (true) {
    $sql_check_duplicate = "SELECT COUNT(*) FROM kasir_transactions WHERE kode_transaksi = :kode_transaksi";
    $stmt_check_duplicate = $pdo->prepare($sql_check_duplicate);
    $stmt_check_duplicate->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
    $stmt_check_duplicate->execute();
    $exists = $stmt_check_duplicate->fetchColumn();

    if ($exists == 0) {
        break;
    }

    $transaction_number = str_pad((int)$transaction_number + 1, 4, '0', STR_PAD_LEFT);
    $kode_transaksi = "TRX-$year$month$day-$kode_user$transaction_number";
}

// Part 3: Starting Cash and Cash Entry Processing
$error_message = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['kas_awal'])) {
    $kas_awal = isset($_POST['kas_awal']) ? floatval($_POST['kas_awal']) : 0;
    $waktu = '08:00:00';

    // Check if kas_awal meets minimum requirement
    if ($kas_awal < $kas_awal_config['nominal_minimum']) {
        $error_message = "Kas awal harus minimal Rp " . number_format($kas_awal_config['nominal_minimum'], 0, ',', '.') . 
                        ". Saat ini: Rp " . number_format($kas_awal, 0, ',', '.');
    } else {
        $kepingFilled = false;
        if (isset($_POST['keping']) && is_array($_POST['keping'])) {
            foreach ($_POST['keping'] as $nominal => $jumlah_keping) {
                if (!is_numeric($jumlah_keping) || $jumlah_keping < 0 || floor($jumlah_keping) != $jumlah_keping) {
                    $error_message = "Number of coins must be a non-negative integer.";
                    break;
                }
                if ($jumlah_keping > 0) {
                    $kepingFilled = true;
                }
            }
        }

        if ($kas_awal == 0 || !$kepingFilled) {
            $error_message = "Total starting cash cannot be 0, and at least one coin entry must be filled.";
        } else {
            try {
                $sql_kas_awal = "INSERT INTO kas_awal (kode_transaksi, kode_karyawan, total_nilai, tanggal, waktu) 
                                 VALUES (:kode_transaksi, :kode_karyawan, :total_nilai, :tanggal, :waktu)";
                $stmt_kas_awal = $pdo->prepare($sql_kas_awal);
                $stmt_kas_awal->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
                $stmt_kas_awal->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
                $stmt_kas_awal->bindParam(':total_nilai', $kas_awal, PDO::PARAM_STR);
                $stmt_kas_awal->bindParam(':tanggal', $tanggal_transaksi_baru, PDO::PARAM_STR);
                $stmt_kas_awal->bindParam(':waktu', $waktu, PDO::PARAM_STR);
                $stmt_kas_awal->execute();

                if (isset($_POST['keping']) && is_array($_POST['keping'])) {
                    foreach ($_POST['keping'] as $nominal => $jumlah_keping) {
                        if ($jumlah_keping > 0) {
                            $sql_detail_kas_awal = "INSERT INTO detail_kas_awal (kode_transaksi, nominal, jumlah_keping) 
                                                    VALUES (:kode_transaksi, :nominal, :jumlah_keping)";
                            $stmt_detail = $pdo->prepare($sql_detail_kas_awal);
                            $stmt_detail->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
                            $stmt_detail->bindParam(':nominal', $nominal, PDO::PARAM_INT);
                            $stmt_detail->bindParam(':jumlah_keping', $jumlah_keping, PDO::PARAM_INT);
                            $stmt_detail->execute();
                        }
                    }
                }

                $sql_trans = "INSERT INTO kasir_transactions (kode_karyawan, kode_transaksi, kas_awal, tanggal_transaksi, status, kode_cabang, nama_cabang) 
                              VALUES (:kode_karyawan, :kode_transaksi, :kas_awal, :tanggal_transaksi, 'on proses', :kode_cabang, :nama_cabang)";
                $stmt_trans = $pdo->prepare($sql_trans);
                $stmt_trans->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
                $stmt_trans->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
                $stmt_trans->bindParam(':kas_awal', $kas_awal, PDO::PARAM_STR);
                $stmt_trans->bindParam(':tanggal_transaksi', $tanggal_transaksi_baru, PDO::PARAM_STR);
                $stmt_trans->bindParam(':kode_cabang', $kode_cabang, PDO::PARAM_STR);
                $stmt_trans->bindParam(':nama_cabang', $nama_cabang, PDO::PARAM_STR);
                $stmt_trans->execute();

                $_SESSION['kode_transaksi'] = $kode_transaksi;
                header("Location: index_kasir.php");
                exit;
            } catch (Exception $e) {
                echo "Error: " . $e->getMessage();
            }
        }
    }
}

// Fetch coin denominations - URUTAN TERBESAR DI ATAS
$sql_keping = "SELECT nominal FROM keping ORDER BY nominal DESC";
$stmt_keping = $pdo->query($sql_keping);
$keping_data = $stmt_keping->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kas Awal Kasir</title>
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { margin-top: 20px; }
        .preview-modal { max-height: 70vh; overflow-y: auto; }
        .receh-checkbox { margin-bottom: 15px; }
        .preview-btn { margin-left: 10px; }
        .alert-info { background-color: #e7f3ff; border-color: #b8daff; color: #0c5460; }
        .side-by-side { display: flex; gap: 20px; }
        .side-by-side > div { flex: 1; }
        .table-sm th, .table-sm td { font-size: 0.9rem; }
        .config-card { border-left: 4px solid #007bff; }
        .exact-match { border-left: 4px solid #28a745; }
        .insufficient-match { border-left: 4px solid #dc3545; }
        .alternative-match { border-left: 4px solid #ffc107; }
    </style>
</head>
<body>
    <div class="mt-3">
        <button class="btn btn-danger" onclick="window.location.href='index_kasir.php'">Kembali ke Dashboard</button>
    </div>

    <div class="container">
        <header class="text-center mb-4">
            <h1>Kas Awal Kasir</h1>
            <p>Tanggal Transaksi Baru: <strong><?php echo htmlspecialchars($tanggal_transaksi_baru); ?></strong></p>
            <h2 class="text-muted">Perhitungan Keping dan Total Nilai</h2>
        </header>

        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <!-- Configuration Card with Real-time Sync -->
        <div class="card mb-4 config-card">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Konfigurasi Kas Awal - <?php echo htmlspecialchars($nama_cabang); ?></h5>
                <div>
                    <button class="btn btn-light btn-sm me-2" onclick="refreshConfig()" title="Refresh Konfigurasi">
                        <i class="fa fa-refresh"></i>
                    </button>
                    <?php if (in_array($_SESSION['role'], ['admin', 'super_admin'])): ?>
                    <button class="btn btn-warning btn-sm" onclick="window.open('kas_awal_config_crud.php', '_blank')" title="Kelola Konfigurasi">
                        <i class="fa fa-cog"></i> Kelola
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Nominal Minimum Kas Awal:</strong></p>
                        <h4 class="text-primary" id="currentNominal">Rp <?php echo number_format($kas_awal_config['nominal_minimum'], 0, ',', '.'); ?></h4>
                        <small class="text-muted">ID Config: #<?php echo $kas_awal_config['id']; ?></small>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Status:</strong> 
                            <span class="badge bg-success">Aktif</span>
                            <button class="btn btn-outline-info btn-sm ms-2" onclick="checkConfigChanges()" title="Cek Perubahan">
                                <i class="fa fa-sync"></i>
                            </button>
                        </p>
                        <p><strong>Terakhir diupdate:</strong> <small id="lastUpdated"><?php echo date('d/m/Y H:i', strtotime($kas_awal_config['updated_at'])); ?></small></p>
                        <p><strong>Oleh:</strong> <small><?php echo htmlspecialchars($kas_awal_config['updated_by'] ?? $kas_awal_config['created_by'] ?? 'SYSTEM'); ?></small></p>
                    </div>
                </div>
                <div class="mt-2">
                    <div class="alert alert-info py-2 mb-0">
                        <small><i class="fa fa-info-circle"></i> 
                        Sistem akan otomatis sinkron dengan konfigurasi terbaru. 
                        Klik refresh jika ada perubahan dari admin.</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Receh Option Section -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Ambil Uang Receh dari Closing Sebelumnya</h5>
            </div>
            <div class="card-body">
                <div class="receh-checkbox">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="useReceh" onchange="toggleRecehOption()">
                        <label class="form-check-label" for="useReceh">
                            <strong>✓ Ambil uang receh dari kas akhir sebelumnya</strong>
                            <small class="text-muted d-block">
                                Nominal yang dibutuhkan: <strong>Rp <?php echo number_format($kas_awal_config['nominal_minimum'], 0, ',', '.'); ?></strong><br>
                                Tanggal closing yang dicari: <?php echo date('d-m-Y', strtotime($tanggal_transaksi_baru . ' -1 day')); ?><br>
                                <span class="text-info">Algoritma: Prioritas nominal terkecil → variasi optimal</span>
                            </small>
                        </label>
                    </div>
                </div>
                
                <div id="recehActions" style="display: none;">
                    <button type="button" class="btn btn-outline-primary preview-btn" onclick="previewReceh()">
                        <i class="fa fa-eye"></i> Cek Ketersediaan Receh
                    </button>
                    <button type="button" class="btn btn-success" id="applyRecehBtn" onclick="applyReceh()" disabled>
                        <i class="fa fa-check"></i> Gunakan Receh Ini
                    </button>
                    <div class="mt-2">
                        <small class="text-info">
                            <i class="fa fa-info-circle"></i> 
                            <strong>Logika Pengambilan:</strong> Sistem akan mengambil uang dari nominal terkecil terlebih dahulu
                            hingga mencapai nominal yang tepat (<strong>harus pas, tidak boleh kurang</strong>)
                        </small>
                    </div>
                </div>
                
                <div id="recehPreviewResult" class="mt-3" style="display: none;"></div>
            </div>
        </div>

        <form method="POST" action="">
            <table class="table table-striped table-bordered text-center">
                <thead class="table-light">
                    <tr>
                        <th>NOMINAL</th>
                        <th>x</th>
                        <th>KEPING</th>
                        <th>=</th>
                        <th>TOTAL NILAI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keping_data as $row): 
                        $nominal = $row['nominal'];
                        $id = "keping_" . $nominal;
                    ?>
                        <tr>
                            <td><?php echo number_format($nominal, 0, ',', '.'); ?></td>
                            <td>x</td>
                            <td>
                                <input type="number" id="<?php echo $id; ?>" name="keping[<?php echo $nominal; ?>]" 
                                       class="form-control" value="" oninput="hitungTotal('<?php echo $nominal; ?>')" 
                                       min="0" step="1">
                            </td>
                            <td>=</td>
                            <td>
                                <input type="text" id="total_<?php echo $nominal; ?>" class="form-control" value="Rp 0" readonly>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="total_nilai" class="form-label">Total Kas Awal:</label>
                    <input type="text" id="kas_awal_display" class="form-control" value="Rp 0" readonly>
                    <input type="hidden" id="kas_awal" name="kas_awal" value="0">
                </div>
                <div class="col-md-6">
                    <label for="status_minimum" class="form-label">Status Minimum:</label>
                    <input type="text" id="status_minimum" class="form-control" value="Belum Mencukupi" readonly>
                </div>
            </div>

            <button type="submit" class="btn btn-success" id="submitBtn" disabled>Simpan Kas Awal</button>
        </form>
    </div>

    <!-- Preview Modal -->
    <div class="modal fade" id="recehPreviewModal" tabindex="-1" aria-labelledby="recehPreviewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="recehPreviewModalLabel">Preview Uang Receh dari Closing Sebelumnya</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body preview-modal" id="recehPreviewContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-success" id="confirmApplyBtn" onclick="confirmApplyReceh()" disabled>Gunakan Receh Ini</button>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        let recehData = null;
        let configData = <?php echo json_encode($kas_awal_config); ?>;
        let lastConfigCheck = Date.now();

        // Real-time config sync functions
        function refreshConfig() {
            fetch('?action=get_config&kode_cabang=<?php echo $kode_cabang; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        configData = data.config;
                        updateConfigDisplay();
                        checkMinimumRequirement();
                        showAlert('success', 'Konfigurasi berhasil diperbarui!');
                    } else {
                        showAlert('error', 'Gagal memperbarui konfigurasi: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('error', 'Terjadi kesalahan saat memperbarui konfigurasi');
                });
        }

        function checkConfigChanges() {
            fetch('?action=check_config_changes&kode_cabang=<?php echo $kode_cabang; ?>&last_check=' + lastConfigCheck)
                .then(response => response.json())
                .then(data => {
                    if (data.has_changes) {
                        showAlert('warning', 'Konfigurasi telah berubah! Klik refresh untuk memperbarui.');
                        document.querySelector('[onclick="refreshConfig()"]').classList.add('btn-warning');
                        document.querySelector('[onclick="refreshConfig()"] i').classList.add('fa-spin');
                    } else {
                        showAlert('info', 'Tidak ada perubahan konfigurasi.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }

        function updateConfigDisplay() {
            const nominalFormatted = parseInt(configData.nominal_minimum).toLocaleString('id-ID');
            document.getElementById('currentNominal').textContent = 'Rp ' + nominalFormatted;
            document.getElementById('lastUpdated').textContent = new Date(configData.updated_at).toLocaleDateString('id-ID') + ' ' + new Date(configData.updated_at).toLocaleTimeString('id-ID');
            
            // Update target di section receh juga
            const recehSection = document.querySelector('label[for="useReceh"] small');
            if (recehSection) {
                const newText = recehSection.innerHTML.replace(/Rp [\d.,]+/g, 'Rp ' + nominalFormatted);
                recehSection.innerHTML = newText;
            }
            
            lastConfigCheck = Date.now();
        }

        // Auto-check for config changes every 30 seconds
        setInterval(checkConfigChanges, 30000);

        function showAlert(type, message) {
            // Remove existing alerts
            const existingAlerts = document.querySelectorAll('.alert-floating');
            existingAlerts.forEach(alert => alert.remove());
            
            const alertClass = type === 'success' ? 'alert-success' : 
                             type === 'warning' ? 'alert-warning' :
                             type === 'info' ? 'alert-info' : 'alert-danger';
            const iconClass = type === 'success' ? 'fa-check-circle' : 
                            type === 'warning' ? 'fa-exclamation-triangle' :
                            type === 'info' ? 'fa-info-circle' : 'fa-exclamation-circle';
            
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert ${alertClass} alert-dismissible fade show alert-floating`;
            alertDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            alertDiv.innerHTML = `
                <i class="fas ${iconClass}"></i> ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(alertDiv);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }

        function toggleRecehOption() {
            const checkbox = document.getElementById('useReceh');
            const actions = document.getElementById('recehActions');
            const previewResult = document.getElementById('recehPreviewResult');
            
            if (checkbox.checked) {
                actions.style.display = 'block';
            } else {
                actions.style.display = 'none';
                previewResult.style.display = 'none';
                recehData = null;
                document.getElementById('applyRecehBtn').disabled = true;
            }
        }

        function previewReceh() {
            const selectedDate = '<?php echo $tanggal_transaksi_baru; ?>';
            
            document.getElementById('recehPreviewContent').innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div><p>Menganalisis ketersediaan receh...</p></div>';
            
            const modal = new bootstrap.Modal(document.getElementById('recehPreviewModal'));
            modal.show();
            
            fetch(`?action=preview_receh&date=${selectedDate}`)
                .then(response => response.json())
                .then(data => {
                    displayRecehPreview(data);
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('recehPreviewContent').innerHTML = '<div class="alert alert-danger">Terjadi kesalahan saat memuat data receh.</div>';
                });
        }

        function displayRecehPreview(data) {
            const content = document.getElementById('recehPreviewContent');
            
            if (!data.success) {
                let alertClass = 'alert-warning';
                if (data.status === 'insufficient') {
                    alertClass = 'alert-danger';
                }
                
                content.innerHTML = `
                    <div class="alert ${alertClass}">
                        <h6>❌ ${data.message}</h6>
                        <p><strong>Target yang dibutuhkan:</strong> Rp ${parseInt(configData.nominal_minimum).toLocaleString('id-ID')}</p>
                        ${data.debug ? '<details><summary>Debug Info</summary><pre>' + JSON.stringify(data.debug, null, 2) + '</pre></details>' : ''}
                    </div>
                `;
                return;
            }
            
            recehData = data.selected_receh;
            
            let statusClass = '';
            let statusIcon = '';
            let statusText = '';
            
            if (data.status === 'exact_match') {
                statusClass = 'exact-match';
                statusIcon = '✅';
                statusText = 'Kombinasi Optimal Ditemukan!';
            } else if (data.status === 'alternative_found') {
                statusClass = 'alternative-match';
                statusIcon = '⚡';
                statusText = 'Kombinasi Alternatif Ditemukan!';
            }
            
            // Format target amount dengan benar
            const targetAmount = parseInt(data.required_amount);
            const resultAmount = parseInt(data.total_selected_receh);
            const allDenomTotal = parseInt(data.total_all_denominations);
            
            let html = `
                <div class="alert alert-success ${statusClass}">
                    <h6>${statusIcon} ${statusText}</h6>
                    <p><strong>Target (nominal kas awal):</strong> Rp ${targetAmount.toLocaleString('id-ID')}</p>
                    <p><strong>Hasil kombinasi:</strong> Rp ${resultAmount.toLocaleString('id-ID')}</p>
                    <p><strong>Data dari Tanggal Closing:</strong> ${data.previous_date}</p>
                    <p><strong>Algoritma:</strong> Prioritas nominal terkecil dengan variasi optimal</p>
                </div>
                
                <div class="side-by-side">
                    <div>
                        <h6>💰 Semua Denominasi Tersedia</h6>
                        <p><small>Total keseluruhan: <strong>Rp ${allDenomTotal.toLocaleString('id-ID')}</strong></small></p>
                        <div style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-striped table-sm">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Nominal</th>
                                        <th>Keping</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
            `;
            
            data.all_denominations.forEach(item => {
                const nominal = parseInt(item.nominal);
                const keping = parseInt(item.jumlah_keping);
                const totalNilai = nominal * keping;
                html += `
                    <tr>
                        <td>Rp ${nominal.toLocaleString('id-ID')}</td>
                        <td>${keping.toLocaleString('id-ID')}</td>
                        <td>Rp ${totalNilai.toLocaleString('id-ID')}</td>
                    </tr>
                `;
            });
            
            html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div>
                        <h6>🎯 Kombinasi Optimal untuk Kas Awal</h6>
                        <p><small>Total tepat: <strong>Rp ${resultAmount.toLocaleString('id-ID')}</strong></small></p>
                        <div class="alert alert-info py-2 mb-2">
                            <small><i class="fa fa-lightbulb"></i> <strong>Strategi:</strong> Mengutamakan nominal kecil dengan variasi seimbang</small>
                        </div>
                        <table class="table table-striped table-sm">
                            <thead class="table-success">
                                <tr>
                                    <th>Nominal</th>
                                    <th>Keping</th>
                                    <th>Total</th>
                                    <th>%</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            let grandTotal = 0;
            data.selected_receh.forEach(item => {
                const nominal = parseInt(item.nominal);
                const keping = parseInt(item.jumlah_keping);
                const totalNilai = nominal * keping;
                const percentage = ((totalNilai / resultAmount) * 100).toFixed(1);
                grandTotal += totalNilai;
                
                html += `
                    <tr>
                        <td>Rp ${nominal.toLocaleString('id-ID')}</td>
                        <td>${keping.toLocaleString('id-ID')}</td>
                        <td>Rp ${totalNilai.toLocaleString('id-ID')}</td>
                        <td>${percentage}%</td>
                    </tr>
                `;
            });
            
            html += `
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="2">TOTAL</th>
                                    <th>Rp ${grandTotal.toLocaleString('id-ID')}</th>
                                    <th>100%</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="alert alert-success">
                            <strong>✅ Keunggulan Kombinasi:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Prioritas nominal terkecil dulu</li>
                                <li>Variasi denominasi seimbang</li>
                                <li>Hasil tepat ${targetAmount.toLocaleString('id-ID')}</li>
                                <li>Mudah dihitung & diverifikasi</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-info">
                            <strong>💡 Detail Algoritma:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Sort denominasi ASC (kecil → besar)</li>
                                <li>Batasi max keping per denominasi</li>
                                <li>Optimasi untuk variasi optimal</li>
                                <li>Validasi hasil = target</li>
                            </ul>
                        </div>
                    </div>
                </div>
            `;
            
            content.innerHTML = html;
            document.getElementById('applyRecehBtn').disabled = false;
            document.getElementById('confirmApplyBtn').disabled = false;
        }

        function confirmApplyReceh() {
            if (recehData) {
                applyReceh();
                const modal = bootstrap.Modal.getInstance(document.getElementById('recehPreviewModal'));
                modal.hide();
            }
        }

        function applyReceh() {
            if (!recehData) {
                alert('Tidak ada data receh untuk diterapkan. Silakan preview terlebih dahulu.');
                return;
            }
            
            // Clear existing values
            <?php foreach ($keping_data as $row): ?>
                document.getElementById('keping_<?php echo $row['nominal']; ?>').value = '';
                document.getElementById('total_<?php echo $row['nominal']; ?>').value = 'Rp 0';
            <?php endforeach; ?>
            
            // Apply selected receh data
            recehData.forEach(item => {
                const input = document.getElementById('keping_' + item.nominal);
                if (input) {
                    input.value = item.jumlah_keping;
                    hitungTotal(item.nominal);
                }
            });
            
            // Show confirmation
            const previewResult = document.getElementById('recehPreviewResult');
            previewResult.innerHTML = `
                <div class="alert alert-success">
                    <i class="fa fa-check-circle"></i> 
                    <strong>Receh berhasil diterapkan!</strong><br>
                    <small>Kombinasi uang receh telah dimasukkan ke dalam form dengan nominal yang tepat sesuai konfigurasi terbaru.</small>
                </div>
            `;
            previewResult.style.display = 'block';
            
            hitungKasAwal();
        }

        function checkMinimumRequirement() {
            const kasAwal = parseFloat(document.getElementById('kas_awal').value) || 0;
            const minimumRequired = parseInt(configData.nominal_minimum);
            const statusElement = document.getElementById('status_minimum');
            const submitBtn = document.getElementById('submitBtn');
            
            if (kasAwal >= minimumRequired) {
                statusElement.value = '✅ Mencukupi (Rp ' + kasAwal.toLocaleString('id-ID') + ')';
                statusElement.className = 'form-control text-success';
                submitBtn.disabled = false;
            } else {
                const kekurangan = minimumRequired - kasAwal;
                statusElement.value = `❌ Kurang Rp ${kekurangan.toLocaleString('id-ID')} (Min: Rp ${minimumRequired.toLocaleString('id-ID')})`;
                statusElement.className = 'form-control text-danger';
                submitBtn.disabled = true;
            }
        }

        function hitungKasAwal() {
            var totalKasAwal = 0;

            <?php foreach ($keping_data as $row): 
                $nominal = $row['nominal']; ?>
                var keping_<?php echo $nominal; ?> = document.getElementById('keping_<?php echo $nominal; ?>').value || 0;
                totalKasAwal += <?php echo $nominal; ?> * keping_<?php echo $nominal; ?>;
            <?php endforeach; ?>

            var totalFormatted = "Rp " + totalKasAwal.toLocaleString('id-ID', { minimumFractionDigits: 0 });
            document.getElementById('kas_awal_display').value = totalFormatted;
            document.getElementById('kas_awal').value = totalKasAwal;
            
            checkMinimumRequirement();
        }

        function hitungTotal(nominal) {
            var keping = document.getElementById('keping_' + nominal).value;

            if (keping < 0 || keping % 1 !== 0) {
                alert('Jumlah keping harus berupa bilangan bulat positif.');
                document.getElementById('keping_' + nominal).value = 0;
                keping = 0;
            }

            var totalNilai = nominal * keping;

            var totalFormatted = "Rp " + totalNilai.toLocaleString('id-ID', { minimumFractionDigits: 0 });
            document.getElementById('total_' + nominal).value = totalFormatted;
            hitungKasAwal();
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            checkMinimumRequirement();
            
            // Show notification if this is a fresh load
            setTimeout(() => {
                showAlert('info', 'Sistem kas awal telah tersinkronisasi dengan konfigurasi terbaru.');
            }, 1000);
        });
    </script>
</body>
</html>