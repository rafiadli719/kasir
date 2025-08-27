<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
header('Location: ../../login_dashboard/login.php');
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Set timezone ke Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

// Ambil nama user dan cabang dari sesi atau database
if (isset($_SESSION['kode_karyawan'])) {
    $kode_karyawan = $_SESSION['kode_karyawan'];
    $nama_karyawan = $_SESSION['nama_karyawan'] ?? 'Unknown User'; // Nama user dari sesi, default to 'Unknown User' if not set

    // Check if 'cabang' is already in the session
    if (isset($_SESSION['cabang'])) {
        $nama_cabang = $_SESSION['cabang'];
    } else {
        // Fetch 'cabang' from the database if it's not in the session
        $sql_cabang = "SELECT nama_cabang FROM kasir_transactions WHERE kode_karyawan = :kode_karyawan LIMIT 1";
        $stmt_cabang = $pdo->prepare($sql_cabang);
        $stmt_cabang->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
        $stmt_cabang->execute();
        $result_cabang = $stmt_cabang->fetch(PDO::FETCH_ASSOC);

        // Check if the query returned a result
        if ($result_cabang && isset($result_cabang['nama_cabang'])) {
            $nama_cabang = $result_cabang['nama_cabang'];
            // Store the cabang name in the session for future use
            $_SESSION['cabang'] = $nama_cabang;
        } else {
            // Default value if 'cabang' is not found in the database
            $nama_cabang = 'Unknown Cabang';
        }
    }
} else {
    die("Kode karyawan tidak ditemukan di session. Silakan login.");
}

// Get transaction code from URL or session
if (isset($_GET['kode_transaksi'])) {
    $kode_transaksi = $_GET['kode_transaksi'];
} else {
    header('Location: index_kasir.php');
    exit;
}

// Cek apakah data penjualan dan servis sudah ada untuk kode transaksi ini
$sql_check = "SELECT dp.id as penjualan_id, ds.id as servis_id, dp.jumlah_penjualan, ds.jumlah_servis,
              COALESCE(dp.tanggal, ds.tanggal) as tanggal, COALESCE(dp.waktu, ds.waktu) as waktu
              FROM data_penjualan dp
              JOIN data_servis ds ON dp.kode_transaksi = ds.kode_transaksi
              WHERE dp.kode_transaksi = :kode_transaksi";
$stmt_check = $pdo->prepare($sql_check);
$stmt_check->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_check->execute();
$data_exist = $stmt_check->fetch(PDO::FETCH_ASSOC);

$mode = $data_exist ? 'edit' : 'insert'; // Jika data sudah ada, mode edit

// Proses input atau update data omset dengan AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['jumlah_penjualan']) && isset($_POST['jumlah_servis'])) {
    $jumlah_penjualan = $_POST['jumlah_penjualan'];
    $jumlah_servis = $_POST['jumlah_servis'];

    // Validasi angka agar hanya bilangan bulat positif
    if (!is_numeric($jumlah_penjualan) || $jumlah_penjualan < 0 || floor($jumlah_penjualan) != $jumlah_penjualan) {
        echo json_encode(['status' => 'error', 'message' => 'Jumlah Penjualan harus berupa bilangan bulat positif!']);
        exit;
    }
    if (!is_numeric($jumlah_servis) || $jumlah_servis < 0 || floor($jumlah_servis) != $jumlah_servis) {
        echo json_encode(['status' => 'error', 'message' => 'Jumlah Servis harus berupa bilangan bulat positif!']);
        exit;
    }

    try {
        // Set waktu dan tanggal secara otomatis
        $tanggal = date('Y-m-d');  // Tanggal saat ini
        $waktu = date('H:i:s');    // Waktu saat ini

        if ($mode == 'insert') {
            // Insert data baru
            $pdo->beginTransaction();

            // Simpan data penjualan
            $sql_penjualan = "INSERT INTO data_penjualan (kode_transaksi, kode_karyawan, jumlah_penjualan, tanggal, waktu)
                              VALUES (:kode_transaksi, :kode_karyawan, :jumlah_penjualan, :tanggal, :waktu)";
            $stmt_penjualan = $pdo->prepare($sql_penjualan);
            $stmt_penjualan->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
            $stmt_penjualan->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
            $stmt_penjualan->bindParam(':jumlah_penjualan', $jumlah_penjualan, PDO::PARAM_INT);
            $stmt_penjualan->bindParam(':tanggal', $tanggal, PDO::PARAM_STR);
            $stmt_penjualan->bindParam(':waktu', $waktu, PDO::PARAM_STR);
            $stmt_penjualan->execute();

            // Simpan data servis
            $sql_servis = "INSERT INTO data_servis (kode_transaksi, kode_karyawan, jumlah_servis, tanggal, waktu)
                           VALUES (:kode_transaksi, :kode_karyawan, :jumlah_servis, :tanggal, :waktu)";
            $stmt_servis = $pdo->prepare($sql_servis);
            $stmt_servis->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
            $stmt_servis->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
            $stmt_servis->bindParam(':jumlah_servis', $jumlah_servis, PDO::PARAM_INT);
            $stmt_servis->bindParam(':tanggal', $tanggal, PDO::PARAM_STR);
            $stmt_servis->bindParam(':waktu', $waktu, PDO::PARAM_STR);
            $stmt_servis->execute();

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Data Penjualan dan Servis berhasil disimpan!', 'tanggal' => $tanggal, 'waktu' => $waktu]);
        } else {
            // Update data yang sudah ada
            $sql_update_penjualan = "UPDATE data_penjualan SET jumlah_penjualan = :jumlah_penjualan, tanggal = :tanggal, waktu = :waktu
                                     WHERE kode_transaksi = :kode_transaksi";
            $stmt_update_penjualan = $pdo->prepare($sql_update_penjualan);
            $stmt_update_penjualan->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
            $stmt_update_penjualan->bindParam(':jumlah_penjualan', $jumlah_penjualan, PDO::PARAM_INT);
            $stmt_update_penjualan->bindParam(':tanggal', $tanggal, PDO::PARAM_STR);
            $stmt_update_penjualan->bindParam(':waktu', $waktu, PDO::PARAM_STR);
            $stmt_update_penjualan->execute();

            $sql_update_servis = "UPDATE data_servis SET jumlah_servis = :jumlah_servis, tanggal = :tanggal, waktu = :waktu
                                  WHERE kode_transaksi = :kode_transaksi";
            $stmt_update_servis = $pdo->prepare($sql_update_servis);
            $stmt_update_servis->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
            $stmt_update_servis->bindParam(':jumlah_servis', $jumlah_servis, PDO::PARAM_INT);
            $stmt_update_servis->bindParam(':tanggal', $tanggal, PDO::PARAM_STR);
            $stmt_update_servis->bindParam(':waktu', $waktu, PDO::PARAM_STR);
            $stmt_update_servis->execute();

            echo json_encode(['status' => 'success', 'message' => 'Data Penjualan dan Servis berhasil diperbarui!', 'tanggal' => $tanggal, 'waktu' => $waktu]);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Ambil nama karyawan dan nama cabang berdasarkan kode_karyawan dari tabel users
$sql_nama_karyawan = "SELECT nama_karyawan, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan";
$stmt_nama_karyawan = $pdo->prepare($sql_nama_karyawan);
$stmt_nama_karyawan->bindParam(':kode_karyawan', $kode_karyawan);
$stmt_nama_karyawan->execute();
$nama_karyawan_data = $stmt_nama_karyawan->fetch(PDO::FETCH_ASSOC);

$nama_karyawan = $nama_karyawan_data['nama_karyawan'] ?? 'Tidak diketahui';
$nama_cabang = $nama_karyawan_data['nama_cabang'] ?? 'Tidak diketahui';
// Gabungkan kode_karyawan dan nama_karyawan
$karyawan_info = $kode_karyawan . ' - ' . ($nama_karyawan ?? 'Tidak diketahui');

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Data Penjualan dan Servis</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
            --secondary-color: #6c757d;
            --warning-color: #ffc107;
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
            z-index: 1000;
        }
        .sidebar a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            white-space: nowrap;
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
        }
        .logout-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            padding: 12px 20px;
            width: 100%;
            text-align: left;
            margin-top: 20px;
            cursor: pointer;
        }
        .logout-btn:hover {
            background: #c82333;
        }
        .main-content {
            margin-left: 200px;
            padding: 30px;
            flex: 1;
            transition: margin-left 0.3s ease;
        }
        .page-header {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
        }
        .page-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 16px;
        }
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 14px;
        }
        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .info-card .label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .info-card .value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-dark);
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            background: var(--background-light);
            border-radius: 12px 12px 0 0;
        }
        .card-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-dark);
            margin: 0;
        }
        .card-body {
            padding: 24px;
        }
        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            border: 1px solid;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-dark);
            font-size: 14px;
        }
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            justify-content: center;
        }
        .btn-primary {
            background: var(--primary-color);
            color: white;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.2);
        }
        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.3);
        }
        .btn-secondary {
            background: var(--secondary-color);
            color: white;
            box-shadow: 0 2px 4px rgba(108, 117, 125, 0.2);
        }
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.3);
        }
        .btn-block {
            width: 100%;
        }
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
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
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
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
            .info-grid {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <a href="index_kasir.php"><i class="fas fa-tachometer-alt"></i> Dashboard Kasir</a>
        <a href="serah_terima_kasir.php"><i class="fas fa-handshake"></i> Serah Terima Kasir</a>
        <a href="setoran_keuangan_cs.php"><i class="fas fa-money-bill"></i> Setoran Keuangan CS</a>
        <button class="logout-btn" onclick="window.location.href='logout.php';"><i class="fas fa-sign-out-alt"></i> Logout</button>
    </div>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-chart-line"></i> Input Data Penjualan dan Servis</h1>
            <div class="breadcrumb">
                <a href="index_kasir.php">Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span>Input Omset</span>
            </div>
        </div>

        <!-- Info Cards -->
        <div class="info-grid">
            <div class="info-card">
                <div class="label">Kode Transaksi</div>
                <div class="value"><?php echo htmlspecialchars($kode_transaksi); ?></div>
            </div>
            <div class="info-card">
                <div class="label">Karyawan</div>
                <div class="value"><?php echo htmlspecialchars($karyawan_info); ?></div>
            </div>
            <div class="info-card">
                <div class="label">Cabang</div>
                <div class="value"><?php echo htmlspecialchars($nama_cabang); ?></div>
            </div>
        </div>

        <!-- Form Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-edit"></i> Form Input Omset</h3>
            </div>
            <div class="card-body">
                <form id="dataForm" method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="jumlah_penjualan" class="form-label">
                                <i class="fas fa-shopping-cart"></i> Jumlah Penjualan
                            </label>
                            <input type="number" class="form-control" id="jumlah_penjualan" name="jumlah_penjualan" min="0" step="1"
                                   value="<?php echo $data_exist ? htmlspecialchars($data_exist['jumlah_penjualan']) : ''; ?>" placeholder="Masukkan jumlah penjualan" required>
                        </div>

                        <div class="form-group">
                            <label for="jumlah_servis" class="form-label">
                                <i class="fas fa-tools"></i> Jumlah Servis
                            </label>
                            <input type="number" class="form-control" id="jumlah_servis" name="jumlah_servis" min="0" step="1"
                                   value="<?php echo $data_exist ? htmlspecialchars($data_exist['jumlah_servis']) : ''; ?>" placeholder="Masukkan jumlah servis" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary btn-block" id="submitBtn">
                            <i class="fas fa-save"></i> <?php echo $mode == 'edit' ? 'Update Data' : 'Simpan Data'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Data Table Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-table"></i> Data Penjualan dan Servis</h3>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-container" style="border: none; box-shadow: none;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Nama User</th>
                                <th>Cabang</th>
                                <th>Jumlah Penjualan</th>
                                <th>Jumlah Servis</th>
                                <th>Tanggal</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($data_exist): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($nama_karyawan); ?></td>
                                    <td><?php echo htmlspecialchars($nama_cabang); ?></td>
                                    <td style="font-weight: 600; color: var(--success-color);"><?php echo number_format($data_exist['jumlah_penjualan'], 0); ?></td>
                                    <td style="font-weight: 600; color: var(--primary-color);"><?php echo number_format($data_exist['jumlah_servis'], 0); ?></td>
                                    <td><?php echo htmlspecialchars($data_exist['tanggal']); ?></td>
                                    <td><?php echo htmlspecialchars($data_exist['waktu']); ?></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <p>Belum ada data untuk transaksi ini.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Back Button -->
        <div style="margin-top: 24px;">
            <button class="btn btn-secondary" onclick="window.location.href='index_kasir.php'">
                <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
            </button>
        </div>
    </div>

    <!-- AJAX untuk submit form tanpa refresh -->
    <script>
    $(document).ready(function() {
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

            const minWidth = 180;
            sidebar.style.width = maxWidth > minWidth ? `${maxWidth + 20}px` : `${minWidth}px`;
            document.querySelector('.main-content').style.marginLeft = sidebar.style.width;
        }

        adjustSidebarWidth();
        window.addEventListener('resize', adjustSidebarWidth);

        $('#dataForm').on('submit', function(e) {
            e.preventDefault();

            let jumlah_penjualan = $('#jumlah_penjualan').val();
            let jumlah_servis = $('#jumlah_servis').val();

            $.ajax({
                url: '',
                type: 'POST',
                data: {
                    jumlah_penjualan: jumlah_penjualan,
                    jumlah_servis: jumlah_servis
                },
                success: function(response) {
                    response = JSON.parse(response);
                    if (response.status === 'success') {
                        $('.alert-success').remove();
                        $('.page-header').after('<div class="alert alert-success"><i class="fas fa-check-circle"></i>' + response.message + '</div>');

                        $('table tbody').html(`
                            <tr>
                                <td><?php echo htmlspecialchars($nama_karyawan); ?></td>
                                <td><?php echo htmlspecialchars($nama_cabang); ?></td>
                                <td style="font-weight: 600; color: var(--success-color);">${new Intl.NumberFormat().format(jumlah_penjualan)}</td>
                                <td style="font-weight: 600; color: var(--primary-color);">${new Intl.NumberFormat().format(jumlah_servis)}</td>
                                <td>${response.tanggal}</td>
                                <td>${response.waktu}</td>
                            </tr>
                        `);

                        $('#submitBtn').html('<i class="fas fa-save"></i> Update Data');
                        $('html, body').animate({scrollTop: 0}, 500);
                    } else {
                        alert(response.message);
                    }
                },
                error: function(xhr, status, error) {
                    alert("Terjadi kesalahan: " + error);
                }
            });
        });
    });
    </script>
</body>
</html>