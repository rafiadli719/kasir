<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
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

// Check if the user is logged in and has a role of admin or super_admin
if (!isset($_SESSION['kode_karyawan']) || !isset($_SESSION['role']) || 
    !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header('Location: ../../login_dashboard/login.php');
    exit;
}
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    die("Akses ditolak. Hanya untuk admin dan super_admin.");
}

$is_super_admin = $_SESSION['role'] === 'super_admin';
$is_admin = $_SESSION['role'] === 'admin';
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User';
$kode_karyawan = $_SESSION['kode_karyawan'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_rekening'])) {
        try {
            // Check if no_rekening already exists for the same kode_cabang
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM master_rekening_cabang WHERE kode_cabang = ? AND no_rekening = ?");
            $stmt->execute([$_POST['kode_cabang'], $_POST['no_rekening']]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Nomor rekening sudah terdaftar untuk cabang ini!";
            } else {
                $stmt = $pdo->prepare("INSERT INTO master_rekening_cabang (kode_cabang, nama_bank, no_rekening, nama_rekening, jenis_rekening, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['kode_cabang'],
                    $_POST['nama_bank'],
                    $_POST['no_rekening'],
                    $_POST['nama_rekening'],
                    $_POST['jenis_rekening'],
                    $_POST['status'],
                    $kode_karyawan
                ]);
                $success = "Rekening berhasil ditambahkan!";
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['edit_rekening'])) {
        try {
            // Check if no_rekening already exists for the same kode_cabang (excluding current record)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM master_rekening_cabang WHERE kode_cabang = ? AND no_rekening = ? AND id != ?");
            $stmt->execute([$_POST['kode_cabang'], $_POST['no_rekening'], $_POST['id']]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Nomor rekening sudah terdaftar untuk cabang ini!";
            } else {
                $stmt = $pdo->prepare("UPDATE master_rekening_cabang SET kode_cabang = ?, nama_bank = ?, no_rekening = ?, nama_rekening = ?, jenis_rekening = ?, status = ?, updated_by = ? WHERE id = ?");
                $stmt->execute([
                    $_POST['kode_cabang'],
                    $_POST['nama_bank'],
                    $_POST['no_rekening'],
                    $_POST['nama_rekening'],
                    $_POST['jenis_rekening'],
                    $_POST['status'],
                    $kode_karyawan,
                    $_POST['id']
                ]);
                $success = "Rekening berhasil diupdate!";
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_rekening'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM master_rekening_cabang WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $success = "Rekening berhasil dihapus!";
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Fetch all rekening with cabang relation
$stmt = $pdo->query("
    SELECT mr.*, c.nama_cabang 
    FROM master_rekening_cabang mr
    LEFT JOIN cabang c ON mr.kode_cabang = c.kode_cabang
    ORDER BY c.nama_cabang, mr.nama_bank
");
$rekening_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch cabang for dropdown
$stmt_cabang = $pdo->query("SELECT kode_cabang, nama_cabang FROM cabang ORDER BY nama_cabang");
$cabang_list = $stmt_cabang->fetchAll(PDO::FETCH_ASSOC);

// Get edit data if editing
$edit_data = null;
if (isset($_GET['edit_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM master_rekening_cabang WHERE id = ?");
    $stmt->execute([$_GET['edit_id']]);
    $edit_data = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Rekening Cabang</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="includes/sidebar.css" rel="stylesheet">
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
        .content-body {
            padding: 24px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-label {
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 14px;
        }
        .form-control {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
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
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
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
            background-color: #218838;
        }
        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }
        .btn-danger:hover {
            background-color: #c82333;
        }
        .btn-warning {
            background-color: var(--warning-color);
            color: #212529;
        }
        .btn-warning:hover {
            background-color: #e0a800;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
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
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-badge.active {
            background: rgba(40,167,69,0.2);
            color: #155724;
        }
        .status-badge.inactive {
            background: rgba(220,53,69,0.2);
            color: #721c24;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: none;
            align-items: center;
            gap: 10px;
        }
        .alert.show {
            display: flex;
        }
        .alert-success {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
            border: 1px solid rgba(40,167,69,0.2);
        }
        .alert-danger {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220,53,69,0.2);
        }
        .required {
            color: var(--danger-color);
        }
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main-content">
    <div class="user-profile">
        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
        <div>
            <strong><?php echo htmlspecialchars($username); ?></strong>
            <p style="color: var(--text-muted); font-size: 12px;"><?php echo ucfirst($role); ?></p>
        </div>
    </div>

    <h1 style="margin-bottom: 24px; color: var(--text-dark);"><i class="fas fa-university"></i> Master Rekening Cabang</h1>

    <?php if (isset($success)): ?>
        <div class="alert alert-success show">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger show">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Form Add/Edit Rekening -->
    <div class="content-card">
        <div class="content-header">
            <h3>
                <i class="fas fa-<?php echo $edit_data ? 'edit' : 'plus'; ?>"></i> 
                <?php echo $edit_data ? 'Edit' : 'Tambah'; ?> Rekening Cabang
            </h3>
        </div>
        <div class="content-body">
            <form action="" method="POST">
                <?php if ($edit_data): ?>
                    <input type="hidden" name="id" value="<?php echo $edit_data['id']; ?>">
                <?php endif; ?>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Cabang <span class="required">*</span></label>
                        <select name="kode_cabang" class="form-control" required>
                            <option value="">Pilih Cabang</option>
                            <?php foreach ($cabang_list as $cabang): ?>
                                <option value="<?php echo $cabang['kode_cabang']; ?>" 
                                        <?php echo ($edit_data && $edit_data['kode_cabang'] == $cabang['kode_cabang']) ? 'selected' : ''; ?>>
                                    <?php echo $cabang['kode_cabang'] . ' - ' . $cabang['nama_cabang']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Nama Bank <span class="required">*</span></label>
                        <select name="nama_bank" class="form-control" required>
                            <option value="">Pilih Bank</option>
                            <option value="Bank BCA" <?php echo ($edit_data && $edit_data['nama_bank'] == 'Bank BCA') ? 'selected' : ''; ?>>Bank BCA</option>
                            <option value="Bank BRI" <?php echo ($edit_data && $edit_data['nama_bank'] == 'Bank BRI') ? 'selected' : ''; ?>>Bank BRI</option>
                            <option value="Bank BNI" <?php echo ($edit_data && $edit_data['nama_bank'] == 'Bank BNI') ? 'selected' : ''; ?>>Bank BNI</option>
                            <option value="Bank Mandiri" <?php echo ($edit_data && $edit_data['nama_bank'] == 'Bank Mandiri') ? 'selected' : ''; ?>>Bank Mandiri</option>
                            <option value="Bank BTN" <?php echo ($edit_data && $edit_data['nama_bank'] == 'Bank BTN') ? 'selected' : ''; ?>>Bank BTN</option>
                            <option value="Bank CIMB Niaga" <?php echo ($edit_data && $edit_data['nama_bank'] == 'Bank CIMB Niaga') ? 'selected' : ''; ?>>Bank CIMB Niaga</option>
                            <option value="Bank Danamon" <?php echo ($edit_data && $edit_data['nama_bank'] == 'Bank Danamon') ? 'selected' : ''; ?>>Bank Danamon</option>
                            <option value="Bank Permata" <?php echo ($edit_data && $edit_data['nama_bank'] == 'Bank Permata') ? 'selected' : ''; ?>>Bank Permata</option>
                            <option value="Bank Syariah Indonesia" <?php echo ($edit_data && $edit_data['nama_bank'] == 'Bank Syariah Indonesia') ? 'selected' : ''; ?>>Bank Syariah Indonesia</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Nomor Rekening <span class="required">*</span></label>
                        <input type="text" name="no_rekening" class="form-control" 
                               value="<?php echo $edit_data ? htmlspecialchars($edit_data['no_rekening']) : ''; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Nama Rekening <span class="required">*</span></label>
                        <input type="text" name="nama_rekening" class="form-control" 
                               value="<?php echo $edit_data ? htmlspecialchars($edit_data['nama_rekening']) : ''; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Jenis Rekening <span class="required">*</span></label>
                        <select name="jenis_rekening" class="form-control" required>
                            <option value="Milik Sendiri" <?php echo ($edit_data && $edit_data['jenis_rekening'] == 'Milik Sendiri') ? 'selected' : ''; ?>>Milik Sendiri</option>
                            <option value="Mitra" <?php echo ($edit_data && $edit_data['jenis_rekening'] == 'Mitra') ? 'selected' : ''; ?>>Mitra</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status <span class="required">*</span></label>
                        <select name="status" class="form-control" required>
                            <option value="active" <?php echo ($edit_data && $edit_data['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($edit_data && $edit_data['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                
                <div style="margin-top: 20px;">
                    <button type="submit" name="<?php echo $edit_data ? 'edit_rekening' : 'add_rekening'; ?>" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $edit_data ? 'Update' : 'Tambah'; ?> Rekening
                    </button>
                    <?php if ($edit_data): ?>
                        <a href="master_rekening_cabang.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Batal
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Rekening -->
    <div class="content-card">
        <div class="content-header">
            <h3><i class="fas fa-list"></i> Daftar Rekening Cabang</h3>
        </div>
        <div class="content-body">
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kode Cabang</th>
                            <th>Nama Cabang</th>
                            <th>Bank</th>
                            <th>No. Rekening</th>
                            <th>Nama Rekening</th>
                            <th>Jenis</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rekening_list as $rekening): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($rekening['kode_cabang']); ?></td>
                                <td><?php echo htmlspecialchars($rekening['nama_cabang'] ?? 'Cabang Tidak Ditemukan'); ?></td>
                                <td><?php echo htmlspecialchars($rekening['nama_bank']); ?></td>
                                <td><code><?php echo htmlspecialchars($rekening['no_rekening']); ?></code></td>
                                <td><?php echo htmlspecialchars($rekening['nama_rekening']); ?></td>
                                <td>
                                    <?php if ($rekening['jenis_rekening'] == 'Mitra'): ?>
                                        <span class="status-badge" style="background: rgba(255,193,7,0.2); color: #856404;">Mitra</span>
                                    <?php else: ?>
                                        <span class="status-badge" style="background: rgba(0,123,255,0.2); color: #004085;">Milik Sendiri</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $rekening['status']; ?>">
                                        <?php echo ucfirst($rekening['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?edit_id=<?php echo $rekening['id']; ?>" class="btn btn-warning btn-sm">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <form action="" method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menghapus rekening ini?')">
                                            <input type="hidden" name="id" value="<?php echo $rekening['id']; ?>">
                                            <button type="submit" name="delete_rekening" class="btn btn-danger btn-sm">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Auto hide alerts
document.querySelectorAll('.alert.show').forEach(alert => {
    setTimeout(() => {
        alert.classList.remove('show');
    }, 5000);
});
</script>
</body>
</html>