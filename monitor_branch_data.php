<?php
/**
 * Script monitoring untuk memantau data cabang
 * Jalankan script ini secara berkala untuk memantau kesehatan data
 * 
 * Usage: 
 * - Via browser: http://yoursite.com/path/monitor_branch_data.php
 * - Via cron: php monitor_branch_data.php
 * - Via command line: php monitor_branch_data.php --check-only
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if running from command line
$is_cli = (php_sapi_name() === 'cli');
$check_only = false;

if ($is_cli) {
    $check_only = in_array('--check-only', $argv);
    if (!$check_only) {
        echo "Branch Data Monitor - " . date('Y-m-d H:i:s') . "\n";
        echo str_repeat("=", 50) . "\n";
    }
} else {
    echo "<h1>🔍 Branch Data Monitor</h1>";
    echo "<p><strong>Last check:</strong> " . date('Y-m-d H:i:s') . "</p>";
    echo "<hr>";
}

// Database connection
$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if (!$check_only) {
        if ($is_cli) {
            echo "✓ Database connection successful\n";
        } else {
            echo "<p style='color: green;'>✓ Database connection successful</p>";
        }
    }
} catch (PDOException $e) {
    $error = "✗ Database connection failed: " . $e->getMessage();
    if ($is_cli) {
        echo $error . "\n";
    } else {
        echo "<p style='color: red;'>$error</p>";
    }
    exit(1);
}

// Monitoring functions
function formatOutput($message, $is_cli, $color = 'black') {
    if ($is_cli) {
        echo $message . "\n";
    } else {
        echo "<p style='color: $color;'>$message</p>";
    }
}

function formatTable($data, $headers, $is_cli) {
    if ($is_cli) {
        // CLI table format
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = max(strlen($header), 15);
            foreach ($data as $row) {
                if (isset($row[$i])) {
                    $widths[$i] = max($widths[$i], strlen($row[$i]));
                }
            }
        }
        
        // Header
        foreach ($headers as $i => $header) {
            echo str_pad($header, $widths[$i]) . " | ";
        }
        echo "\n";
        
        // Separator
        foreach ($widths as $width) {
            echo str_repeat("-", $width) . "-+-";
        }
        echo "\n";
        
        // Data
        foreach ($data as $row) {
            foreach ($headers as $i => $header) {
                $value = isset($row[$i]) ? $row[$i] : '';
                echo str_pad($value, $widths[$i]) . " | ";
            }
            echo "\n";
        }
    } else {
        // HTML table format
        echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; font-size: 12px;'>";
        echo "<tr style='background-color: #f0f0f0;'>";
        foreach ($headers as $header) {
            echo "<th>" . htmlspecialchars($header) . "</th>";
        }
        echo "</tr>";
        
        foreach ($data as $row) {
            echo "<tr>";
            foreach ($headers as $i => $header) {
                $value = isset($row[$i]) ? $row[$i] : '';
                echo "<td>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
}

// 1. Check missing branch data in kasir_transactions
if (!$check_only) {
    formatOutput("1. Checking missing branch data in kasir_transactions...", $is_cli, 'blue');
}

$sql_missing = "SELECT COUNT(*) as missing_count FROM kasir_transactions 
                WHERE kode_cabang IS NULL OR nama_cabang IS NULL 
                   OR kode_cabang = '' OR nama_cabang = ''";
$stmt = $pdo->query($sql_missing);
$missing_count = $stmt->fetchColumn();

if ($missing_count > 0) {
    formatOutput("⚠ Found $missing_count records with missing branch data", $is_cli, 'orange');
    
    if (!$check_only) {
        $sql_missing_detail = "SELECT id, kode_transaksi, kode_karyawan, tanggal_transaksi 
                              FROM kasir_transactions 
                              WHERE kode_cabang IS NULL OR nama_cabang IS NULL 
                                 OR kode_cabang = '' OR nama_cabang = ''
                              ORDER BY tanggal_transaksi DESC LIMIT 10";
        $stmt_detail = $pdo->query($sql_missing_detail);
        $missing_data = $stmt_detail->fetchAll(PDO::FETCH_NUM);
        
        formatOutput("Recent missing records:", $is_cli);
        formatTable($missing_data, ['ID', 'Kode Transaksi', 'Karyawan', 'Tanggal'], $is_cli);
    }
} else {
    formatOutput("✓ No missing branch data found", $is_cli, 'green');
}

// 2. Check users without branch data
if (!$check_only) {
    formatOutput("2. Checking users without branch data...", $is_cli, 'blue');
}

$sql_users_missing = "SELECT COUNT(*) as users_missing FROM users 
                     WHERE role IN ('kasir', 'admin', 'super_admin')
                     AND (kode_cabang IS NULL OR nama_cabang IS NULL 
                          OR kode_cabang = '' OR nama_cabang = '')";
$stmt = $pdo->query($sql_users_missing);
$users_missing = $stmt->fetchColumn();

if ($users_missing > 0) {
    formatOutput("⚠ Found $users_missing users without branch data", $is_cli, 'orange');
    
    if (!$check_only) {
        $sql_users_detail = "SELECT kode_karyawan, nama_karyawan, role 
                            FROM users 
                            WHERE role IN ('kasir', 'admin', 'super_admin')
                            AND (kode_cabang IS NULL OR nama_cabang IS NULL 
                                 OR kode_cabang = '' OR nama_cabang = '')";
        $stmt_detail = $pdo->query($sql_users_detail);
        $users_data = $stmt_detail->fetchAll(PDO::FETCH_NUM);
        
        formatOutput("Users without branch data:", $is_cli);
        formatTable($users_data, ['Kode Karyawan', 'Nama', 'Role'], $is_cli);
    }
} else {
    formatOutput("✓ All users have branch data", $is_cli, 'green');
}

// 3. Check recent transactions
if (!$check_only) {
    formatOutput("3. Checking recent transactions...", $is_cli, 'blue');
}

$sql_recent = "SELECT COUNT(*) as recent_count FROM kasir_transactions 
               WHERE tanggal_transaksi >= DATE_SUB(CURDATE(), INTERVAL 7 DAYS)";
$stmt = $pdo->query($sql_recent);
$recent_count = $stmt->fetchColumn();

$sql_recent_missing = "SELECT COUNT(*) as recent_missing FROM kasir_transactions 
                      WHERE tanggal_transaksi >= DATE_SUB(CURDATE(), INTERVAL 7 DAYS)
                      AND (kode_cabang IS NULL OR nama_cabang IS NULL 
                           OR kode_cabang = '' OR nama_cabang = '')";
$stmt = $pdo->query($sql_recent_missing);
$recent_missing = $stmt->fetchColumn();

formatOutput("Recent transactions (last 7 days): $recent_count", $is_cli);
if ($recent_missing > 0) {
    formatOutput("⚠ Recent missing branch data: $recent_missing", $is_cli, 'red');
} else {
    formatOutput("✓ All recent transactions have branch data", $is_cli, 'green');
}

// 4. Branch distribution
if (!$check_only) {
    formatOutput("4. Branch distribution...", $is_cli, 'blue');
    
    $sql_distribution = "SELECT 
                           CONCAT(kode_cabang, ' - ', nama_cabang) as cabang,
                           COUNT(*) as total_transactions,
                           COUNT(DISTINCT kode_karyawan) as unique_employees,
                           MIN(tanggal_transaksi) as first_transaction,
                           MAX(tanggal_transaksi) as last_transaction
                        FROM kasir_transactions 
                        WHERE kode_cabang IS NOT NULL AND nama_cabang IS NOT NULL
                        GROUP BY kode_cabang, nama_cabang
                        ORDER BY total_transactions DESC";
    $stmt = $pdo->query($sql_distribution);
    $distribution = $stmt->fetchAll(PDO::FETCH_NUM);
    
    formatOutput("Branch transaction distribution:", $is_cli);
    formatTable($distribution, ['Cabang', 'Total Trans', 'Employees', 'First Trans', 'Last Trans'], $is_cli);
}

// 5. Session health check (if session_helper exists)
if (!$check_only && file_exists('session_helper.php')) {
    formatOutput("5. Session helper status...", $is_cli, 'blue');
    formatOutput("✓ session_helper.php found", $is_cli, 'green');
} else if (!$check_only) {
    formatOutput("5. Session helper status...", $is_cli, 'blue');
    formatOutput("⚠ session_helper.php not found", $is_cli, 'orange');
}

// 6. Health score calculation
$total_transactions = $recent_count;
$health_score = 100;

if ($missing_count > 0) {
    $health_score -= min(50, ($missing_count / max($total_transactions, 1)) * 100);
}

if ($users_missing > 0) {
    $health_score -= min(30, $users_missing * 5);
}

if ($recent_missing > 0) {
    $health_score -= min(20, ($recent_missing / max($recent_count, 1)) * 100);
}

$health_score = max(0, round($health_score));

if (!$check_only) {
    formatOutput("6. Overall health score...", $is_cli, 'blue');
}

$color = 'green';
if ($health_score < 80) $color = 'orange';
if ($health_score < 60) $color = 'red';

formatOutput("Overall Health Score: $health_score/100", $is_cli, $color);

// Exit with appropriate code for automated monitoring
if ($health_score < 70) {
    exit(1); // Error exit code
} else {
    exit(0); // Success exit code
}
?>