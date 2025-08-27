<?php
/**
 * Script untuk membuat tabel master_cabang dan mengisi data awal
 * Jalankan script ini sekali untuk setup tabel master cabang
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Database connection
$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<h2>✓ Database connection successful</h2>";
} catch (PDOException $e) {
    die("<h2>✗ Database connection failed: " . $e->getMessage() . "</h2>");
}

echo "<h1>🏢 Setup Master Cabang</h1>";
echo "<p>Setting up master cabang table and initial data...</p>";

// 1. Check if table exists
echo "<h3>1. Checking if master_cabang table exists...</h3>";

$sql_check_table = "SHOW TABLES LIKE 'master_cabang'";
$stmt = $pdo->query($sql_check_table);
$table_exists = $stmt->rowCount() > 0;

if ($table_exists) {
    echo "<p style='color: orange;'>⚠ Table master_cabang already exists</p>";
} else {
    echo "<p style='color: blue;'>ℹ Table master_cabang does not exist, will create it</p>";
}

// 2. Create table if not exists
echo "<h3>2. Creating master_cabang table...</h3>";

$sql_create_table = "
CREATE TABLE IF NOT EXISTS master_cabang (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode_cabang VARCHAR(20) NOT NULL UNIQUE,
    nama_cabang VARCHAR(100) NOT NULL,
    alamat TEXT,
    telepon VARCHAR(20),
    email VARCHAR(100),
    manager VARCHAR(100),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_kode_cabang (kode_cabang),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

try {
    $pdo->exec($sql_create_table);
    echo "<p style='color: green;'>✓ Table master_cabang created successfully</p>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Error creating table: " . $e->getMessage() . "</p>";
}

// 3. Extract existing cabang data from kasir_transactions
echo "<h3>3. Extracting existing branch data...</h3>";

$sql_existing = "SELECT DISTINCT kode_cabang, nama_cabang 
                 FROM kasir_transactions 
                 WHERE kode_cabang IS NOT NULL AND nama_cabang IS NOT NULL 
                 AND kode_cabang != '' AND nama_cabang != ''
                 ORDER BY nama_cabang";

$stmt = $pdo->query($sql_existing);
$existing_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Found " . count($existing_branches) . " unique branches in transaction data:</p>";

if (count($existing_branches) > 0) {
    echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
    echo "<tr style='background-color: #f0f0f0;'><th>Kode Cabang</th><th>Nama Cabang</th></tr>";
    
    foreach ($existing_branches as $branch) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($branch['kode_cabang']) . "</td>";
        echo "<td>" . htmlspecialchars($branch['nama_cabang']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 4. Insert data into master_cabang
echo "<h3>4. Inserting branch data into master_cabang...</h3>";

$inserted_count = 0;
$skipped_count = 0;

foreach ($existing_branches as $branch) {
    // Check if already exists
    $sql_check = "SELECT COUNT(*) FROM master_cabang WHERE kode_cabang = :kode_cabang";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->bindParam(':kode_cabang', $branch['kode_cabang']);
    $stmt_check->execute();
    $exists = $stmt_check->fetchColumn();
    
    if ($exists > 0) {
        echo "<p style='color: orange;'>- " . $branch['kode_cabang'] . " already exists, skipped</p>";
        $skipped_count++;
    } else {
        // Insert new branch
        $sql_insert = "INSERT INTO master_cabang (kode_cabang, nama_cabang, status) 
                      VALUES (:kode_cabang, :nama_cabang, 'active')";
        $stmt_insert = $pdo->prepare($sql_insert);
        $stmt_insert->bindParam(':kode_cabang', $branch['kode_cabang']);
        $stmt_insert->bindParam(':nama_cabang', $branch['nama_cabang']);
        
        try {
            $stmt_insert->execute();
            echo "<p style='color: green;'>+ " . $branch['kode_cabang'] . " - " . $branch['nama_cabang'] . " inserted</p>";
            $inserted_count++;
        } catch (PDOException $e) {
            echo "<p style='color: red;'>✗ Error inserting " . $branch['kode_cabang'] . ": " . $e->getMessage() . "</p>";
        }
    }
}

// 5. Add some sample branches if none exist
if (count($existing_branches) == 0) {
    echo "<h3>5. Adding sample branch data...</h3>";
    
    $sample_branches = [
        ['201601001', 'FIT MOTOR ADIWERNA'],
        ['201809001', 'FIT MOTOR PACUL'],
        ['202201001', 'FIT MOTOR CIKDITIRO'],
        ['202301001', 'FIT MOTOR PUSAT'],
        ['202302001', 'FIT MOTOR CABANG UTAMA']
    ];
    
    foreach ($sample_branches as $sample) {
        $sql_insert = "INSERT IGNORE INTO master_cabang (kode_cabang, nama_cabang, status) 
                      VALUES (:kode_cabang, :nama_cabang, 'active')";
        $stmt_insert = $pdo->prepare($sql_insert);
        $stmt_insert->bindParam(':kode_cabang', $sample[0]);
        $stmt_insert->bindParam(':nama_cabang', $sample[1]);
        
        try {
            $stmt_insert->execute();
            if ($pdo->lastInsertId()) {
                echo "<p style='color: green;'>+ Sample: " . $sample[0] . " - " . $sample[1] . " added</p>";
                $inserted_count++;
            }
        } catch (PDOException $e) {
            echo "<p style='color: red;'>✗ Error adding sample " . $sample[0] . ": " . $e->getMessage() . "</p>";
        }
    }
}

// 6. Summary and current data
echo "<h3>6. Summary</h3>";
echo "<div style='background-color: #f0f8ff; padding: 15px; border-radius: 5px;'>";
echo "<p><strong>Inserted:</strong> $inserted_count branches</p>";
echo "<p><strong>Skipped (already exists):</strong> $skipped_count branches</p>";
echo "</div>";

// Show current master_cabang data
echo "<h3>7. Current master_cabang data</h3>";

$sql_current = "SELECT kode_cabang, nama_cabang, status, created_at FROM master_cabang ORDER BY nama_cabang";
$stmt = $pdo->query($sql_current);
$current_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background-color: #f0f0f0;'>";
echo "<th>Kode Cabang</th><th>Nama Cabang</th><th>Status</th><th>Created At</th>";
echo "</tr>";

foreach ($current_data as $row) {
    $bg_color = ($row['status'] == 'active') ? '#f8fff8' : '#fff8f8';
    echo "<tr style='background-color: $bg_color;'>";
    echo "<td>" . htmlspecialchars($row['kode_cabang']) . "</td>";
    echo "<td>" . htmlspecialchars($row['nama_cabang']) . "</td>";
    echo "<td>" . htmlspecialchars($row['status']) . "</td>";
    echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// 8. Update get_branches.php recommendation
echo "<h3>8. Next Steps</h3>";
echo "<div style='background-color: #e7f3ff; padding: 15px; border-radius: 5px;'>";
echo "<h4>Recommendations:</h4>";
echo "<ol>";
echo "<li><strong>Update get_branches.php</strong> to use master_cabang table:</li>";
echo "<pre style='background: #f5f5f5; padding: 10px; font-family: monospace;'>";
echo '$sql_cabang = "SELECT kode_cabang, nama_cabang FROM master_cabang WHERE status = \'active\' ORDER BY nama_cabang";';
echo "</pre>";

echo "<li><strong>Add more branch details</strong> (alamat, telepon, manager) via admin panel</li>";
echo "<li><strong>Create admin interface</strong> to manage master_cabang data</li>";
echo "<li><strong>Set up validation</strong> to ensure new transactions use valid branch codes</li>";
echo "<li><strong>Monitor usage</strong> with monitor_branch_data.php script</li>";
echo "</ol>";
echo "</div>";

// 9. Create admin management snippet
echo "<h3>9. Admin Management Query Examples</h3>";
echo "<div style='background-color: #f9f9f9; padding: 15px; border-radius: 5px;'>";
echo "<h4>Useful SQL queries for admin:</h4>";

echo "<p><strong>Add new branch:</strong></p>";
echo "<pre style='background: #f5f5f5; padding: 10px; font-family: monospace;'>";
echo "INSERT INTO master_cabang (kode_cabang, nama_cabang, alamat, telepon, manager, status) 
VALUES ('NEW001', 'FIT MOTOR NEW BRANCH', 'Jl. Contoh No. 123', '081234567890', 'Manager Name', 'active');";
echo "</pre>";

echo "<p><strong>Deactivate branch:</strong></p>";
echo "<pre style='background: #f5f5f5; padding: 10px; font-family: monospace;'>";
echo "UPDATE master_cabang SET status = 'inactive' WHERE kode_cabang = 'OLD001';";
echo "</pre>";

echo "<p><strong>Update branch info:</strong></p>";
echo "<pre style='background: #f5f5f5; padding: 10px; font-family: monospace;'>";
echo "UPDATE master_cabang SET 
    nama_cabang = 'FIT MOTOR UPDATED NAME',
    alamat = 'New Address',
    telepon = '081987654321'
WHERE kode_cabang = 'EXISTING001';";
echo "</pre>";

echo "<p><strong>Check branch usage:</strong></p>";
echo "<pre style='background: #f5f5f5; padding: 10px; font-family: monospace;'>";
echo "SELECT mc.kode_cabang, mc.nama_cabang, mc.status,
       COUNT(kt.id) as total_transactions,
       MAX(kt.tanggal_transaksi) as last_used
FROM master_cabang mc
LEFT JOIN kasir_transactions kt ON mc.kode_cabang = kt.kode_cabang
GROUP BY mc.kode_cabang, mc.nama_cabang, mc.status
ORDER BY total_transactions DESC;";
echo "</pre>";

echo "</div>";

echo "<hr>";
echo "<div style='background-color: #d4edda; padding: 15px; border-radius: 5px; margin-top: 20px;'>";
echo "<h3>✅ Setup Complete!</h3>";
echo "<p>Master cabang table has been set up successfully.</p>";
echo "<p><strong>Total branches in master_cabang:</strong> " . count($current_data) . "</p>";
echo "<p><strong>Time completed:</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "</div>";
?>