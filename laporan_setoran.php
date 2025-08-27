<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['kode_karyawan']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    die("Akses ditolak. Hanya untuk admin dan super_admin.");
}

$is_super_admin = $_SESSION['role'] === 'super_admin';
$is_admin = $_SESSION['role'] === 'admin';
// Ubah bagian ini yang sudah ada
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User'; // Tambahkan ini


$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';

// Filter parameters
$tanggal_awal = $_POST['tanggal_awal'] ?? '';
$tanggal_akhir = $_POST['tanggal_akhir'] ?? '';
$cabang = $_POST['cabang'] ?? 'all';
$report_type = $_POST['report_type'] ?? 'cs_to_keuangan';

// Fetch branches for filter
$sql_cabang = "SELECT DISTINCT nama_cabang FROM setoran_keuangan WHERE nama_cabang IS NOT NULL AND nama_cabang != '' ORDER BY nama_cabang";
$stmt_cabang = $pdo->query($sql_cabang);
$cabang_list = $stmt_cabang->fetchAll(PDO::FETCH_COLUMN);

// Fetch data based on report type
$data = [];
$summary = [];

if ($report_type == 'cs_to_keuangan') {
    // Query yang diperbaiki - menggunakan kode_setoran sebagai penghubung
    $sql = "
        SELECT sk.*, u.nama_karyawan, 
               GROUP_CONCAT(kt.deposit_status) as deposit_status_list,
               GROUP_CONCAT(kt.deposit_difference_status) as deposit_difference_status_list
        FROM setoran_keuangan sk
        LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
        LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
        WHERE 1=1";
    $params = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql .= " AND sk.tanggal_setoran BETWEEN ? AND ?";
        $params[] = $tanggal_awal;
        $params[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql .= " AND sk.nama_cabang = ?";
        $params[] = $cabang;
    }
    $sql .= " GROUP BY sk.id ORDER BY sk.tanggal_setoran DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary
    $sql_summary = "
        SELECT 
            status, 
            COUNT(*) as count,
            SUM(jumlah_setoran) as total_setoran,
            SUM(jumlah_diterima) as total_diterima,
            SUM(selisih_setoran) as total_selisih
        FROM setoran_keuangan sk
        WHERE 1=1";
    $params_summary = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_summary .= " AND sk.tanggal_setoran BETWEEN ? AND ?";
        $params_summary[] = $tanggal_awal;
        $params_summary[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_summary .= " AND sk.nama_cabang = ?";
        $params_summary[] = $cabang;
    }
    $sql_summary .= " GROUP BY status";

    $stmt_summary = $pdo->prepare($sql_summary);
    $stmt_summary->execute($params_summary);
    $summary = $stmt_summary->fetchAll(PDO::FETCH_ASSOC);
} elseif ($report_type == 'keuangan_to_bank') {
    $sql = "
        SELECT sb.*, u.nama_karyawan as created_by_name, GROUP_CONCAT(DISTINCT sk.nama_cabang SEPARATOR ', ') as cabang_list
        FROM setoran_ke_bank sb
        JOIN users u ON sb.created_by = u.kode_karyawan
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        WHERE 1=1";
    $params = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql .= " AND sb.tanggal_setoran BETWEEN ? AND ?";
        $params[] = $tanggal_awal;
        $params[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql .= " AND sk.nama_cabang = ?";
        $params[] = $cabang;
    }
    $sql .= " GROUP BY sb.id ORDER BY sb.tanggal_setoran DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary
    $sql_summary = "
        SELECT 
            SUM(total_setoran) as total,
            COUNT(*) as count
        FROM setoran_ke_bank sb
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        WHERE 1=1";
    $params_summary = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_summary .= " AND sb.tanggal_setoran BETWEEN ? AND ?";
        $params_summary[] = $tanggal_awal;
        $params_summary[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_summary .= " AND sk.nama_cabang = ?";
        $params_summary[] = $cabang;
    }

    $stmt_summary = $pdo->prepare($sql_summary);
    $stmt_summary->execute($params_summary);
    $summary = $stmt_summary->fetch(PDO::FETCH_ASSOC);
} elseif ($report_type == 'rekapitulasi') {
    // Total from CS (based on jumlah_diterima)
    $sql_total_cs = "
        SELECT SUM(COALESCE(jumlah_diterima, jumlah_setoran)) as total_cs
        FROM setoran_keuangan sk
        WHERE 1=1";
    $params_cs = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_total_cs .= " AND sk.tanggal_setoran BETWEEN ? AND ?";
        $params_cs[] = $tanggal_awal;
        $params_cs[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_total_cs .= " AND sk.nama_cabang = ?";
        $params_cs[] = $cabang;
    }

    $stmt_total_cs = $pdo->prepare($sql_total_cs);
    $stmt_total_cs->execute($params_cs);
    $total_cs = $stmt_total_cs->fetchColumn() ?? 0;

    // Total selisih
    $sql_total_selisih = "
        SELECT SUM(COALESCE(selisih_setoran, 0)) as total_selisih
        FROM setoran_keuangan sk
        WHERE 1=1";
    $params_selisih = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_total_selisih .= " AND sk.tanggal_setoran BETWEEN ? AND ?";
        $params_selisih[] = $tanggal_awal;
        $params_selisih[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_total_selisih .= " AND sk.nama_cabang = ?";
        $params_selisih[] = $cabang;
    }

    $stmt_total_selisih = $pdo->prepare($sql_total_selisih);
    $stmt_total_selisih->execute($params_selisih);
    $total_selisih = $stmt_total_selisih->fetchColumn() ?? 0;

    // Total to Bank
    $sql_total_bank = "
        SELECT SUM(total_setoran) as total_bank
        FROM setoran_ke_bank sb
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        WHERE 1=1";
    $params_bank = [];
    if ($tanggal_awal && $tanggal_akhir) {
        $sql_total_bank .= " AND sb.tanggal_setoran BETWEEN ? AND ?";
        $params_bank[] = $tanggal_awal;
        $params_bank[] = $tanggal_akhir;
    }
    if ($cabang !== 'all') {
        $sql_total_bank .= " AND sk.nama_cabang = ?";
        $params_bank[] = $cabang;
    }

    $stmt_total_bank = $pdo->prepare($sql_total_bank);
    $stmt_total_bank->execute($params_bank);
    $total_bank = $stmt_total_bank->fetchColumn() ?? 0;

    // Pending amount
    $selisih = $total_cs - $total_bank;

    $summary = [
        'total_cs' => $total_cs,
        'total_bank' => $total_bank,
        'selisih' => $selisih,
        'total_selisih_cs' => $total_selisih
    ];
}

// Handle print transfer proof
if (isset($_GET['print_proof']) && $report_type == 'keuangan_to_bank') {
    $setoran_id = $_GET['print_proof'];
    $sql_proof = "
        SELECT sb.*, u.nama_karyawan as created_by_name, GROUP_CONCAT(DISTINCT sk.nama_cabang SEPARATOR ', ') as cabang_list,
               GROUP_CONCAT(CONCAT(sk.tanggal_setoran, ': Rp ', FORMAT(COALESCE(sk.jumlah_diterima, sk.jumlah_setoran), 0), ' (Selisih: Rp ', FORMAT(COALESCE(sk.selisih_setoran, 0), 0), ')') SEPARATOR '<br>') as setoran_details
        FROM setoran_ke_bank sb
        JOIN users u ON sb.created_by = u.kode_karyawan
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        WHERE sb.id = ?
        GROUP BY sb.id";
    $stmt_proof = $pdo->prepare($sql_proof);
    $stmt_proof->execute([$setoran_id]);
    $proof_data = $stmt_proof->fetch(PDO::FETCH_ASSOC);

    if ($proof_data) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Bukti Setoran ke Bank</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .container { max-width: 800px; margin: auto; }
                .header { text-align: center; margin-bottom: 20px; }
                .details { margin-bottom: 20px; }
                .details table { width: 100%; border-collapse: collapse; }
                .details table, .details th, .details td { border: 1px solid #ddd; padding: 8px; }
                .footer { margin-top: 20px; text-align: right; }
            </style>
        </head>
        <body onload="window.print()">
            <div class="container">
                <div class="header">
                    <h2>Bukti Setoran ke Bank</h2>
                    <p>Kode Setoran: <?php echo htmlspecialchars($proof_data['kode_setoran']); ?></p>
                </div>
                <div class="details">
                    <table>
                        <tr><th>Tanggal Setoran</th><td><?php echo htmlspecialchars($proof_data['tanggal_setoran']); ?></td></tr>
                        <tr><th>Metode Setoran</th><td><?php echo htmlspecialchars($proof_data['metode_setoran']); ?></td></tr>
                        <tr><th>Rekening Tujuan</th><td><?php echo htmlspecialchars($proof_data['rekening_tujuan']); ?></td></tr>
                        <tr><th>Total Setoran</th><td>Rp <?php echo number_format($proof_data['total_setoran'], 0, ',', '.'); ?></td></tr>
                        <tr><th>Cabang Asal</th><td><?php echo htmlspecialchars($proof_data['cabang_list']); ?></td></tr>
                        <tr><th>Detail Setoran</th><td><?php echo $proof_data['setoran_details']; ?></td></tr>
                        <tr><th>Dibuat Oleh</th><td><?php echo htmlspecialchars($proof_data['created_by_name']); ?></td></tr>
                        <tr><th>Tanggal Dibuat</th><td><?php echo htmlspecialchars($proof_data['created_at']); ?></td></tr>
                    </table>
                    <?php if (isset($proof_data['bukti_transfer']) && !empty($proof_data['bukti_transfer'])): ?>
                        <p><strong>Bukti Transfer:</strong><br>
                        <img src="<?php echo htmlspecialchars($proof_data['bukti_transfer']); ?>" alt="Bukti Transfer" style="max-width: 100%;"></p>
                    <?php endif; ?>
                </div>
                <div class="footer">
                    <p>Fitmotor Maintenance</p>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Handle detailed view for specific deposit
$detail_view = null;
$detail_transactions = [];
if (isset($_GET['detail_id'])) {
    $detail_id = $_GET['detail_id'];
    
    if ($report_type == 'cs_to_keuangan') {
        // Get setoran details
        $sql_detail = "
            SELECT sk.*, u.nama_karyawan 
            FROM setoran_keuangan sk
            LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
            WHERE sk.id = ?";
        $stmt_detail = $pdo->prepare($sql_detail);
        $stmt_detail->execute([$detail_id]);
        $detail_view = $stmt_detail->fetch(PDO::FETCH_ASSOC);
        
        if ($detail_view) {
            // Get related transactions
            $sql_trans = "
                SELECT kt.*
                FROM kasir_transactions kt
                WHERE kt.kode_setoran = ?";
            $stmt_trans = $pdo->prepare($sql_trans);
            $stmt_trans->execute([$detail_view['kode_setoran']]);
            $detail_transactions = $stmt_trans->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($report_type == 'keuangan_to_bank') {
        // Get bank deposit details
        $sql_detail = "
            SELECT sb.*, u.nama_karyawan as created_by_name
            FROM setoran_ke_bank sb
            LEFT JOIN users u ON sb.created_by = u.kode_karyawan
            WHERE sb.id = ?";
        $stmt_detail = $pdo->prepare($sql_detail);
        $stmt_detail->execute([$detail_id]);
        $detail_view = $stmt_detail->fetch(PDO::FETCH_ASSOC);
        
        if ($detail_view) {
            // Get related setoran keuangan
            $sql_setoran = "
                SELECT sk.*, u.nama_karyawan
                FROM setoran_keuangan sk
                LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
                JOIN setoran_ke_bank_detail sbd ON sk.id = sbd.setoran_keuangan_id
                WHERE sbd.setoran_ke_bank_id = ?";
            $stmt_setoran = $pdo->prepare($sql_setoran);
            $stmt_setoran->execute([$detail_id]);
            $detail_transactions = $stmt_setoran->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Function to generate Excel download URL
function getExcelDownloadUrl($report_type, $tanggal_awal, $tanggal_akhir, $cabang) {
    $params = [
        'report_type' => $report_type,
        'tanggal_awal' => $tanggal_awal,
        'tanggal_akhir' => $tanggal_akhir,
        'cabang' => $cabang
    ];
    return 'export_setoran_excel.php?' . http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Setoran</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        :root {
            --primary-color: #007bff;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --secondary-color: #6c757d;
            --background-light: #f8fafc;
            --text-dark: #334155;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--background-light);
            color: var(--text-dark);
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: auto;
            background: #1e293b;
            height: 100vh;
            position: fixed;
            padding: 20px 0;
            transition: width 0.3s ease;
            overflow-y: auto;
        }
        .sidebar h2 {
            color: white;
            text-align: center;
            padding: 0 20px 20px 20px;
            border-bottom: 1px solid #334155;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 600;
        }
        .sidebar a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            white-space: nowrap;
            transition: all 0.3s ease;
        }
        .sidebar a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }
        .sidebar a.active {
            background: var(--primary-color);
            color: white;
        }
        .sidebar a i {
            margin-right: 10px;
            width: 18px;
            text-align: center;
        }
        .main-content {
            margin-left: 300px;
            padding: 30px;
            flex: 1;
            transition: margin-left 0.3s ease;
        }
        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        .welcome-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .welcome-card h1 {
            font-size: 24px;
            margin-bottom: 15px;
            color: var(--text-dark);
        }
        .info-tags {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        .info-tag {
            background: var(--background-light);
            padding: 8px 12px;
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-dark);
        }
        .filter-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .filter-card h2 {
            font-size: 18px;
            margin-bottom: 20px;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-inline {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: end;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 14px;
        }
        .form-control {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            background: white;
            min-width: 120px;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            border: 1px solid transparent;
        }
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }
        .btn-success {
            background-color: var(--success-color);
            color: white;
        }
        .btn-success:hover {
            background-color: #1e7e34;
        }
        .btn-info {
            background-color: var(--info-color);
            color: white;
        }
        .btn-info:hover {
            background-color: #138496;
        }
        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
        }
        .btn-secondary:hover {
            background-color: #545b62;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        .btn-excel {
            background: linear-gradient(135deg, #217346, #28a745);
            color: white;
            box-shadow: 0 2px 4px rgba(40, 167, 69, 0.3);
        }
        .btn-excel:hover {
            background: linear-gradient(135deg, #1e6940, #1e7e34);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.4);
            transform: translateY(-1px);
        }
        .content-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .content-header {
            background: var(--background-light);
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .content-header h3 {
            margin: 0;
            color: var(--text-dark);
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .content-header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .content-body {
            padding: 24px;
        }
        .table-container {
            overflow-x: auto;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        .table th {
            background: var(--background-light);
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 13px;
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }
        .table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
            vertical-align: middle;
        }
        .table tbody tr:hover {
            background: rgba(0,123,255,0.05);
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .summary-item {
            background: var(--background-light);
            padding: 15px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }
        .summary-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .summary-value {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
        }
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal.show {
            display: flex;
        }
        .modal-dialog {
            background: white;
            border-radius: 16px;
            max-width: 90%;
            max-height: 90%;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .modal-lg {
            max-width: 1000px;
        }
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
            margin: 0;
        }
        .btn-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
            padding: 0;
            text-decoration: none;
        }
        .btn-close:hover {
            color: var(--text-dark);
        }
        .modal-body {
            padding: 24px;
        }
        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .detail-value {
            font-size: 16px;
            font-weight: 500;
            color: var(--text-dark);
        }
        .detail-value.amount {
            font-size: 18px;
            font-weight: 600;
            color: var(--success-color);
        }
        .detail-value.difference {
            font-weight: 600;
        }
        .detail-value.difference.positive {
            color: var(--success-color);
        }
        .detail-value.difference.negative {
            color: var(--danger-color);
        }
        .image-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .image-item {
            text-align: center;
        }
        .image-item img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        .image-item img:hover {
            transform: scale(1.05);
        }
        .image-label {
            margin-top: 8px;
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        .no-data i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        .excel-info {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
            border: 1px solid rgba(40, 167, 69, 0.2);
            border-radius: 8px;
            padding: 10px 15px;
            margin-left: 10px;
            font-size: 12px;
            color: var(--success-color);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            .form-inline {
                flex-direction: column;
                align-items: stretch;
            }
            .form-group {
                width: 100%;
            }
            .detail-grid {
                grid-template-columns: 1fr;
            }
            .content-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .content-header-actions {
                width: 100%;
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="sidebar" id="sidebar">
    <h2><i class="fas fa-user-shield"></i> Dashboard Admin</h2>
    <a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    <a href="master_akun.php"><i class="fas fa-users-cog"></i> Master Akun</a>
    <a href="keping.php"><i class="fas fa-coins"></i> Master Nominal</a>
    <a href="detail_pemasukan.php"><i class="fas fa-file-invoice-dollar"></i> Detail Pemasukan</a>
    <a href="detail_pengeluaran.php"><i class="fas fa-file-invoice-dollar"></i> Detail Pengeluaran</a>
    <?php if ($is_admin || $is_super_admin): ?>
        <a href="index_kasir.php"><i class="fas fa-cash-register"></i> Dashboard Kasir</a>
    <?php endif; ?>
    <?php if ($is_super_admin): ?>
        <a href="users.php"><i class="fas fa-user-friends"></i> Master User</a>
        <a href="masterkey.php"><i class="fas fa-id-card"></i> Master Karyawan</a>
        <a href="cabang.php"><i class="fas fa-building"></i> Master Cabang</a>
        <a href="setoran_keuangan.php"><i class="fas fa-hand-holding-usd"></i> Manajemen Setoran</a>
        <a href="laporan_setoran.php" class="active"><i class="fas fa-chart-line"></i> Laporan Setoran</a>
        <a href="keuangan_pusat.php"><i class="fas fa-wallet"></i> Keuangan Pusat</a>
        <a href="laporan_keuangan_pusat.php"><i class="fas fa-file-alt"></i> Laporan Keuangan</a>
    <?php endif; ?>
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Kembali ke Dashboard</a>
</div>

<div class="main-content">
    <div class="user-profile">
        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
        <div>
            <strong><?php echo htmlspecialchars($username); ?></strong>
            <p style="color: var(--text-muted); font-size: 12px;">Super Admin</p>
        </div>
    </div>

    <div class="welcome-card">
        <h1><i class="fas fa-chart-line"></i> Laporan Setoran</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Analisis dan pelaporan komprehensif untuk aliran setoran dari cabang ke keuangan pusat hingga bank</p>
        <div class="info-tags">
            <div class="info-tag"><i class="fas fa-user"></i> User: <?php echo htmlspecialchars($username); ?></div>
            <div class="info-tag"><i class="fas fa-shield-alt"></i> Role: Super Admin</div>
            <div class="info-tag"><i class="fas fa-calendar-day"></i> Tanggal: <?php echo date('d M Y'); ?></div>
        </div>
    </div>

    <?php if ($detail_view): ?>
    <!-- Detail Modal -->
    <div class="modal show">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-file-alt"></i> 
                        <?php if ($report_type == 'cs_to_keuangan'): ?>
                            Detail Setoran CS ke Keuangan
                        <?php else: ?>
                            Detail Setoran Keuangan ke Bank
                        <?php endif; ?>
                    </h5>
                    <a href="?report_type=<?php echo $report_type; ?>" class="btn-close">&times;</a>
                </div>
                <div class="modal-body">
                    <?php if ($report_type == 'cs_to_keuangan'): ?>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Kode Setoran</div>
                                <div class="detail-value"><?php echo htmlspecialchars($detail_view['kode_setoran']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Tanggal Setoran</div>
                                <div class="detail-value"><?php echo date('d M Y', strtotime($detail_view['tanggal_setoran'])); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Cabang</div>
                                <div class="detail-value"><?php echo htmlspecialchars(ucfirst($detail_view['nama_cabang'])); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Kasir</div>
                                <div class="detail-value"><?php echo htmlspecialchars($detail_view['nama_karyawan']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Pengantar</div>
                                <div class="detail-value"><?php echo htmlspecialchars($detail_view['nama_pengantar']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <div class="detail-value"><?php echo htmlspecialchars($detail_view['status']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Nominal Sistem</div>
                                <div class="detail-value amount"><?php echo formatRupiah($detail_view['jumlah_setoran']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Jumlah Diterima</div>
                                <div class="detail-value amount"><?php echo $detail_view['jumlah_diterima'] ? formatRupiah($detail_view['jumlah_diterima']) : '-'; ?></div>
                            </div>
                            <?php if (isset($detail_view['selisih_setoran']) && !empty($detail_view['selisih_setoran'])): ?>
                            <div class="detail-item">
                                <div class="detail-label">Selisih</div>
                                <div class="detail-value difference <?php echo $detail_view['selisih_setoran'] > 0 ? 'positive' : 'negative'; ?>">
                                    <?php echo formatRupiah($detail_view['selisih_setoran']); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (isset($detail_view['catatan_validasi']) && !empty($detail_view['catatan_validasi'])): ?>
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Catatan Validasi</div>
                                <div class="detail-value"><?php echo htmlspecialchars($detail_view['catatan_validasi']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($detail_transactions): ?>
                        <div class="content-card" style="margin-top: 20px;">
                            <div class="content-header">
                                <h3><i class="fas fa-list"></i> Transaksi Terkait</h3>
                            </div>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Kode Transaksi</th>
                                            <th>Tanggal</th>
                                            <th>Jumlah Setoran</th>
                                            <th>Status</th>
                                            <th>Bukti</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($detail_transactions as $trans): ?>
                                            <tr>
                                                <td><code><?php echo htmlspecialchars($trans['kode_transaksi']); ?></code></td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($trans['tanggal_transaksi'])); ?></td>
                                                <td style="text-align: right; font-weight: 600;"><?php echo formatRupiah($trans['setoran_real']); ?></td>
                                                <td><?php echo htmlspecialchars($trans['deposit_status']); ?></td>
                                                <td>
                                                    <?php if (isset($trans['bukti_gambar_setoran']) && !empty($trans['bukti_gambar_setoran'])): ?>
                                                        <button class="btn btn-info btn-sm" onclick="openImageModal('<?php echo htmlspecialchars($trans['bukti_gambar_setoran']); ?>')">
                                                            <i class="fas fa-image"></i> Lihat
                                                        </button>
                                                    <?php else: ?>
                                                        <span style="color: var(--text-muted);">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                    <?php else: // keuangan_to_bank detail ?>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Kode Setoran Bank</div>
                                <div class="detail-value"><?php echo htmlspecialchars($detail_view['kode_setoran']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Tanggal Setoran</div>
                                <div class="detail-value"><?php echo date('d M Y', strtotime($detail_view['tanggal_setoran'])); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Metode Setoran</div>
                                <div class="detail-value"><?php echo htmlspecialchars($detail_view['metode_setoran']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Rekening Tujuan</div>
                                <div class="detail-value"><?php echo htmlspecialchars($detail_view['rekening_tujuan']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Total Setoran</div>
                                <div class="detail-value amount"><?php echo formatRupiah($detail_view['total_setoran']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Dibuat Oleh</div>
                                <div class="detail-value"><?php echo htmlspecialchars($detail_view['created_by_name']); ?></div>
                            </div>
                        </div>

                        <?php if (isset($detail_view['bukti_transfer']) && !empty($detail_view['bukti_transfer'])): ?>
                        <div style="margin-top: 20px;">
                            <div class="detail-label">Bukti Transfer</div>
                            <div class="image-gallery">
                                <div class="image-item">
                                    <img src="<?php echo htmlspecialchars($detail_view['bukti_transfer']); ?>" alt="Bukti Transfer" onclick="openImageModal(this.src)">
                                    <div class="image-label">Bukti Transfer Bank</div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($detail_transactions): ?>
                        <div class="content-card" style="margin-top: 20px;">
                            <div class="content-header">
                                <h3><i class="fas fa-list"></i> Setoran yang Disertakan</h3>
                            </div>
                            <div class="table-container">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Kode Setoran</th>
                                            <th>Tanggal</th>
                                            <th>Cabang</th>
                                            <th>Kasir</th>
                                            <th>Nominal Sistem</th>
                                            <th>Diterima</th>
                                            <th>Selisih</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($detail_transactions as $setoran): ?>
                                            <tr>
                                                <td><code><?php echo htmlspecialchars($setoran['kode_setoran']); ?></code></td>
                                                <td><?php echo date('d/m/Y', strtotime($setoran['tanggal_setoran'])); ?></td>
                                                <td><?php echo htmlspecialchars(ucfirst($setoran['nama_cabang'])); ?></td>
                                                <td><?php echo htmlspecialchars($setoran['nama_karyawan']); ?></td>
                                                <td style="text-align: right; font-weight: 600;"><?php echo formatRupiah($setoran['jumlah_setoran']); ?></td>
                                                <td style="text-align: right; font-weight: 600;"><?php echo formatRupiah($setoran['jumlah_diterima'] ?? $setoran['jumlah_setoran']); ?></td>
                                                <td style="text-align: right;">
                                                    <?php 
                                                    $selisih = $setoran['selisih_setoran'] ?? 0;
                                                    if ($selisih != 0) {
                                                        $color = $selisih > 0 ? 'var(--success-color)' : 'var(--danger-color)';
                                                        echo '<span style="color: ' . $color . '; font-weight: 600;">' . formatRupiah($selisih) . '</span>';
                                                    } else {
                                                        echo '<span style="color: var(--text-muted);">-</span>';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <a href="?report_type=<?php echo $report_type; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter Card -->
    <div class="filter-card">
        <h2><i class="fas fa-filter"></i> Filter Laporan</h2>
        <form action="" method="POST" class="form-inline">
            <div class="form-group">
                <label for="tanggal_awal">Tanggal Awal:</label>
                <input type="date" name="tanggal_awal" class="form-control" value="<?php echo htmlspecialchars($tanggal_awal); ?>">
            </div>
            <div class="form-group">
                <label for="tanggal_akhir">Tanggal Akhir:</label>
                <input type="date" name="tanggal_akhir" class="form-control" value="<?php echo htmlspecialchars($tanggal_akhir); ?>">
            </div>
            <div class="form-group">
                <label for="cabang">Cabang:</label>
                <select name="cabang" class="form-control">
                    <option value="all" <?php echo $cabang == 'all' ? 'selected' : ''; ?>>Semua Cabang</option>
                    <?php foreach ($cabang_list as $nama_cabang): ?>
                        <option value="<?php echo htmlspecialchars($nama_cabang); ?>" <?php echo $cabang == $nama_cabang ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($nama_cabang)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="report_type">Tipe Laporan:</label>
                <select name="report_type" class="form-control" onchange="this.form.submit()">
                    <option value="cs_to_keuangan" <?php echo $report_type == 'cs_to_keuangan' ? 'selected' : ''; ?>>CS ke Keuangan</option>
                    <option value="keuangan_to_bank" <?php echo $report_type == 'keuangan_to_bank' ? 'selected' : ''; ?>>Keuangan ke Bank</option>
                    <option value="rekapitulasi" <?php echo $report_type == 'rekapitulasi' ? 'selected' : ''; ?>>Rekapitulasi</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Filter
            </button>
        </form>
    </div>

    <?php if ($report_type == 'cs_to_keuangan'): ?>
    <div class="content-card">
        <div class="content-header">
            <h3><i class="fas fa-exchange-alt"></i> Laporan Setoran CS ke Keuangan</h3>
            <div class="content-header-actions">
                <a href="<?php echo getExcelDownloadUrl($report_type, $tanggal_awal, $tanggal_akhir, $cabang); ?>" 
                   class="btn btn-excel" target="_blank">
                    <i class="fas fa-file-excel"></i> Unduh Excel
                </a>
                <div class="excel-info">
                    <i class="fas fa-info-circle"></i>
                    Termasuk gambar bukti setoran
                </div>
            </div>
        </div>
        <div class="content-body">
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Tanggal Setoran</th>
                            <th>Kode Setoran</th>
                            <th>Nama Kasir</th>
                            <th>Cabang</th>
                            <th>Jumlah Setoran</th>
                            <th>Jumlah Diterima</th>
                            <th>Selisih</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($data): ?>
                            <?php foreach ($data as $row): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($row['tanggal_setoran'])); ?></td>
                                    <td><code><?php echo htmlspecialchars($row['kode_setoran']); ?></code></td>
                                    <td><?php echo htmlspecialchars($row['nama_karyawan']); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($row['nama_cabang'])); ?></td>
                                    <td style="text-align: right; font-weight: 600;"><?php echo formatRupiah($row['jumlah_setoran']); ?></td>
                                    <td style="text-align: right; font-weight: 600;"><?php echo $row['jumlah_diterima'] !== null ? formatRupiah($row['jumlah_diterima']) : '-'; ?></td>
                                    <td style="text-align: right;">
                                        <?php 
                                        if ($row['selisih_setoran'] !== null && $row['selisih_setoran'] != 0) {
                                            $color = $row['selisih_setoran'] > 0 ? 'var(--success-color)' : 'var(--danger-color)';
                                            echo '<span style="color: ' . $color . '; font-weight: 600;">' . formatRupiah($row['selisih_setoran']) . '</span>';
                                        } else {
                                            echo '<span style="color: var(--text-muted);">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                                    <td>
                                        <a href="?report_type=cs_to_keuangan&detail_id=<?php echo $row['id']; ?>" class="btn btn-info btn-sm">
                                            <i class="fas fa-eye"></i> Detail
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="no-data">
                                    <i class="fas fa-inbox"></i><br>
                                    Tidak ada data ditemukan
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($summary): ?>
            <div class="content-header" style="margin-top: 30px; background: none; padding: 0; border: none;">
                <h3><i class="fas fa-chart-bar"></i> Ringkasan</h3>
            </div>
            <div class="summary-grid">
                <?php foreach ($summary as $row): ?>
                    <div class="summary-item">
                        <div class="summary-label"><?php echo htmlspecialchars($row['status']); ?></div>
                        <div class="summary-value"><?php echo $row['count']; ?> transaksi</div>
                        <div style="font-size: 14px; color: var(--text-muted); margin-top: 5px;">
                            Setoran: <?php echo formatRupiah($row['total_setoran']); ?><br>
                            Diterima: <?php echo formatRupiah($row['total_diterima'] ?? 0); ?><br>
                            Selisih: <?php echo formatRupiah($row['total_selisih'] ?? 0); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($report_type == 'keuangan_to_bank'): ?>
    <div class="content-card">
        <div class="content-header">
            <h3><i class="fas fa-university"></i> Laporan Setoran Keuangan ke Bank</h3>
            <div class="content-header-actions">
                <a href="<?php echo getExcelDownloadUrl($report_type, $tanggal_awal, $tanggal_akhir, $cabang); ?>" 
                   class="btn btn-excel" target="_blank">
                    <i class="fas fa-file-excel"></i> Unduh Excel
                </a>
                <div class="excel-info">
                    <i class="fas fa-info-circle"></i>
                    Termasuk gambar bukti transfer
                </div>
            </div>
        </div>
        <div class="content-body">
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kode Setoran</th>
                            <th>Tanggal Setoran</th>
                            <th>Metode Setoran</th>
                            <th>Rekening Tujuan</th>
                            <th>Total Setoran</th>
                            <th>Cabang Asal</th>
                            <th>Dibuat Oleh</th>
                            <th>Bukti Transfer</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($data): ?>
                            <?php foreach ($data as $row): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($row['kode_setoran']); ?></code></td>
                                    <td><?php echo date('d/m/Y', strtotime($row['tanggal_setoran'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['metode_setoran']); ?></td>
                                    <td><?php echo htmlspecialchars($row['rekening_tujuan']); ?></td>
                                    <td style="text-align: right; font-weight: 600;"><?php echo formatRupiah($row['total_setoran']); ?></td>
                                    <td><?php echo htmlspecialchars($row['cabang_list']); ?></td>
                                    <td><?php echo htmlspecialchars($row['created_by_name']); ?></td>
                                    <td>
                                        <?php if (isset($row['bukti_transfer']) && !empty($row['bukti_transfer'])): ?>
                                            <button class="btn btn-info btn-sm" onclick="openImageModal('<?php echo htmlspecialchars($row['bukti_transfer']); ?>')">
                                                <i class="fas fa-image"></i> Lihat
                                            </button>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted);">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                            <a href="?report_type=keuangan_to_bank&detail_id=<?php echo $row['id']; ?>" class="btn btn-info btn-sm">
                                                <i class="fas fa-eye"></i> Detail
                                            </a>
                                            <a href="?report_type=keuangan_to_bank&print_proof=<?php echo $row['id']; ?>" class="btn btn-secondary btn-sm" target="_blank">
                                                <i class="fas fa-print"></i> Cetak
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="no-data">
                                    <i class="fas fa-university"></i><br>
                                    Tidak ada data ditemukan
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($summary): ?>
            <div class="content-header" style="margin-top: 30px; background: none; padding: 0; border: none;">
                <h3><i class="fas fa-chart-bar"></i> Ringkasan</h3>
            </div>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">Total Setoran</div>
                    <div class="summary-value"><?php echo formatRupiah($summary['total']); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Jumlah Transaksi</div>
                    <div class="summary-value"><?php echo $summary['count']; ?> transaksi</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($report_type == 'rekapitulasi'): ?>
    <div class="content-card">
        <div class="content-header">
            <h3><i class="fas fa-chart-pie"></i> Rekapitulasi Setoran</h3>
            <div class="content-header-actions">
                <a href="<?php echo getExcelDownloadUrl($report_type, $tanggal_awal, $tanggal_akhir, $cabang); ?>" 
                   class="btn btn-excel" target="_blank">
                    <i class="fas fa-file-excel"></i> Unduh Excel
                </a>
                <div class="excel-info">
                    <i class="fas fa-info-circle"></i>
                    Ringkasan lengkap dengan persentase
                </div>
            </div>
        </div>
        <div class="content-body">
            <div class="summary-grid">
                <div class="summary-item" style="border-left: 4px solid var(--info-color);">
                    <div class="summary-label">Total Setoran Diterima dari CS</div>
                    <div class="summary-value"><?php echo formatRupiah($summary['total_cs']); ?></div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">
                        Jumlah uang yang telah diterima dan divalidasi dari seluruh cabang
                    </div>
                </div>
                <div class="summary-item" style="border-left: 4px solid <?php echo $summary['total_selisih_cs'] > 0 ? 'var(--danger-color)' : 'var(--success-color)'; ?>;">
                    <div class="summary-label">Total Selisih Setoran CS</div>
                    <div class="summary-value" style="color: <?php echo $summary['total_selisih_cs'] > 0 ? 'var(--danger-color)' : 'var(--success-color)'; ?>">
                        <?php echo formatRupiah($summary['total_selisih_cs']); ?>
                    </div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">
                        Selisih antara sistem dan fisik saat validasi
                    </div>
                </div>
                <div class="summary-item" style="border-left: 4px solid var(--success-color);">
                    <div class="summary-label">Total Setoran ke Bank</div>
                    <div class="summary-value"><?php echo formatRupiah($summary['total_bank']); ?></div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">
                        Jumlah uang yang telah disetor ke bank
                    </div>
                </div>
                <div class="summary-item" style="border-left: 4px solid <?php echo $summary['selisih'] > 0 ? 'var(--warning-color)' : 'var(--success-color)'; ?>;">
                    <div class="summary-label">Saldo Belum Disetor</div>
                    <div class="summary-value" style="color: <?php echo $summary['selisih'] > 0 ? 'var(--warning-color)' : 'var(--success-color)'; ?>">
                        <?php echo formatRupiah($summary['selisih']); ?>
                    </div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">
                        Sisa uang yang belum disetor ke bank
                    </div>
                </div>
            </div>

            <!-- Flow Chart -->
            <div style="margin-top: 30px; padding: 20px; background: var(--background-light); border-radius: 12px;">
                <h4 style="margin-bottom: 20px; color: var(--text-dark);"><i class="fas fa-sitemap"></i> Alur Dana</h4>
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                    <div style="text-align: center; flex: 1; min-width: 150px;">
                        <div style="background: var(--info-color); color: white; padding: 15px; border-radius: 8px; margin-bottom: 8px;">
                            <i class="fas fa-store" style="font-size: 24px; margin-bottom: 5px; display: block;"></i>
                            <strong>Cabang</strong>
                        </div>
                        <div style="font-size: 14px; color: var(--text-dark); font-weight: 600;">
                            <?php echo formatRupiah($summary['total_cs']); ?>
                        </div>
                    </div>
                    <div style="color: var(--text-muted); font-size: 24px;">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div style="text-align: center; flex: 1; min-width: 150px;">
                        <div style="background: var(--warning-color); color: #212529; padding: 15px; border-radius: 8px; margin-bottom: 8px;">
                            <i class="fas fa-building" style="font-size: 24px; margin-bottom: 5px; display: block;"></i>
                            <strong>Keuangan</strong>
                        </div>
                        <div style="font-size: 14px; color: var(--text-dark); font-weight: 600;">
                            <?php echo formatRupiah($summary['selisih']); ?>
                            <div style="font-size: 11px; color: var(--text-muted);">Belum disetor</div>
                        </div>
                    </div>
                    <div style="color: var(--text-muted); font-size: 24px;">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div style="text-align: center; flex: 1; min-width: 150px;">
                        <div style="background: var(--success-color); color: white; padding: 15px; border-radius: 8px; margin-bottom: 8px;">
                            <i class="fas fa-university" style="font-size: 24px; margin-bottom: 5px; display: block;"></i>
                            <strong>Bank</strong>
                        </div>
                        <div style="font-size: 14px; color: var(--text-dark); font-weight: 600;">
                            <?php echo formatRupiah($summary['total_bank']); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Image Modal -->
    <div class="modal" id="imageModal">
        <div class="modal-dialog" style="max-width: 90%; max-height: 90%;">
            <div style="background: white; border-radius: 16px; padding: 20px; text-align: center;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h5 style="margin: 0;"><i class="fas fa-image"></i> Lihat Gambar</h5>
                    <button onclick="closeImageModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted);">&times;</button>
                </div>
                <img id="modalImage" src="" alt="Gambar" style="max-width: 100%; max-height: 70vh; border-radius: 8px;">
            </div>
        </div>
    </div>
</div>

<script>
    // Image modal functions
    function openImageModal(src) {
        document.getElementById('modalImage').src = src;
        document.getElementById('imageModal').classList.add('show');
    }

    function closeImageModal() {
        document.getElementById('imageModal').classList.remove('show');
    }

    // Close modals when clicking outside
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                if (this.id === 'imageModal') {
                    closeImageModal();
                } else {
                    window.location.href = '?report_type=<?php echo $report_type; ?>';
                }
            }
        });
    });

    // Adjust sidebar width
    function adjustSidebarWidth() {
        const sidebar = document.getElementById('sidebar');
        const links = sidebar.getElementsByTagName('a');
        let maxWidth = 0;

        for (let link of links) {
            link.style.whiteSpace = 'nowrap';
            const width = link.getBoundingClientRect().width;
            if (width > maxWidth) {
                maxWidth = width;
            }
        }

        const minWidth = 280;
        sidebar.style.width = maxWidth > minWidth ? `${maxWidth + 40}px` : `${minWidth}px`;
        document.querySelector('.main-content').style.marginLeft = sidebar.style.width;
    }

    // Run on page load and window resize
    window.addEventListener('load', adjustSidebarWidth);
    window.addEventListener('resize', adjustSidebarWidth);
</script>
</body>
</html>