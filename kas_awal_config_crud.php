<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Jakarta');

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
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
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';

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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'create':
            $kode_cabang = trim($_POST['kode_cabang']);
            $nama_cabang = trim($_POST['nama_cabang']);
            $nominal_minimum = floatval($_POST['nominal_minimum']);
            $status = $_POST['status'];
            
            if (empty($kode_cabang) || empty($nama_cabang)) {
                echo json_encode(['success' => false, 'message' => 'Kode cabang dan nama cabang harus diisi']);
                exit;
            }
            
            if ($nominal_minimum < 100000) {
                echo json_encode(['success' => false, 'message' => 'Nominal minimum harus minimal Rp 100.000']);
                exit;
            }
            
            try {
                $sql = "INSERT INTO kas_awal_config (kode_cabang, nama_cabang, nominal_minimum, status, created_by) 
                        VALUES (:kode_cabang, :nama_cabang, :nominal_minimum, :status, :created_by)";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':kode_cabang', $kode_cabang);
                $stmt->bindParam(':nama_cabang', $nama_cabang);
                $stmt->bindParam(':nominal_minimum', $nominal_minimum);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':created_by', $kode_karyawan);
                
                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Konfigurasi berhasil ditambahkan']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Gagal menambahkan konfigurasi']);
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    echo json_encode(['success' => false, 'message' => 'Kode cabang sudah ada']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                }
            }
            exit;
            
        case 'update':
            $id = intval($_POST['id']);
            $kode_cabang = trim($_POST['kode_cabang']);
            $nama_cabang = trim($_POST['nama_cabang']);
            $nominal_minimum = floatval($_POST['nominal_minimum']);
            $status = $_POST['status'];
            
            if (empty($kode_cabang) || empty($nama_cabang)) {
                echo json_encode(['success' => false, 'message' => 'Kode cabang dan nama cabang harus diisi']);
                exit;
            }
            
            if ($nominal_minimum < 100000) {
                echo json_encode(['success' => false, 'message' => 'Nominal minimum harus minimal Rp 100.000']);
                exit;
            }
            
            try {
                $sql = "UPDATE kas_awal_config SET nama_cabang = :nama_cabang, nominal_minimum = :nominal_minimum, 
                        status = :status, updated_by = :updated_by WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':nama_cabang', $nama_cabang);
                $stmt->bindParam(':nominal_minimum', $nominal_minimum);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':updated_by', $kode_karyawan);
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                
                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Konfigurasi berhasil diperbarui']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Gagal memperbarui konfigurasi']);
                }
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;
            
        case 'delete':
            $id = intval($_POST['id']);
            
            try {
                // Soft delete by setting status to inactive
                $sql = "UPDATE kas_awal_config SET status = 'inactive', updated_by = :updated_by WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':updated_by', $kode_karyawan);
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                
                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Konfigurasi berhasil dihapus']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Gagal menghapus konfigurasi']);
                }
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;
            
        case 'get_detail':
            $id = intval($_POST['id']);
            
            try {
                $sql = "SELECT * FROM kas_awal_config WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($data) {
                    echo json_encode(['success' => true, 'data' => $data]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
                }
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;
    }
}

// Handle search and filter
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Build query with filters
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(kode_cabang LIKE :search OR nama_cabang LIKE :search)";
    $params['search'] = "%$search%";
}

if ($status_filter !== 'all') {
    $where_conditions[] = "status = :status";
    $params['status'] = $status_filter;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count for pagination
$sql_count = "SELECT COUNT(*) FROM kas_awal_config $where_clause";
$stmt_count = $pdo->prepare($sql_count);
foreach ($params as $key => $value) {
    $stmt_count->bindValue(":$key", $value);
}
$stmt_count->execute();
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Get data with pagination
$sql_data = "SELECT * FROM kas_awal_config $where_clause ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
$stmt_data = $pdo->prepare($sql_data);
foreach ($params as $key => $value) {
    $stmt_data->bindValue(":$key", $value);
}
$stmt_data->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt_data->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt_data->execute();
$config_list = $stmt_data->fetchAll(PDO::FETCH_ASSOC);

// Get available branches from users table for dropdown
$sql_branches = "SELECT DISTINCT kode_cabang, nama_cabang FROM users WHERE kode_cabang IS NOT NULL ORDER BY nama_cabang";
$stmt_branches = $pdo->query($sql_branches);
$available_branches = $stmt_branches->fetchAll(PDO::FETCH_ASSOC);

function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function getStatusBadge($status) {
    switch($status) {
        case 'active':
            return '<span class="badge bg-success">Aktif</span>';
        case 'inactive':
            return '<span class="badge bg-danger">Tidak Aktif</span>';
        default:
            return '<span class="badge bg-secondary">Unknown</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Konfigurasi Kas Awal</title>
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { margin-top: 20px; background-color: #f8f9fa; }
        .page-header { background: linear-gradient(135deg, #007bff, #0056b3); color: white; padding: 2rem 0; margin-bottom: 2rem; }
        .card { border: none; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); }
        .card-header { background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; font-weight: 600; }
        .btn-action { margin: 0 2px; }
        .filter-card { background-color: #fff; border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .table-responsive { background-color: #fff; border-radius: 8px; }
        .pagination-wrapper { background-color: #fff; border-radius: 8px; padding: 1rem; }
        .form-floating .form-control:focus { border-color: #007bff; box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25); }
        .modal-header { background: linear-gradient(135deg, #007bff, #0056b3); color: white; }
        .stats-card { background: linear-gradient(135deg, #28a745, #20c997); color: white; }
        .stats-card .card-body { padding: 1.5rem; }
        .breadcrumb-item a { text-decoration: none; }
    </style>
</head>
<body>

    <!-- Page Header -->
    <div class="page-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb text-white-50 mb-2">
                            <li class="breadcrumb-item"><a href="admin_dashboard.php" class="text-white-50">Dashboard</a></li>
                            <li class="breadcrumb-item active text-white" aria-current="page">Konfigurasi</li>
                        </ol>
                    </nav>
                    <h1 class="mb-1"><i class="fas fa-cogs"></i> Manajemen Konfigurasi Kas Awal</h1>
                    <p class="mb-0">Kelola konfigurasi nominal minimum kas awal per cabang</p>
                </div>
                <div class="col-md-4 text-end">
                    <button type="button" class="btn btn-light btn-lg" onclick="showCreateModal()">
                        <i class="fas fa-plus"></i> Tambah Konfigurasi
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- User Info -->
        <div class="alert alert-info">
            <i class="fas fa-user"></i> <strong>Admin:</strong> <?php echo htmlspecialchars($username); ?> | 
            <strong>Role:</strong> <?php echo htmlspecialchars($_SESSION['role']); ?> |
            <strong>Aksi:</strong> Mengelola konfigurasi kas awal untuk seluruh cabang
        </div>

        <!-- Navigation Buttons -->
        <div class="mb-3">
            <a href="index_kasir.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
            </a>
            <button type="button" class="btn btn-info" onclick="window.location.reload()">
                <i class="fas fa-refresh"></i> Refresh Data
            </button>
        </div>
        <!-- Filter and Search -->
        <div class="filter-card">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <div class="form-floating">
                        <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari...">
                        <label for="search"><i class="fas fa-search"></i> Cari kode atau nama cabang</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-floating">
                        <select class="form-select" id="status" name="status">
                            <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>Semua Status</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Aktif</option>
                            <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Tidak Aktif</option>
                        </select>
                        <label for="status"><i class="fas fa-filter"></i> Filter Status</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary h-100 w-100">
                        <i class="fas fa-search"></i> Filter
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="?" class="btn btn-outline-secondary h-100 w-100">
                        <i class="fas fa-refresh"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Data Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-table"></i> Daftar Konfigurasi Kas Awal</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th width="5%">#</th>
                            <th width="15%">Kode Cabang</th>
                            <th width="25%">Nama Cabang</th>
                            <th width="20%">Nominal Minimum</th>
                            <th width="10%">Status</th>
                            <th width="15%">Terakhir Update</th>
                            <th width="10%">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($config_list)): ?>
                            <?php foreach ($config_list as $index => $config): ?>
                                <tr>
                                    <td><?php echo $offset + $index + 1; ?></td>
                                    <td><code><?php echo htmlspecialchars($config['kode_cabang']); ?></code></td>
                                    <td><?php echo htmlspecialchars($config['nama_cabang']); ?></td>
                                    <td><strong><?php echo formatRupiah($config['nominal_minimum']); ?></strong></td>
                                    <td><?php echo getStatusBadge($config['status']); ?></td>
                                    <td>
                                        <small>
                                            <?php echo date('d/m/Y H:i', strtotime($config['updated_at'])); ?><br>
                                            <span class="text-muted">oleh <?php echo htmlspecialchars($config['updated_by'] ?? $config['created_by']); ?></span>
                                        </small>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-action" 
                                                onclick="showEditModal(<?php echo $config['id']; ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-action" 
                                                onclick="confirmDelete(<?php echo $config['id']; ?>, '<?php echo htmlspecialchars($config['nama_cabang']); ?>')" title="Hapus">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3"></i>
                                        <p>Tidak ada data konfigurasi ditemukan</p>
                                        <button type="button" class="btn btn-primary" onclick="showCreateModal()">
                                            <i class="fas fa-plus"></i> Tambah Konfigurasi Pertama
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-wrapper mt-4">
            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    </li>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $status_filter; ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <div class="text-center mt-2">
                <small class="text-muted">
                    Menampilkan <?php echo $offset + 1; ?> - <?php echo min($offset + $limit, $total_records); ?> 
                    dari <?php echo $total_records; ?> data
                </small>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Create/Edit Modal -->
    <div class="modal fade" id="configModal" tabindex="-1" aria-labelledby="configModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="configModalLabel">
                        <i class="fas fa-plus"></i> Tambah Konfigurasi
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="configForm">
                    <div class="modal-body">
                        <input type="hidden" id="configId" name="id">
                        <input type="hidden" id="formAction" name="action" value="create">
                        
                        <div class="mb-3">
                            <label for="kodeCabang" class="form-label">Kode Cabang <span class="text-danger">*</span></label>
                            <select class="form-select" id="kodeCabang" name="kode_cabang" required>
                                <option value="">Pilih Cabang</option>
                                <?php foreach ($available_branches as $branch): ?>
                                    <option value="<?php echo htmlspecialchars($branch['kode_cabang']); ?>">
                                        <?php echo htmlspecialchars($branch['kode_cabang'] . ' - ' . $branch['nama_cabang']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Pilih cabang dari daftar yang tersedia</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="namaCabang" class="form-label">Nama Cabang <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="namaCabang" name="nama_cabang" required readonly>
                            <div class="form-text">Nama cabang akan terisi otomatis</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="nominalMinimum" class="form-label">Nominal Minimum <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">Rp</span>
                                <input type="number" class="form-control" id="nominalMinimum" name="nominal_minimum" 
                                       min="100000" step="50000" value="500000" required>
                            </div>
                            <div class="form-text">Minimum Rp 100.000, kelipatan Rp 50.000</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="active">Aktif</option>
                                <option value="inactive">Tidak Aktif</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Batal
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteModalLabel">
                        <i class="fas fa-exclamation-triangle"></i> Konfirmasi Hapus
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle"></i>
                        <strong>Perhatian:</strong> Data akan dinonaktifkan (soft delete) dan tidak dapat digunakan lagi.
                    </div>
                    <p>Apakah Anda yakin ingin menghapus konfigurasi untuk cabang:</p>
                    <div class="text-center">
                        <h5 class="text-danger" id="deleteCabangName"></h5>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Batal
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="fas fa-trash"></i> Ya, Hapus
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Modal -->
    <div class="modal fade" id="loadingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 mb-0">Memproses...</p>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        let deleteConfigId = null;
        
        // Branch selection auto-fill
        document.getElementById('kodeCabang').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const namaCabang = selectedOption.text.split(' - ')[1] || '';
            document.getElementById('namaCabang').value = namaCabang;
        });

        function showCreateModal() {
            document.getElementById('configModalLabel').innerHTML = '<i class="fas fa-plus"></i> Tambah Konfigurasi';
            document.getElementById('configForm').reset();
            document.getElementById('configId').value = '';
            document.getElementById('formAction').value = 'create';
            document.getElementById('kodeCabang').disabled = false;
            document.getElementById('nominalMinimum').value = '500000';
            const modal = new bootstrap.Modal(document.getElementById('configModal'));
            modal.show();
        }

        function showEditModal(id) {
            showLoading();
            
            const formData = new FormData();
            formData.append('action', 'get_detail');
            formData.append('id', id);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    document.getElementById('configModalLabel').innerHTML = '<i class="fas fa-edit"></i> Edit Konfigurasi';
                    document.getElementById('configId').value = data.data.id;
                    document.getElementById('formAction').value = 'update';
                    document.getElementById('kodeCabang').value = data.data.kode_cabang;
                    document.getElementById('kodeCabang').disabled = true;
                    document.getElementById('namaCabang').value = data.data.nama_cabang;
                    document.getElementById('nominalMinimum').value = data.data.nominal_minimum;
                    document.getElementById('status').value = data.data.status;
                    
                    const modal = new bootstrap.Modal(document.getElementById('configModal'));
                    modal.show();
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showAlert('error', 'Terjadi kesalahan saat memuat data');
            });
        }

        function confirmDelete(id, namaCabang) {
            deleteConfigId = id;
            document.getElementById('deleteCabangName').textContent = namaCabang;
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }

        document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
            if (deleteConfigId) {
                showLoading();
                
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', deleteConfigId);

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    hideLoading();
                    
                    if (data.success) {
                        showAlert('success', data.message);
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showAlert('error', data.message);
                    }
                })
                .catch(error => {
                    hideLoading();
                    console.error('Error:', error);
                    showAlert('error', 'Terjadi kesalahan saat menghapus data');
                });
                
                const modal = bootstrap.Modal.getInstance(document.getElementById('deleteModal'));
                modal.hide();
            }
        });

        document.getElementById('configForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            showLoading();

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                
                if (data.success) {
                    showAlert('success', data.message);
                    const modal = bootstrap.Modal.getInstance(document.getElementById('configModal'));
                    modal.hide();
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showAlert('error', 'Terjadi kesalahan saat menyimpan data');
            });
        });

        function showLoading() {
            const modal = new bootstrap.Modal(document.getElementById('loadingModal'));
            modal.show();
        }

        function hideLoading() {
            const modal = bootstrap.Modal.getInstance(document.getElementById('loadingModal'));
            if (modal) {
                modal.hide();
            }
        }

        function showAlert(type, message) {
            // Remove existing alerts
            const existingAlerts = document.querySelectorAll('.alert-floating');
            existingAlerts.forEach(alert => alert.remove());
            
            const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            const iconClass = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
            
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

        // Format number input
        document.getElementById('nominalMinimum').addEventListener('input', function() {
            let value = this.value.replace(/[^0-9]/g, '');
            if (value) {
                // Round to nearest 50000
                value = Math.round(value / 50000) * 50000;
                this.value = value;
            }
        });
    </script>

</body>
</html>