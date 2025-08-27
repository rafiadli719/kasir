<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'config.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    die("Akses ditolak. Hanya untuk admin dan super_admin.");
}

$is_super_admin = $_SESSION['role'] === 'super_admin';
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';

// Check if user is super admin
if (!isset($_SESSION['kode_karyawan']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../../login_dashboard/login.php');
    exit();
}

// Get parameters
$id = $_GET['id'] ?? null;
$jenis = $_GET['jenis'] ?? null;

if (!$id || !in_array($jenis, ['pemasukan', 'pengeluaran'])) {
    $_SESSION['error_message'] = "Parameter tidak valid!";
    header('Location: keuangan_pusat.php?tab=riwayat');
    exit();
}

// Get transaction data for logging purposes
if ($jenis === 'pemasukan') {
    $query = "SELECT * FROM pemasukan_pusat WHERE id = ?";
} else {
    $query = "SELECT * FROM pengeluaran_pusat WHERE id = ?";
}
$stmt = $pdo->prepare($query);
$stmt->execute([$id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    $_SESSION['error_message'] = "Transaksi tidak ditemukan!";
    header('Location: keuangan_pusat.php?tab=riwayat');
    exit();
}

// Handle deletion confirmation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm_delete'])) {
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        // Log the deletion for audit purposes
        $audit_data = json_encode($transaction);
        $audit_query = "INSERT INTO audit_log (kode_karyawan, action, table_name, record_id, old_data) 
                        VALUES (?, 'DELETE', ?, ?, ?)";
        $audit_stmt = $pdo->prepare($audit_query);
        $table_name = ($jenis === 'pemasukan') ? 'pemasukan_pusat' : 'pengeluaran_pusat';
        $audit_stmt->execute([$_SESSION['kode_karyawan'], $table_name, $id, $audit_data]);
        
        // Delete the transaction
        if ($jenis === 'pemasukan') {
            $delete_query = "DELETE FROM pemasukan_pusat WHERE id = ?";
        } else {
            $delete_query = "DELETE FROM pengeluaran_pusat WHERE id = ?";
        }
        $delete_stmt = $pdo->prepare($delete_query);
        $delete_stmt->execute([$id]);
        
        // Commit transaction
        $pdo->commit();
        
        $_SESSION['success_message'] = ucfirst($jenis) . " berhasil dihapus!";
        header('Location: keuangan_pusat.php?tab=riwayat');
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction
        $pdo->rollBack();
        $_SESSION['error_message'] = "Gagal menghapus " . $jenis . ": " . $e->getMessage();
        header('Location: keuangan_pusat.php?tab=riwayat');
        exit();
    }
}

// If not POST request, show confirmation page
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Hapus <?php echo ucfirst($jenis); ?> - Super Admin</title>
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
            padding: 30px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            max-width: 600px;
            width: 100%;
        }
        .confirmation-card {
            background: white;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border: 1px solid var(--border-color);
            text-align: center;
        }
        .warning-icon {
            font-size: 64px;
            color: var(--danger-color);
            margin-bottom: 24px;
        }
        .confirmation-card h1 {
            font-size: 28px;
            margin-bottom: 16px;
            color: var(--text-dark);
        }
        .confirmation-card p {
            font-size: 16px;
            color: var(--text-muted);
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .transaction-details {
            background: var(--background-light);
            border-radius: 12px;
            padding: 20px;
            margin: 24px 0;
            text-align: left;
        }
        .transaction-details h3 {
            margin-bottom: 16px;
            color: var(--text-dark);
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }
        .detail-item {
            font-size: 14px;
        }
        .detail-label {
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        .detail-value {
            color: var(--text-dark);
            font-family: 'Courier New', monospace;
            word-break: break-all;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            border: 1px solid transparent;
            margin: 0 8px;
        }
        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }
        .btn-danger:hover {
            background-color: #bd2130;
            transform: translateY(-1px);
        }
        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
        }
        .btn-secondary:hover {
            background-color: #545b62;
            transform: translateY(-1px);
        }
        .btn-group {
            display: flex;
            justify-content: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-top: 32px;
        }
        .amount-highlight {
            color: var(--danger-color);
            font-weight: bold;
            font-size: 16px;
        }
        .warning-text {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
            padding: 16px;
            border-radius: 8px;
            border: 1px solid rgba(220,53,69,0.2);
            margin: 16px 0;
            font-size: 14px;
            font-weight: 500;
        }
        @media (max-width: 768px) {
            body {
                padding: 20px;
            }
            .confirmation-card {
                padding: 24px;
            }
            .confirmation-card h1 {
                font-size: 24px;
            }
            .btn-group {
                flex-direction: column;
                align-items: stretch;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="confirmation-card">
        <div class="warning-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        
        <h1>Konfirmasi Penghapusan</h1>
        <p>Anda yakin ingin menghapus <?php echo $jenis; ?> ini? Tindakan ini tidak dapat dibatalkan!</p>
        
        <div class="transaction-details">
            <h3>
                <?php if ($jenis === 'pemasukan'): ?>
                    <i class="fas fa-arrow-up" style="color: var(--success-color);"></i> Detail Pemasukan
                <?php else: ?>
                    <i class="fas fa-arrow-down" style="color: var(--danger-color);"></i> Detail Pengeluaran
                <?php endif; ?>
            </h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">ID Transaksi:</div>
                    <div class="detail-value">#<?php echo $transaction['id']; ?></div>
                </div>
                
                <?php if ($jenis === 'pengeluaran' && $transaction['kode_transaksi']): ?>
                <div class="detail-item">
                    <div class="detail-label">Kode Transaksi:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($transaction['kode_transaksi']); ?></div>
                </div>
                <?php endif; ?>
                
                <div class="detail-item">
                    <div class="detail-label">Tanggal:</div>
                    <div class="detail-value"><?php echo date('d/m/Y', strtotime($transaction['tanggal'])); ?></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Cabang:</div>
                    <div class="detail-value"><?php echo htmlspecialchars(ucfirst($transaction['cabang'])); ?></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Kode Akun:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($transaction['kode_akun']); ?></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Jumlah:</div>
                    <div class="detail-value amount-highlight">Rp <?php echo number_format($transaction['jumlah'], 0, ',', '.'); ?></div>
                </div>
                
                <?php if ($jenis === 'pengeluaran'): ?>
                <div class="detail-item">
                    <div class="detail-label">Kategori:</div>
                    <div class="detail-value"><?php echo htmlspecialchars(ucfirst($transaction['kategori'] ?? '-')); ?></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Umur Pakai:</div>
                    <div class="detail-value"><?php echo ($transaction['umur_pakai'] ?? 0); ?> bulan</div>
                </div>
                <?php endif; ?>
                
                <div class="detail-item" style="grid-column: 1 / -1;">
                    <div class="detail-label">Keterangan:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($transaction['keterangan']); ?></div>
                </div>
                
                <div class="detail-item">
                    <div class="detail-label">Dibuat:</div>
                    <div class="detail-value"><?php echo date('d/m/Y H:i:s', strtotime($transaction['created_at'])); ?></div>
                </div>
                
                <?php if (isset($transaction['updated_at']) && $transaction['updated_at'] !== $transaction['created_at']): ?>
                <div class="detail-item">
                    <div class="detail-label">Diupdate:</div>
                    <div class="detail-value"><?php echo date('d/m/Y H:i:s', strtotime($transaction['updated_at'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="warning-text">
            <i class="fas fa-exclamation-circle"></i>
            <strong>Peringatan:</strong> Data yang dihapus akan disimpan dalam log audit untuk keperluan tracking, 
            namun tidak dapat dikembalikan ke sistem aktif.
        </div>
        
        <form method="POST" action="">
            <div class="btn-group">
                <button type="submit" name="confirm_delete" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Ya, Hapus <?php echo ucfirst($jenis); ?>
                </button>
                <a href="keuangan_pusat.php?tab=riwayat" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Batal
                </a>
            </div>
        </form>
    </div>
</div>

<script>
    // Auto focus on delete button for keyboard users
    document.addEventListener('DOMContentLoaded', function() {
        // Add confirmation on form submit
        document.querySelector('form').addEventListener('submit', function(e) {
            const confirmed = confirm('Apakah Anda benar-benar yakin ingin menghapus transaksi ini?\n\nTindakan ini TIDAK DAPAT DIBATALKAN!');
            if (!confirmed) {
                e.preventDefault();
            }
        });
    });
</script>

</body>
</html>