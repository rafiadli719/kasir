<?php
session_start();
// Cek apakah pengguna memiliki sesi role sebagai super_admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    // Jika bukan super_admin, arahkan ke halaman unauthorized
header('Location: ../../login_dashboard/login.php');
exit();
}

$is_super_admin = true;
$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User';
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    die("Akses ditolak. Hanya untuk admin dan super_admin.");
}

$is_super_admin = $_SESSION['role'] === 'super_admin';
$is_admin = $_SESSION['role'] === 'admin';
// Ubah bagian ini yang sudah ada
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User'; // Tambahkan ini


$host = 'localhost';
$dbname = 'fitmotor_maintance-beta';
$username_db = 'fitmotor_LOGIN';
$password_db = 'Sayalupa12';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username_db, $password_db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        switch ($action) {
            case 'create':
                $nama_cabang = strtoupper(trim($_POST['nama_cabang']));
                $entry_year = $_POST['entry_year'];
                $entry_month = str_pad($_POST['entry_month'], 2, '0', STR_PAD_LEFT);

                // Cek jika nama_cabang sudah ada di database
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM cabang WHERE nama_cabang = :nama_cabang");
                $stmt->execute([':nama_cabang' => $nama_cabang]);
                if ($stmt->fetchColumn() > 0) {
                    echo "Nama cabang sudah ada, gunakan nama lain.";
                    exit;
                }

                // Generate kode_cabang
                $stmt = $pdo->prepare("SELECT COUNT(*) + 1 AS next_id FROM cabang WHERE entry_year = :entry_year AND entry_month = :entry_month");
                $stmt->execute([':entry_year' => $entry_year, ':entry_month' => $entry_month]);
                $next_id = str_pad($stmt->fetchColumn(), 3, '0', STR_PAD_LEFT);
                $kode_cabang = $entry_year . $entry_month . $next_id;

                // Insert data ke database
                $stmt = $pdo->prepare("INSERT INTO cabang (kode_cabang, nama_cabang, entry_year, entry_month) VALUES (:kode_cabang, :nama_cabang, :entry_year, :entry_month)");
                $stmt->bindParam(':kode_cabang', $kode_cabang);
                $stmt->bindParam(':nama_cabang', $nama_cabang);
                $stmt->bindParam(':entry_year', $entry_year);
                $stmt->bindParam(':entry_month', $entry_month);

                echo $stmt->execute() ? "Cabang berhasil ditambahkan dengan kode: $kode_cabang" : "Gagal menambahkan cabang.";
                break;

            case 'read':
                $stmt = $pdo->query("SELECT * FROM cabang ORDER BY kode_cabang DESC");
                $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($branches);
                break;

            case 'update':
                $kode_cabang = $_POST['kode_cabang'];
                $nama_cabang = strtoupper(trim($_POST['nama_cabang']));
                $entry_year = $_POST['entry_year'];
                $entry_month = str_pad($_POST['entry_month'], 2, '0', STR_PAD_LEFT);

                // Update data di database
                $stmt = $pdo->prepare("UPDATE cabang SET nama_cabang = :nama_cabang, entry_year = :entry_year, entry_month = :entry_month WHERE kode_cabang = :kode_cabang");
                $stmt->bindParam(':nama_cabang', $nama_cabang);
                $stmt->bindParam(':entry_year', $entry_year);
                $stmt->bindParam(':entry_month', $entry_month);
                $stmt->bindParam(':kode_cabang', $kode_cabang);

                echo $stmt->execute() ? "Cabang berhasil diupdate." : "Gagal mengupdate cabang.";
                break;

            case 'delete':
                $kode_cabang = $_POST['kode_cabang'];

                // Delete data di database
                $stmt = $pdo->prepare("DELETE FROM cabang WHERE kode_cabang = :kode_cabang");
                $stmt->bindParam(':kode_cabang', $kode_cabang);

                echo $stmt->execute() ? "Cabang berhasil dihapus." : "Gagal menghapus cabang.";
                break;
        }
        exit;
    }
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Cabang - Admin Dashboard</title>
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
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--primary-color);
        }
        .stats-card.success {
            border-left-color: var(--success-color);
        }
        .stats-card.warning {
            border-left-color: var(--warning-color);
        }
        .stats-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .stats-info h4 {
            margin: 0 0 5px 0;
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .stats-info .stats-number {
            font-size: 20px;
            font-weight: bold;
            margin: 0;
            color: var(--text-dark);
        }
        .stats-icon {
            font-size: 28px;
            opacity: 0.7;
            color: var(--primary-color);
        }
        .stats-card.success .stats-icon {
            color: var(--success-color);
        }
        .stats-card.warning .stats-icon {
            color: var(--warning-color);
        }
        .form-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .form-card h3 {
            margin-bottom: 20px;
            color: var(--text-dark);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-label {
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 14px;
        }
        .form-control {
            padding: 12px 16px;
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
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
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
            background-color: #bd2130;
        }
        .btn-warning {
            background-color: var(--warning-color);
            color: #212529;
        }
        .btn-warning:hover {
            background-color: #e0a800;
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
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: none;
            align-items: center;
            gap: 10px;
            border: 1px solid transparent;
        }
        .alert.show {
            display: flex;
        }
        .alert-success {
            background: rgba(40,167,69,0.1);
            color: var(--success-color);
            border-color: rgba(40,167,69,0.2);
        }
        .alert-danger {
            background: rgba(220,53,69,0.1);
            color: var(--danger-color);
            border-color: rgba(220,53,69,0.2);
        }
        .alert-info {
            background: rgba(23,162,184,0.1);
            color: var(--info-color);
            border-color: rgba(23,162,184,0.2);
        }
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
        }
        .table-header {
            background: var(--background-light);
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
        }
        .table-header h3 {
            margin: 0;
            color: var(--text-dark);
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .table-wrapper {
            overflow-x: auto;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th {
            background: var(--background-light);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 14px;
            border-bottom: 1px solid var(--border-color);
        }
        .table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        .table tbody tr:hover {
            background: var(--background-light);
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        .text-required {
            color: var(--danger-color);
        }
        .form-help {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .loading {
            display: none;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
        }
        .loading.show {
            display: flex;
        }
        .loading i {
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @media (max-width: 768px) {
            .sidebar.active {
                transform: translateX(0);
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .info-tags {
                flex-direction: column;
                gap: 10px;
            }
            .action-buttons {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                justify-content: center;
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
            <p style="color: var(--text-muted); font-size: 12px;"><?php echo htmlspecialchars(ucfirst($role)); ?></p>
        </div>
    </div>

    <div class="welcome-card">
        <h1><i class="fas fa-building"></i> Master Cabang</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Kelola data cabang FIT MOTOR dengan sistem kode otomatis</p>
        <div class="info-tags">
            <div class="info-tag"><i class="fas fa-user"></i> User: <?php echo htmlspecialchars($username); ?></div>
            <div class="info-tag"><i class="fas fa-shield-alt"></i> Role: <?php echo htmlspecialchars(ucfirst($role)); ?></div>
            <div class="info-tag"><i class="fas fa-calendar-day"></i> Tanggal: <?php echo date('d M Y'); ?></div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid" id="statsGrid">
        <div class="stats-card">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Total Cabang</h4>
                    <p class="stats-number" id="totalCabang">-</p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-building"></i>
                </div>
            </div>
        </div>
        <div class="stats-card success">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Tahun Ini</h4>
                    <p class="stats-number" id="cabangTahunIni">-</p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-plus-circle"></i>
                </div>
            </div>
        </div>
        <div class="stats-card warning">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Kode Terbaru</h4>
                    <p class="stats-number" id="kodeTerbaru">-</p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-tag"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert -->
    <div class="alert" id="alertBox">
        <i class="fas fa-info-circle"></i>
        <span class="alert-message"></span>
    </div>

    <!-- Form Card -->
    <div class="form-card">
        <h3>
            <i class="fas fa-plus" id="formIcon"></i> 
            <span id="formTitle">Form Pembuatan Cabang</span>
        </h3>
        
        <form id="branchForm">
            <div class="form-grid">
                <div class="form-group">
                    <label for="entry_year" class="form-label">
                        <i class="fas fa-calendar-alt"></i> Tahun Berdiri <span class="text-required">*</span>
                    </label>
                    <select id="entry_year" name="entry_year" class="form-control" required>
                        <option value="">-- Pilih Tahun --</option>
                    </select>
                    <div class="form-help">Tahun cabang mulai beroperasi</div>
                </div>

                <div class="form-group">
                    <label for="entry_month" class="form-label">
                        <i class="fas fa-calendar"></i> Bulan Berdiri <span class="text-required">*</span>
                    </label>
                    <select id="entry_month" name="entry_month" class="form-control" required>
                        <option value="">-- Pilih Bulan --</option>
                    </select>
                    <div class="form-help">Bulan cabang mulai beroperasi</div>
                </div>

                <div class="form-group">
                    <label for="nama_cabang" class="form-label">
                        <i class="fas fa-building"></i> Nama Cabang <span class="text-required">*</span>
                    </label>
                    <input type="text" 
                           id="nama_cabang" 
                           name="nama_cabang" 
                           class="form-control"
                           placeholder="Masukkan nama cabang"
                           oninput="formatNamaCabang()" 
                           required>
                    <div class="form-help">Otomatis menambahkan prefix "FIT MOTOR"</div>
                </div>
            </div>

            <input type="hidden" id="kode_cabang" name="kode_cabang">
            
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="button" id="saveBtn" class="btn btn-success" onclick="saveBranch()">
                    <i class="fas fa-save"></i> <span id="saveBtnText">Simpan</span>
                </button>
                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                    <i class="fas fa-refresh"></i> Reset
                </button>
                <div class="loading" id="loadingIndicator">
                    <i class="fas fa-spinner"></i>
                    <span>Memproses...</span>
                </div>
            </div>
        </form>
    </div>

    <!-- Table Card -->
    <div class="table-container">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Daftar Cabang</h3>
        </div>
        <div class="table-wrapper">
            <table class="table" id="branchesTable">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode Cabang</th>
                        <th>Nama Cabang</th>
                        <th>Tahun Berdiri</th>
                        <th>Bulan Berdiri</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div class="alert alert-info show">
        <i class="fas fa-info-circle"></i>
        <span class="alert-message">Kode cabang akan dibuat otomatis berdasarkan tahun, bulan, dan urutan cabang. Format: YYYYMMXXX</span>
    </div>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
    $(document).ready(function() {
        loadBranches();
        populateYearAndMonth(); 
        updateStats();
    });

    function populateYearAndMonth() {
        const currentYear = new Date().getFullYear();
        $('#entry_year').empty().append(new Option("-- Pilih Tahun --", ""));
        for (let i = 2016; i <= currentYear + 1; i++) {
            $('#entry_year').append(new Option(i, i));
        }
        
        $('#entry_month').empty().append(new Option("-- Pilih Bulan --", ""));
        const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
                           'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        for (let i = 1; i <= 12; i++) {
            const paddedMonth = i.toString().padStart(2, '0');
            $('#entry_month').append(new Option(`${monthNames[i-1]} (${paddedMonth})`, paddedMonth));
        }
    }

    function formatNamaCabang() {
        const inputField = document.getElementById("nama_cabang");
        const value = inputField.value.replace(/^FIT MOTOR\s*/, "").toUpperCase();
        inputField.value = "FIT MOTOR " + value;
    }

    function loadBranches() {
        $.post('cabang.php', { action: 'read' }, function(data) {
            const branches = JSON.parse(data);
            const tbody = $('#branchesTable tbody').empty();
            
            if (branches.length === 0) {
                tbody.append(`
                    <tr>
                        <td colspan="6" class="no-data">
                            <i class="fas fa-building"></i><br>
                            Belum ada cabang yang terdaftar
                        </td>
                    </tr>
                `);
            } else {
                branches.forEach((branch, index) => {
                    tbody.append(`
                        <tr>
                            <td><strong>${index + 1}</strong></td>
                            <td><code>${branch.kode_cabang}</code></td>
                            <td>${branch.nama_cabang}</td>
                            <td>${branch.entry_year}</td>
                            <td>${branch.entry_month}</td>
                            <td class="action-buttons">
                                <button class="btn btn-warning btn-sm" onclick="editBranch('${branch.kode_cabang}', '${branch.nama_cabang}', '${branch.entry_year}', '${branch.entry_month}')" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="deleteBranch('${branch.kode_cabang}', '${branch.nama_cabang}')" title="Hapus">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    `);
                });
            }
            updateStats();
        });
    }

    function updateStats() {
        $.post('cabang.php', { action: 'read' }, function(data) {
            const branches = JSON.parse(data);
            const currentYear = new Date().getFullYear();
            const currentYearBranches = branches.filter(b => parseInt(b.entry_year) === currentYear);
            const latestBranch = branches.length > 0 ? branches[0] : null;

            $('#totalCabang').text(branches.length);
            $('#cabangTahunIni').text(currentYearBranches.length);
            $('#kodeTerbaru').text(latestBranch ? latestBranch.kode_cabang : '-');
        });
    }

    function saveBranch() {
        const action = $('#kode_cabang').val() ? 'update' : 'create';
        
        // Show loading
        $('#saveBtn').prop('disabled', true);
        $('#loadingIndicator').addClass('show');
        hideAlert();
        
        $.post('cabang.php', $('#branchForm').serialize() + '&action=' + action, function(response) {
            $('#saveBtn').prop('disabled', false);
            $('#loadingIndicator').removeClass('show');
            
            if (response.includes('berhasil')) {
                showAlert('success', response);
                resetForm();
                loadBranches();
            } else {
                showAlert('danger', response);
            }
        }).fail(function() {
            $('#saveBtn').prop('disabled', false);
            $('#loadingIndicator').removeClass('show');
            showAlert('danger', 'Terjadi kesalahan saat memproses data');
        });
    }

    function editBranch(kode_cabang, nama_cabang, entry_year, entry_month) {
        $('#kode_cabang').val(kode_cabang);
        $('#nama_cabang').val(nama_cabang);
        $('#entry_year').val(entry_year);
        $('#entry_month').val(entry_month);
        
        // Update UI for edit mode
        $('#formTitle').text('Edit Cabang');
        $('#formIcon').removeClass('fa-plus').addClass('fa-edit');
        $('#saveBtnText').text('Update');
        
        // Scroll to form
        $('html, body').animate({
            scrollTop: $('.form-card').offset().top - 100
        }, 500);
    }

    function deleteBranch(kode_cabang, nama_cabang) {
        if (confirm(`Yakin ingin menghapus cabang "${nama_cabang}" dengan kode ${kode_cabang}?`)) {
            showAlert('info', 'Menghapus cabang...');
            
            $.post('cabang.php', { action: 'delete', kode_cabang: kode_cabang }, function(response) {
                if (response.includes('berhasil')) {
                    showAlert('success', response);
                    loadBranches();
                } else {
                    showAlert('danger', response);
                }
            });
        }
    }

    function resetForm() {
        $('#branchForm')[0].reset();
        $('#kode_cabang').val('');
        
        // Reset UI to create mode
        $('#formTitle').text('Form Pembuatan Cabang');
        $('#formIcon').removeClass('fa-edit').addClass('fa-plus');
        $('#saveBtnText').text('Simpan');
        
        hideAlert();
    }

    function showAlert(type, message) {
        const alertBox = $('#alertBox');
        alertBox.removeClass('alert-success alert-danger alert-info');
        alertBox.addClass('alert-' + type);
        alertBox.find('.alert-message').text(message);
        alertBox.addClass('show');
        
        // Auto hide after 5 seconds
        setTimeout(hideAlert, 5000);
    }

    function hideAlert() {
        $('#alertBox').removeClass('show');
    }

    // Adjust sidebar width based on content
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