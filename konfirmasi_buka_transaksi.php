<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Jakarta');

// Initialize PDO connection
$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Check session and role - only super admin can access
if (!isset($_SESSION['kode_karyawan']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ../../login_dashboard/login.php');
    exit;
}

$kode_karyawan_admin = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';

// Handle konfirmasi action
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['approve_request'])) {
        $request_id = $_POST['request_id'];
        $catatan_admin = trim($_POST['catatan_admin'] ?? '');
        
        try {
            $pdo->beginTransaction();
            
            // Get request details
            $sql_get_request = "SELECT kode_transaksi FROM konfirmasi_buka_transaksi WHERE id = ? AND status = 'pending'";
            $stmt_get = $pdo->prepare($sql_get_request);
            $stmt_get->execute([$request_id]);
            $request = $stmt_get->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                throw new Exception("Permintaan tidak ditemukan atau sudah diproses");
            }
            
            // Update status transaksi menjadi "on proses" (sesuai status awal)
            $sql_update_transaction = "UPDATE kasir_transactions SET 
                                      status = 'on proses',
                                      catatan_validasi = CONCAT(COALESCE(catatan_validasi, ''), '\n--- DIBUKA KEMBALI OLEH ADMIN ---\nTanggal: ', NOW(), '\nDiproses oleh: ', ?, '\nCatatan: ', ?)
                                      WHERE kode_transaksi = ?";
            $stmt_update_trans = $pdo->prepare($sql_update_transaction);
            $result = $stmt_update_trans->execute([$username, $catatan_admin, $request['kode_transaksi']]);
            
            // Debug: Check if update was successful
            $affected_rows = $stmt_update_trans->rowCount();
            error_log("UPDATE kasir_transactions - Affected rows: $affected_rows for transaction: {$request['kode_transaksi']}");
            
            if ($affected_rows === 0) {
                throw new Exception("Gagal mengupdate status transaksi. Transaksi tidak ditemukan atau sudah diproses.");
            }
            
            // Update status permintaan
            $sql_update_request = "UPDATE konfirmasi_buka_transaksi SET 
                                  status = 'approved',
                                  tanggal_diproses = NOW(),
                                  catatan_admin = ?,
                                  kode_karyawan_admin = ?
                                  WHERE id = ?";
            $stmt_update_req = $pdo->prepare($sql_update_request);
            $stmt_update_req->execute([$catatan_admin, $kode_karyawan_admin, $request_id]);
            
            $pdo->commit();
            
            // Verify the update worked by checking the current status
            $sql_verify = "SELECT status, deposit_status FROM kasir_transactions WHERE kode_transaksi = ?";
            $stmt_verify = $pdo->prepare($sql_verify);
            $stmt_verify->execute([$request['kode_transaksi']]);
            $current_status = $stmt_verify->fetch(PDO::FETCH_ASSOC);
            
            if ($current_status && $current_status['status'] === 'on proses') {
                echo "<script>alert('SUCCESS: Transaksi {$request['kode_transaksi']} berhasil dibuka kembali menjadi On Proses. Status sekarang: {$current_status['status']}'); window.location.href = 'konfirmasi_buka_transaksi.php';</script>";
            } else {
                echo "<script>alert('WARNING: Permintaan disetujui tapi status transaksi belum berubah. Status sekarang: " . ($current_status ? $current_status['status'] : 'Not Found') . "'); window.location.href = 'konfirmasi_buka_transaksi.php';</script>";
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
        }
    } elseif (isset($_POST['reject_request'])) {
        $request_id = $_POST['request_id'];
        $catatan_admin = trim($_POST['catatan_admin'] ?? '');
        
        try {
            $sql_reject = "UPDATE konfirmasi_buka_transaksi SET 
                          status = 'rejected',
                          tanggal_diproses = NOW(),
                          catatan_admin = ?,
                          kode_karyawan_admin = ?
                          WHERE id = ? AND status = 'pending'";
            $stmt_reject = $pdo->prepare($sql_reject);
            $stmt_reject->execute([$catatan_admin, $kode_karyawan_admin, $request_id]);
            
            echo "<script>alert('Permintaan berhasil ditolak.'); window.location.href = 'konfirmasi_buka_transaksi.php';</script>";
        } catch (Exception $e) {
            echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
        }
    }
}

// Fetch pending requests
$sql_pending = "
    SELECT 
        kbt.*,
        u.nama_karyawan as nama_peminta,
        kt.tanggal_transaksi,
        kt.setoran_real,
        kt.status,
        kt.catatan_validasi
    FROM konfirmasi_buka_transaksi kbt
    LEFT JOIN users u ON kbt.kode_karyawan_peminta = u.kode_karyawan
    LEFT JOIN kasir_transactions kt ON kbt.kode_transaksi = kt.kode_transaksi
    WHERE kbt.status = 'pending'
    ORDER BY kbt.tanggal_permintaan DESC
";
$stmt_pending = $pdo->query($sql_pending);
$pending_requests = $stmt_pending->fetchAll(PDO::FETCH_ASSOC);

// Fetch processed requests (last 30 days)
$sql_processed = "
    SELECT 
        kbt.*,
        u.nama_karyawan as nama_peminta,
        ua.nama_karyawan as nama_admin,
        kt.tanggal_transaksi,
        kt.setoran_real,
        kt.status
    FROM konfirmasi_buka_transaksi kbt
    LEFT JOIN users u ON kbt.kode_karyawan_peminta = u.kode_karyawan
    LEFT JOIN users ua ON kbt.kode_karyawan_admin = ua.kode_karyawan
    LEFT JOIN kasir_transactions kt ON kbt.kode_transaksi = kt.kode_transaksi
    WHERE kbt.status IN ('approved', 'rejected')
    AND kbt.tanggal_diproses >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY kbt.tanggal_diproses DESC
";
$stmt_processed = $pdo->query($sql_processed);
$processed_requests = $stmt_processed->fetchAll(PDO::FETCH_ASSOC);

function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

function getStatusBadge($status) {
    switch($status) {
        case 'pending':
            return '<span class="badge badge-warning">Menunggu Konfirmasi</span>';
        case 'approved':
            return '<span class="badge badge-success">Disetujui</span>';
        case 'rejected':
            return '<span class="badge badge-danger">Ditolak</span>';
        default:
            return '<span class="badge badge-secondary">Unknown</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Buka Transaksi - Super Admin</title>
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

        .content-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .content-header {
            background: var(--background-light);
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .table th {
            background: linear-gradient(135deg, var(--primary-color), #0056b3);
            color: white;
            font-weight: 600;
            padding: 12px 16px;
            text-align: left;
            font-size: 13px;
            border: none;
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

        .badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 6px;
            text-transform: uppercase;
        }

        .badge-warning {
            background-color: var(--warning-color);
            color: white;
        }

        .badge-success {
            background-color: var(--success-color);
            color: white;
        }

        .badge-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .badge-secondary {
            background-color: var(--secondary-color);
            color: white;
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

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
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
            max-width: 600px;
            width: 90%;
            max-height: 90%;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
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
            margin-left: 10px;
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

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        .info-item {
            background: var(--background-light);
            padding: 12px;
            border-radius: 8px;
        }

        .info-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 14px;
            color: var(--text-dark);
            font-weight: 500;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            border: 1px solid;
        }

        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
            font-style: italic;
        }

        @media (max-width: 768px) {
            .info-grid {
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
            <p style="color: var(--text-muted); font-size: 12px;">Super Admin</p>
        </div>
    </div>

    <div class="welcome-card">
        <h1><i class="fas fa-unlock-alt"></i> Konfirmasi Buka Transaksi</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Kelola permintaan CS/Kasir untuk membuka kembali transaksi yang telah dikembalikan menjadi status "On Proses".</p>
    </div>

    <!-- Pending Requests -->
    <div class="content-card">
        <div class="content-header">
            <h3><i class="fas fa-clock"></i> Permintaan Menunggu Konfirmasi (<?php echo count($pending_requests); ?>)</h3>
        </div>
        <div class="content-body">
            <?php if (!empty($pending_requests)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Info:</strong> Terdapat <?php echo count($pending_requests); ?> permintaan yang menunggu konfirmasi Anda.
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Kode Transaksi</th>
                                <th>Peminta</th>
                                <th>Cabang</th>
                                <th>Tanggal Permintaan</th>
                                <th>Nilai Transaksi</th>
                                <th>Status Saat Ini</th>
                                <th>Alasan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_requests as $request): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($request['kode_transaksi']); ?></strong></td>
                                <td><?php echo htmlspecialchars($request['nama_peminta'] ?? $request['kode_karyawan_peminta']); ?></td>
                                <td><?php echo htmlspecialchars($request['nama_cabang']); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($request['tanggal_permintaan'])); ?></td>
                                <td><?php echo formatRupiah($request['setoran_real'] ?? 0); ?></td>
                                <td><span class="badge badge-warning"><?php echo htmlspecialchars($request['status']); ?></span></td>
                                <td style="max-width: 200px; word-wrap: break-word;">
                                    <?php echo htmlspecialchars($request['alasan_permintaan']); ?>
                                </td>
                                <td>
                                    <button class="btn btn-success btn-sm" onclick="showApproveModal(<?php echo $request['id']; ?>, '<?php echo htmlspecialchars($request['kode_transaksi']); ?>')">
                                        <i class="fas fa-check"></i> Setuju
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="showRejectModal(<?php echo $request['id']; ?>, '<?php echo htmlspecialchars($request['kode_transaksi']); ?>')">
                                        <i class="fas fa-times"></i> Tolak
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-check-circle" style="font-size: 48px; color: var(--success-color); margin-bottom: 10px;"></i><br>
                    Tidak ada permintaan yang menunggu konfirmasi
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Processed Requests -->
    <div class="content-card">
        <div class="content-header">
            <h3><i class="fas fa-history"></i> Riwayat Permintaan (30 Hari Terakhir)</h3>
        </div>
        <div class="content-body">
            <?php if (!empty($processed_requests)): ?>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Kode Transaksi</th>
                                <th>Peminta</th>
                                <th>Cabang</th>
                                <th>Tanggal Permintaan</th>
                                <th>Tanggal Diproses</th>
                                <th>Status</th>
                                <th>Diproses Oleh</th>
                                <th>Catatan Admin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($processed_requests as $request): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($request['kode_transaksi']); ?></strong></td>
                                <td><?php echo htmlspecialchars($request['nama_peminta'] ?? $request['kode_karyawan_peminta']); ?></td>
                                <td><?php echo htmlspecialchars($request['nama_cabang']); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($request['tanggal_permintaan'])); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($request['tanggal_diproses'])); ?></td>
                                <td><?php echo getStatusBadge($request['status']); ?></td>
                                <td><?php echo htmlspecialchars($request['nama_admin'] ?? $request['kode_karyawan_admin']); ?></td>
                                <td style="max-width: 200px; word-wrap: break-word;">
                                    <?php echo htmlspecialchars($request['catatan_admin'] ?: '-'); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-history" style="font-size: 48px; color: var(--text-muted); margin-bottom: 10px;"></i><br>
                    Belum ada riwayat permintaan dalam 30 hari terakhir
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal" id="approveModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Setujui Permintaan</h5>
                    <button type="button" class="btn-close" onclick="closeModal('approveModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Anda akan menyetujui permintaan untuk membuka transaksi <strong id="approveTransactionCode"></strong> menjadi status "On Proses" kembali.</p>
                    
                    <div class="form-group">
                        <label class="form-label">Catatan Admin (Opsional):</label>
                        <textarea name="catatan_admin" class="form-control" rows="3" placeholder="Berikan catatan jika diperlukan..."></textarea>
                    </div>
                    
                    <input type="hidden" name="request_id" id="approveRequestId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('approveModal')">Batal</button>
                    <button type="submit" name="approve_request" class="btn btn-success">
                        <i class="fas fa-check"></i> Setujui
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal" id="rejectModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Tolak Permintaan</h5>
                    <button type="button" class="btn-close" onclick="closeModal('rejectModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <p>Anda akan menolak permintaan untuk membuka transaksi <strong id="rejectTransactionCode"></strong>.</p>
                    
                    <div class="form-group">
                        <label class="form-label">Alasan Penolakan:</label>
                        <textarea name="catatan_admin" class="form-control" rows="3" placeholder="Berikan alasan penolakan..." required></textarea>
                    </div>
                    
                    <input type="hidden" name="request_id" id="rejectRequestId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('rejectModal')">Batal</button>
                    <button type="submit" name="reject_request" class="btn btn-danger">
                        <i class="fas fa-times"></i> Tolak
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showApproveModal(requestId, transactionCode) {
    document.getElementById('approveRequestId').value = requestId;
    document.getElementById('approveTransactionCode').textContent = transactionCode;
    document.getElementById('approveModal').classList.add('show');
}

function showRejectModal(requestId, transactionCode) {
    document.getElementById('rejectRequestId').value = requestId;
    document.getElementById('rejectTransactionCode').textContent = transactionCode;
    document.getElementById('rejectModal').classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

// Close modal on outside click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('show');
        }
    });
});

// Auto refresh every 30 seconds for new requests
setInterval(function() {
    const pendingCount = <?php echo count($pending_requests); ?>;
    if (pendingCount > 0) {
        // Only refresh if there are pending requests to avoid unnecessary refreshes
        fetch(window.location.href)
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const newDoc = parser.parseFromString(html, 'text/html');
                const newPendingCount = newDoc.querySelectorAll('.content-card:first-of-type .table tbody tr').length;
                
                if (newPendingCount !== pendingCount) {
                    window.location.reload();
                }
            });
    }
}, 30000);
</script>
</body>
</html>