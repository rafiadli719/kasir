<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Pastikan pengguna sudah login dan memiliki role kasir
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
header('Location: ../../login_dashboard/login.php');
    exit;
}

// Koneksi ke database
$dsn = "mysql:host=localhost;dbname=fitmotor_maintance-beta";
try {
    $pdo = new PDO($dsn, 'fitmotor_LOGIN', 'Sayalupa12');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Ambil kode transaksi dari URL atau sesi
$kode_transaksi = isset($_GET['kode_transaksi']) ? $_GET['kode_transaksi'] : $_SESSION['kode_transaksi'];

// Ambil kode_karyawan dan nama_cabang dari kasir_transactions
$sql_user_cabang = "SELECT kode_karyawan, nama_cabang FROM kasir_transactions WHERE kode_transaksi = :kode_transaksi";
$stmt_user_cabang = $pdo->prepare($sql_user_cabang);
$stmt_user_cabang->bindParam(':kode_transaksi', $kode_transaksi);
$stmt_user_cabang->execute();
$user_cabang_data = $stmt_user_cabang->fetch(PDO::FETCH_ASSOC);

$kode_karyawan = $user_cabang_data['kode_karyawan'] ?? 'Tidak diketahui';
$cabang = $user_cabang_data['nama_cabang'] ?? 'Tidak diketahui';

// Ambil nama karyawan dan nama cabang berdasarkan kode_karyawan dari tabel users
$sql_nama_karyawan = "SELECT nama_karyawan, nama_cabang FROM users WHERE kode_karyawan = :kode_karyawan";
$stmt_nama_karyawan = $pdo->prepare($sql_nama_karyawan);
$stmt_nama_karyawan->bindParam(':kode_karyawan', $kode_karyawan);
$stmt_nama_karyawan->execute();
$nama_karyawan_data = $stmt_nama_karyawan->fetch(PDO::FETCH_ASSOC);

$nama_karyawan = $nama_karyawan_data['nama_karyawan'] ?? 'Tidak diketahui';
$nama_cabang = $nama_karyawan_data['nama_cabang'] ?? 'Tidak diketahui';


// Ambil detail kas awal dari database
$sql_kas_awal = "SELECT total_nilai, tanggal, waktu FROM kas_awal WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan";
$stmt_kas_awal = $pdo->prepare($sql_kas_awal);
$stmt_kas_awal->bindParam(':kode_transaksi', $kode_transaksi);
$stmt_kas_awal->bindParam(':kode_karyawan', $_SESSION['kode_karyawan']);
$stmt_kas_awal->execute();
$kas_awal_data = $stmt_kas_awal->fetch(PDO::FETCH_ASSOC);

if (!$kas_awal_data) {
    die("Data kas awal tidak ditemukan.");
}

$kas_awal = $kas_awal_data['total_nilai'];
$tanggal = $kas_awal_data['tanggal'];
$waktu = $kas_awal_data['waktu'];

// Ambil detail keping untuk kas awal
$sql_detail_kas_awal = "SELECT nominal, jumlah_keping FROM detail_kas_awal WHERE kode_transaksi = :kode_transaksi";
$stmt_detail_kas_awal = $pdo->prepare($sql_detail_kas_awal);
$stmt_detail_kas_awal->bindParam(':kode_transaksi', $kode_transaksi);
$stmt_detail_kas_awal->execute();
$detail_keping = $stmt_detail_kas_awal->fetchAll(PDO::FETCH_ASSOC);

// Simpan keping sebelumnya ke dalam array
$keping_previous = [];
foreach ($detail_keping as $detail) {
    $keping_previous[$detail['nominal']] = $detail['jumlah_keping'];
}

// Ambil nominal keping yang tersedia
$sql_keping = "SELECT nominal FROM keping";
$stmt_keping = $pdo->query($sql_keping);
$keping_data = $stmt_keping->fetchAll(PDO::FETCH_ASSOC);

// Jika form disubmit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $kas_awal_baru = $_POST['kas_awal'];

    // Validasi keping
    foreach ($_POST['keping'] as $nominal => $jumlah_keping) {
        if (!is_numeric($jumlah_keping) || $jumlah_keping < 0 || floor($jumlah_keping) != $jumlah_keping) {
            echo "<script>alert('Jumlah keping harus berupa bilangan bulat positif!');</script>";
            exit;
        }
    }

    // Update kas_awal di database
    $sql_update_kas_awal = "UPDATE kas_awal SET total_nilai = :total_nilai WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan";
    $stmt_update_kas_awal = $pdo->prepare($sql_update_kas_awal);
    $stmt_update_kas_awal->bindParam(':total_nilai', $kas_awal_baru);
    $stmt_update_kas_awal->bindParam(':kode_transaksi', $kode_transaksi);
    $stmt_update_kas_awal->bindParam(':kode_karyawan', $_SESSION['kode_karyawan']);
    $stmt_update_kas_awal->execute();

    // Update detail keping di detail_kas_awal
    foreach ($_POST['keping'] as $nominal => $jumlah_keping) {
        if ($jumlah_keping >= 0) {
            $sql_check = "SELECT COUNT(*) FROM detail_kas_awal WHERE kode_transaksi = :kode_transaksi AND nominal = :nominal";
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->bindParam(':kode_transaksi', $kode_transaksi);
            $stmt_check->bindParam(':nominal', $nominal);
            $stmt_check->execute();
            $exists = $stmt_check->fetchColumn();

            if ($exists > 0) {
                $sql_update_detail_kas_awal = "UPDATE detail_kas_awal SET jumlah_keping = :jumlah_keping WHERE kode_transaksi = :kode_transaksi AND nominal = :nominal";
                $stmt_detail = $pdo->prepare($sql_update_detail_kas_awal);
            } else {
                $sql_insert_detail_kas_awal = "INSERT INTO detail_kas_awal (kode_transaksi, nominal, jumlah_keping) VALUES (:kode_transaksi, :nominal, :jumlah_keping)";
                $stmt_detail = $pdo->prepare($sql_insert_detail_kas_awal);
            }

            $stmt_detail->bindParam(':kode_transaksi', $kode_transaksi);
            $stmt_detail->bindParam(':nominal', $nominal);
            $stmt_detail->bindParam(':jumlah_keping', $jumlah_keping);
            $stmt_detail->execute();
        }
    }

    echo "<script>alert('Kas Awal berhasil diperbarui!');</script>";
}

// Ambil semua transaksi kas awal berdasarkan kode transaksi
$sql_view_kas_awal = "SELECT kode_transaksi, total_nilai, tanggal, waktu FROM kas_awal WHERE kode_transaksi = :kode_transaksi";
$stmt_view_kas_awal = $pdo->prepare($sql_view_kas_awal);
$stmt_view_kas_awal->bindParam(':kode_transaksi', $kode_transaksi);
$stmt_view_kas_awal->execute();
$view_kas_awal = $stmt_view_kas_awal->fetchAll(PDO::FETCH_ASSOC);

// Gabungkan kode_karyawan dan nama_karyawan
$karyawan_info = $kode_karyawan . ' - ' . ($nama_karyawan ?? 'Tidak diketahui');
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Kas Awal</title>
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
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
            font-size: 14px;
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
            padding: 0;
        }
        .form-group {
            margin-bottom: 24px;
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
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }
        .form-control[readonly] {
            background: var(--background-light);
            color: var(--text-muted);
        }
        .table-container {
            border-radius: 0 0 12px 12px;
            overflow: hidden;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th {
            background: var(--background-light);
            padding: 16px;
            text-align: center;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 14px;
            border-bottom: 1px solid var(--border-color);
        }
        .table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
            text-align: center;
        }
        .table tbody tr:hover {
            background: var(--background-light);
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
        .total-section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            border: 2px solid var(--primary-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .total-section .form-control {
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            color: var(--primary-color);
            background: var(--background-light);
        }
        .total-section .form-label {
            text-align: center;
            font-size: 16px;
            margin-bottom: 12px;
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
            .table {
                font-size: 12px;
            }
            .table th,
            .table td {
                padding: 8px;
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
            <h1><i class="fas fa-edit"></i> Edit Kas Awal</h1>
            <div class="breadcrumb">
                <a href="index_kasir.php">Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span>Edit Kas Awal</span>
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
            <div class="info-card">
                <div class="label">Tanggal Transaksi</div>
                <div class="value"><?php echo htmlspecialchars($tanggal); ?></div>
            </div>
        </div>

        <form method="POST">
            <!-- Calculation Table Card -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-calculator"></i> Perhitungan Keping</h3>
                </div>
                <div class="card-body">
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-money-bill"></i> Nominal</th>
                                    <th>×</th>
                                    <th><i class="fas fa-coins"></i> Keping</th>
                                    <th>=</th>
                                    <th><i class="fas fa-calculator"></i> Total Nilai</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($keping_data as $row): 
                                    $nominal = $row['nominal'];
                                    $jumlah_keping = isset($keping_previous[$nominal]) ? $keping_previous[$nominal] : 0;
                                ?>
                                <tr>
                                    <td style="font-weight: 600;">Rp <?php echo number_format($nominal, 0, ',', '.'); ?></td>
                                    <td>×</td>
                                    <td>
                                        <input type="number" 
                                               name="keping[<?php echo $nominal; ?>]" 
                                               class="form-control" 
                                               value="<?php echo $jumlah_keping; ?>" 
                                               oninput="hitungTotal(<?php echo $nominal; ?>)"
                                               min="0"
                                               step="1">
                                    </td>
                                    <td>=</td>
                                    <td>
                                        <input type="text" 
                                               id="total_<?php echo $nominal; ?>" 
                                               class="form-control" 
                                               value="Rp <?php echo number_format($nominal * $jumlah_keping, 0, ',', '.'); ?>" 
                                               readonly>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Total Kas Awal -->
            <div class="total-section">
                <div class="form-group">
                    <label for="kas_awal_display" class="form-label">
                        <i class="fas fa-wallet"></i> Total Kas Awal
                    </label>
                    <input type="text" 
                           id="kas_awal_display" 
                           class="form-control" 
                           value="Rp <?php echo number_format($kas_awal, 0, ',', '.'); ?>" 
                           readonly>
                    <input type="hidden" 
                           id="kas_awal" 
                           name="kas_awal" 
                           value="<?php echo $kas_awal; ?>">
                </div>
            </div>

            <!-- Submit Button -->
            <div style="display: flex; gap: 16px; margin-bottom: 24px;">
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> Update Kas Awal
                </button>
            </div>
        </form>

        <!-- Riwayat Transaksi Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Riwayat Transaksi Kas Awal</h3>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-container" style="border: none; box-shadow: none;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Kode Transaksi</th>
                                <th>Total Kas Awal</th>
                                <th>Tanggal</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($view_kas_awal as $kas_awal_row): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo $kas_awal_row['kode_transaksi']; ?></td>
                                    <td style="font-weight: 600; color: var(--success-color);">Rp <?php echo number_format($kas_awal_row['total_nilai'], 0, ',', '.'); ?></td>
                                    <td><?php echo $kas_awal_row['tanggal']; ?></td>
                                    <td><?php echo $kas_awal_row['waktu']; ?></td>
                                </tr>
                            <?php endforeach; ?>
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

    <script>
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

        // Run on page load and window resize
        window.addEventListener('load', adjustSidebarWidth);
        window.addEventListener('resize', adjustSidebarWidth);

        function hitungTotal(nominal) {
            var keping = document.querySelector('input[name="keping[' + nominal + ']"]').value || 0;
            var totalNilai = nominal * keping;
            var totalFormatted = "Rp " + totalNilai.toLocaleString('id-ID', { minimumFractionDigits: 0 });
            document.getElementById('total_' + nominal).value = totalFormatted;

            // Update total kas awal
            hitungKasAwal();
        }

        function hitungKasAwal() {
            var totalKasAwal = 0;

            <?php foreach ($keping_data as $row): 
                $nominal = $row['nominal']; ?>
                var keping = document.querySelector('input[name="keping[<?php echo $nominal; ?>]"]').value || 0;
                totalKasAwal += <?php echo $nominal; ?> * keping;
            <?php endforeach; ?>

            var totalFormatted = "Rp " + totalKasAwal.toLocaleString('id-ID', { minimumFractionDigits: 0 });
            document.getElementById('kas_awal_display').value = totalFormatted;
            document.getElementById('kas_awal').value = totalKasAwal;
        }

        // Initialize calculation on page load
        document.addEventListener('DOMContentLoaded', function() {
            hitungKasAwal();
        });
    </script>
</body>
</html>