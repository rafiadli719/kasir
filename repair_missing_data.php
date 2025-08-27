<?php
/**
 * Script untuk memperbaiki data cabang yang missing di tabel kasir_transactions
 * Jalankan script ini sekali untuk memperbaiki data yang sudah ada
 * 
 * Usage: php repair_missing_data.php
 * atau akses melalui browser: http://yoursite.com/path/repair_missing_data.php
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(0); // Remove execution time limit

// Database connection
$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<h2>✓ Database connection successful</h2>";
} catch (PDOException $e) {
    die("<h2>✗ Database connection failed: " . $e->getMessage() . "</h2>");
}

// Include session helper
if (file_exists('session_helper.php')) {
    include_once 'session_helper.php';
    echo "<h2>✓ Session helper loaded</h2>";
} else {
    echo "<h2>⚠ Session helper not found - akan melanjutkan tanpa helper</h2>";
}

echo "<h1>🔧 REPAIR MISSING BRANCH DATA</h1>";
echo "<h3>Memulai proses perbaikan data...</h3>";

// 1. Identifikasi records yang missing branch data
echo "<hr><h3>📋 Step 1: Identifying missing data</h3>";

$sql_missing = "SELECT id, kode_transaksi, kode_karyawan, tanggal_transaksi, kode_cabang, nama_cabang 
                FROM kasir_transactions 
                WHERE (kode_cabang IS NULL OR nama_cabang IS NULL 
                   OR kode_cabang = '' OR nama_cabang = '' 
                   OR kode_cabang = '0' OR nama_cabang = '0')
                ORDER BY tanggal_transaksi DESC";

$stmt_missing = $pdo->query($sql_missing);
$missing_records = $stmt_missing->fetchAll(PDO::FETCH_ASSOC);

echo "<p><strong>Found " . count($missing_records) . " records with missing branch data.</strong></p>";

if (count($missing_records) > 0) {
    echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
    echo "<tr style='background-color: #f0f0f0;'>";
    echo "<th>ID</th><th>Kode Transaksi</th><th>Karyawan</th><th>Tanggal</th><th>Kode Cabang</th><th>Nama Cabang</th>";
    echo "</tr>";
    
    foreach ($missing_records as $record) {
        echo "<tr>";
        echo "<td>" . $record['id'] . "</td>";
        echo "<td>" . htmlspecialchars($record['kode_transaksi']) . "</td>";
        echo "<td>" . htmlspecialchars($record['kode_karyawan']) . "</td>";
        echo "<td>" . htmlspecialchars($record['tanggal_transaksi']) . "</td>";
        echo "<td>" . htmlspecialchars($record['kode_cabang'] ?: 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($record['nama_cabang'] ?: 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 2. Perbaiki data berdasarkan tabel users
echo "<hr><h3>🔧 Step 2: Repairing data from users table</h3>";

$fixed_count = 0;
$failed_fixes = [];
$fix_details = [];

foreach ($missing_records as $record) {
    $kode_karyawan = $record['kode_karyawan'];
    $kode_transaksi = $record['kode_transaksi'];
    $record_id = $record['id'];
    
    echo "<p>Processing: <strong>$kode_transaksi</strong> (Karyawan: $kode_karyawan)... ";
    
    // Ambil data cabang dari tabel users
    $sql_user = "SELECT kode_cabang, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan";
    $stmt_user = $pdo->prepare($sql_user);
    $stmt_user->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
    $stmt_user->execute();
    $user_data = $stmt_user->fetch(PDO::FETCH_ASSOC);
    
    if ($user_data && !empty($user_data['kode_cabang']) && !empty($user_data['nama_cabang'])) {
        // Update record dengan data dari users
        $sql_update = "UPDATE kasir_transactions 
                      SET kode_cabang = :kode_cabang, nama_cabang = :nama_cabang 
                      WHERE id = :id";
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->bindParam(':kode_cabang', $user_data['kode_cabang'], PDO::PARAM_STR);
        $stmt_update->bindParam(':nama_cabang', $user_data['nama_cabang'], PDO::PARAM_STR);
        $stmt_update->bindParam(':id', $record_id, PDO::PARAM_INT);
        
        if ($stmt_update->execute()) {
            echo "<span style='color: green;'>✓ FIXED from users table</span>";
            echo " → {$user_data['kode_cabang']} | {$user_data['nama_cabang']}</p>";
            $fixed_count++;
            $fix_details[] = [
                'kode_transaksi' => $kode_transaksi,
                'karyawan' => $kode_karyawan,
                'source' => 'users_table',
                'kode_cabang' => $user_data['kode_cabang'],
                'nama_cabang' => $user_data['nama_cabang']
            ];
        } else {
            echo "<span style='color: red;'>✗ FAILED to update</span></p>";
            $failed_fixes[] = $record;
        }
    } else {
        // Coba cari dari transaksi lain dari karyawan yang sama
        echo "<br>&nbsp;&nbsp;→ No data in users table, checking other transactions... ";
        
        $sql_other_trans = "SELECT kode_cabang, nama_cabang FROM kasir_transactions 
                           WHERE kode_karyawan = :kode_karyawan 
                           AND kode_cabang IS NOT NULL AND nama_cabang IS NOT NULL 
                           AND kode_cabang != '' AND nama_cabang != ''
                           AND id != :current_id
                           ORDER BY tanggal_transaksi DESC LIMIT 1";
        $stmt_other = $pdo->prepare($sql_other_trans);
        $stmt_other->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
        $stmt_other->bindParam(':current_id', $record_id, PDO::PARAM_INT);
        $stmt_other->execute();
        $other_trans = $stmt_other->fetch(PDO::FETCH_ASSOC);
        
        if ($other_trans && !empty($other_trans['kode_cabang']) && !empty($other_trans['nama_cabang'])) {
            // Update dengan data dari transaksi lain
            $sql_update = "UPDATE kasir_transactions 
                          SET kode_cabang = :kode_cabang, nama_cabang = :nama_cabang 
                          WHERE id = :id";
            $stmt_update = $pdo->prepare($sql_update);
            $stmt_update->bindParam(':kode_cabang', $other_trans['kode_cabang'], PDO::PARAM_STR);
            $stmt_update->bindParam(':nama_cabang', $other_trans['nama_cabang'], PDO::PARAM_STR);
            $stmt_update->bindParam(':id', $record_id, PDO::PARAM_INT);
            
            if ($stmt_update->execute()) {
                echo "<span style='color: green;'>✓ FIXED from other transactions</span>";
                echo " → {$other_trans['kode_cabang']} | {$other_trans['nama_cabang']}";
                $fixed_count++;
                $fix_details[] = [
                    'kode_transaksi' => $kode_transaksi,
                    'karyawan' => $kode_karyawan,
                    'source' => 'other_transactions',
                    'kode_cabang' => $other_trans['kode_cabang'],
                    'nama_cabang' => $other_trans['nama_cabang']
                ];
                
                // Update juga tabel users
                $sql_update_user = "UPDATE users SET kode_cabang = :kode_cabang, nama_cabang = :nama_cabang 
                                   WHERE kode_karyawan = :kode_karyawan 
                                   AND (kode_cabang IS NULL OR nama_cabang IS NULL OR kode_cabang = '' OR nama_cabang = '')";
                $stmt_update_user = $pdo->prepare($sql_update_user);
                $stmt_update_user->bindParam(':kode_cabang', $other_trans['kode_cabang'], PDO::PARAM_STR);
                $stmt_update_user->bindParam(':nama_cabang', $other_trans['nama_cabang'], PDO::PARAM_STR);
                $stmt_update_user->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
                if ($stmt_update_user->execute()) {
                    echo "<br>&nbsp;&nbsp;→ <span style='color: blue;'>Also updated users table</span>";
                }
                echo "</p>";
            } else {
                echo "<span style='color: red;'>✗ FAILED to update</span></p>";
                $failed_fixes[] = $record;
            }
        } else {
            echo "<span style='color: orange;'>⚠ NO DATA FOUND</span></p>";
            $failed_fixes[] = $record;
        }
    }
}

// 3. Summary
echo "<hr><h3>📊 Step 3: Repair Summary</h3>";
echo "<div style='background-color: #f0f8ff; padding: 15px; border-radius: 5px;'>";
echo "<p><strong>Total records processed:</strong> " . count($missing_records) . "</p>";
echo "<p><strong>Successfully fixed:</strong> <span style='color: green; font-weight: bold;'>$fixed_count</span></p>";
echo "<p><strong>Failed to fix:</strong> <span style='color: red; font-weight: bold;'>" . count($failed_fixes) . "</span></p>";
echo "</div>";

// 4. Detail perbaikan yang berhasil
if (count($fix_details) > 0) {
    echo "<h4>✅ Successfully Fixed Records:</h4>";
    echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
    echo "<tr style='background-color: #e8f5e8;'>";
    echo "<th>Kode Transaksi</th><th>Karyawan</th><th>Source</th><th>Kode Cabang</th><th>Nama Cabang</th>";
    echo "</tr>";
    
    foreach ($fix_details as $detail) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($detail['kode_transaksi']) . "</td>";
        echo "<td>" . htmlspecialchars($detail['karyawan']) . "</td>";
        echo "<td>" . htmlspecialchars($detail['source']) . "</td>";
        echo "<td>" . htmlspecialchars($detail['kode_cabang']) . "</td>";
        echo "<td>" . htmlspecialchars($detail['nama_cabang']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 5. Records yang gagal diperbaiki
if (count($failed_fixes) > 0) {
    echo "<h4>❌ Records that couldn't be fixed:</h4>";
    echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%; font-size: 12px;'>";
    echo "<tr style='background-color: #ffe8e8;'>";
    echo "<th>ID</th><th>Kode Transaksi</th><th>Karyawan</th><th>Tanggal</th>";
    echo "</tr>";
    
    foreach ($failed_fixes as $failed) {
        echo "<tr>";
        echo "<td>" . $failed['id'] . "</td>";
        echo "<td>" . htmlspecialchars($failed['kode_transaksi']) . "</td>";
        echo "<td>" . htmlspecialchars($failed['kode_karyawan']) . "</td>";
        echo "<td>" . htmlspecialchars($failed['tanggal_transaksi']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<div style='background-color: #fff3cd; padding: 15px; border-radius: 5px; margin-top: 10px;'>";
    echo "<h4>⚠ Manual Intervention Required</h4>";
    echo "<p>These records need manual intervention. Please check:</p>";
    echo "<ul>";
    echo "<li>Whether these employees exist in the users table</li>";
    echo "<li>Whether they have valid branch assignments</li>";
    echo "<li>Consider setting default branch values if needed</li>";
    echo "</ul>";
    echo "</div>";
}

// 6. Verifikasi hasil perbaikan
echo "<hr><h3>🔍 Step 4: Verification</h3>";
$stmt_verify = $pdo->query($sql_missing);
$remaining_missing = $stmt_verify->fetchAll(PDO::FETCH_ASSOC);

echo "<p><strong>Remaining records with missing branch data:</strong> " . count($remaining_missing) . "</p>";

if (count($remaining_missing) == 0) {
    echo "<div style='background-color: #d4edda; padding: 15px; border-radius: 5px; color: #155724;'>";
    echo "<h3>🎉 SUCCESS! All branch data has been successfully fixed!</h3>";
    echo "</div>";
} else {
    echo "<div style='background-color: #fff3cd; padding: 15px; border-radius: 5px; color: #856404;'>";
    echo "<h3>⚠ PARTIALLY COMPLETED</h3>";
    echo "<p>" . count($remaining_missing) . " records still need attention.</p>";
    echo "</div>";
}

// 7. Generate report
echo "<hr><h3>📋 Step 5: Branch Assignment Report</h3>";

$sql_report = "SELECT DISTINCT u.kode_karyawan, u.nama_karyawan, u.kode_cabang, u.nama_cabang,
               COUNT(kt.id) as total_transactions,
               MAX(kt.tanggal_transaksi) as last_transaction
               FROM users u
               LEFT JOIN kasir_transactions kt ON u.kode_karyawan = kt.kode_karyawan
               WHERE u.role IN ('kasir', 'admin', 'super_admin')
               GROUP BY u.kode_karyawan, u.nama_karyawan, u.kode_cabang, u.nama_cabang
               ORDER BY u.nama_cabang, u.nama_karyawan";

$stmt_report = $pdo->query($sql_report);
$report_data = $stmt_report->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%; font-size: 11px;'>";
echo "<tr style='background-color: #f0f0f0;'>";
echo "<th>Kode Karyawan</th><th>Nama Karyawan</th><th>Kode Cabang</th><th>Nama Cabang</th><th>Total Trans</th><th>Last Trans</th>";
echo "</tr>";

foreach ($report_data as $row) {
    $bg_color = ($row['kode_cabang'] && $row['nama_cabang']) ? '#f8fff8' : '#fff8f8';
    echo "<tr style='background-color: $bg_color;'>";
    echo "<td>" . htmlspecialchars($row['kode_karyawan']) . "</td>";
    echo "<td>" . htmlspecialchars(substr($row['nama_karyawan'], 0, 20)) . "</td>";
    echo "<td>" . htmlspecialchars($row['kode_cabang'] ?: 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars(substr($row['nama_cabang'] ?: 'NULL', 0, 20)) . "</td>";
    echo "<td>" . $row['total_transactions'] . "</td>";
    echo "<td>" . htmlspecialchars($row['last_transaction'] ?: 'Never') . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr><div style='background-color: #e7f3ff; padding: 15px; border-radius: 5px; margin-top: 20px;'>";
echo "<h3>✅ Script completed successfully!</h3>";
echo "<p><strong>Next steps:</strong></p>";
echo "<ol>";
echo "<li>Upload <code>session_helper.php</code> to your kasir folder</li>";
echo "<li>Update your kasir files dengan versi yang sudah diperbaiki</li>";
echo "<li>Test login dan buat transaksi baru</li>";
echo "<li>Monitor untuk memastikan tidak ada data missing lagi</li>";
echo "</ol>";
echo "<p><strong>Time completed:</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "</div>";
?>