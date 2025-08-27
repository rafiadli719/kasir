<?php
session_start();
include 'config.php'; // Koneksi ke database

// Cek apakah user sudah login dan memiliki role 'kasir'
if (!isset($_SESSION['kode_karyawan']) || !in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
header('Location: ../../login_dashboard/login.php');
    exit;
}

$pdo = new PDO("mysql:host=localhost;dbname=fitmotor_maintance-beta", "fitmotor_LOGIN", "Sayalupa12");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Set timezone ke Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

// Mengambil user dan cabang dari sesi
$kode_karyawan = $_SESSION['kode_karyawan'];
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';  
$cabang = $_SESSION['cabang'] ?? 'Unknown Cabang';

// Ambil kode transaksi dari session atau URL
if (isset($_GET['kode_transaksi'])) {
    $kode_transaksi = $_GET['kode_transaksi'];
} else {
    die("Kode transaksi tidak ditemukan.");
}

// Ambil data kas akhir dari database
$sql_kas_akhir = "SELECT total_nilai FROM kas_akhir WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan ORDER BY tanggal DESC, waktu DESC LIMIT 1";
$stmt_kas_akhir = $pdo->prepare($sql_kas_akhir);
$stmt_kas_akhir->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_kas_akhir->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
$stmt_kas_akhir->execute();
$kas_akhir_data = $stmt_kas_akhir->fetch(PDO::FETCH_ASSOC);

if (!$kas_akhir_data) {
    die("Data Kas Akhir tidak ditemukan.");
}

$kas_akhir = $kas_akhir_data['total_nilai'] ?? 0;

// Ambil data nominal keping dari database
$sql_keping = "SELECT k.nominal, IFNULL(dk.jumlah_keping, 0) AS jumlah_keping FROM keping k LEFT JOIN detail_kas_akhir dk ON dk.nominal = k.nominal AND dk.kode_transaksi = :kode_transaksi";
$stmt_keping = $pdo->prepare($sql_keping);
$stmt_keping->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
$stmt_keping->execute();
$keping_data = $stmt_keping->fetchAll(PDO::FETCH_ASSOC);

// Jika form disubmit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $kas_akhir_baru = isset($_POST['kas_akhir']) ? $_POST['kas_akhir'] : 0;
    $tanggal = date('Y-m-d');  // Ambil tanggal secara otomatis
    $waktu = date('H:i:s');    // Ambil waktu secara otomatis

    try {
        $pdo->beginTransaction();

        // Update tabel kas_akhir
        $sql_update_kas_akhir = "UPDATE kas_akhir SET total_nilai = :total_nilai, tanggal = :tanggal, waktu = :waktu WHERE kode_transaksi = :kode_transaksi AND kode_karyawan = :kode_karyawan";
        $stmt_update_kas_akhir = $pdo->prepare($sql_update_kas_akhir);
        $stmt_update_kas_akhir->bindParam(':total_nilai', $kas_akhir_baru, PDO::PARAM_STR);
        $stmt_update_kas_akhir->bindParam(':tanggal', $tanggal, PDO::PARAM_STR);
        $stmt_update_kas_akhir->bindParam(':waktu', $waktu, PDO::PARAM_STR);
        $stmt_update_kas_akhir->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
        $stmt_update_kas_akhir->bindParam(':kode_karyawan', $kode_karyawan, PDO::PARAM_STR);
        $stmt_update_kas_akhir->execute();

        // Update tabel detail_kas_akhir
        foreach ($keping_data as $row) {
            $nominal = $row['nominal'];
            $jumlah_keping = isset($_POST['keping_' . $nominal]) ? $_POST['keping_' . $nominal] : 0;

            // Check if record exists before updating, if not, insert new record
            $sql_detail = "SELECT COUNT(*) FROM detail_kas_akhir WHERE kode_transaksi = :kode_transaksi AND nominal = :nominal";
            $stmt_check_detail = $pdo->prepare($sql_detail);
            $stmt_check_detail->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
            $stmt_check_detail->bindParam(':nominal', $nominal, PDO::PARAM_INT);
            $stmt_check_detail->execute();
            $exists = $stmt_check_detail->fetchColumn();

            if ($exists) {
                $sql_detail = "UPDATE detail_kas_akhir SET jumlah_keping = :jumlah_keping WHERE kode_transaksi = :kode_transaksi AND nominal = :nominal";
            } else {
                $sql_detail = "INSERT INTO detail_kas_akhir (kode_transaksi, nominal, jumlah_keping) VALUES (:kode_transaksi, :nominal, :jumlah_keping)";
            }
            
            $stmt_detail = $pdo->prepare($sql_detail);
            $stmt_detail->bindParam(':kode_transaksi', $kode_transaksi, PDO::PARAM_STR);
            $stmt_detail->bindParam(':nominal', $nominal, PDO::PARAM_INT);
            $stmt_detail->bindParam(':jumlah_keping', $jumlah_keping, PDO::PARAM_INT);
            $stmt_detail->execute();
        }

        $pdo->commit();

        // Return updated data as JSON
        echo json_encode([
            'success' => true,
            'total_kas_akhir' => $kas_akhir_baru,
            'kode_transaksi' => $kode_transaksi,
            'tanggal' => $tanggal,
            'waktu' => $waktu,
        ]);
        exit; // Stop further execution
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit; // Stop further execution
    }
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
    <title>Edit Kas Akhir</title>
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
            padding: 0;
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
        .btn-success {
            background: var(--success-color);
            color: white;
            box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
        }
        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
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
            <h1><i class="fas fa-cash-register"></i> Edit Kas Akhir</h1>
            <div class="breadcrumb">
                <a href="index_kasir.php">Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span>Edit Kas Akhir</span>
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

        <form id="editKasForm" method="POST" action="">
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
                                    <th><i class="fas fa-money-bill"></i> NOMINAL</th>
                                    <th>×</th>
                                    <th><i class="fas fa-coins"></i> KEPING</th>
                                    <th>=</th>
                                    <th><i class="fas fa-calculator"></i> TOTAL NILAI</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($keping_data as $row): 
                                    $nominal = $row['nominal'];
                                    $jumlah_keping = $row['jumlah_keping'] ?? 0;
                                ?>
                                    <tr>
                                        <td style="font-weight: 600;">Rp <?php echo number_format($nominal, 0, ',', '.'); ?></td>
                                        <td>×</td>
                                        <td>
                                            <input type="number" 
                                                   name="keping_<?php echo $nominal; ?>" 
                                                   id="keping_<?php echo $nominal; ?>" 
                                                   class="form-control" 
                                                   value="<?php echo $jumlah_keping; ?>" 
                                                   oninput="hitungTotal('<?php echo $nominal; ?>')" 
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

            <!-- Total Kas Akhir -->
            <div class="total-section">
                <div class="form-group">
                    <label for="total_nilai" class="form-label">
                        <i class="fas fa-wallet"></i> Total Kas Akhir
                    </label>
                    <input type="text" 
                           id="kas_akhir_display" 
                           class="form-control" 
                           value="Rp <?php echo number_format($kas_akhir, 0, ',', '.'); ?>" 
                           readonly>
                    <input type="hidden" 
                           id="kas_akhir" 
                           name="kas_akhir" 
                           value="<?php echo $kas_akhir; ?>">
                </div>
            </div>

            <!-- Submit Button -->
            <div style="display: flex; gap: 16px; margin-bottom: 24px;">
                <button type="submit" class="btn btn-success btn-block">
                    <i class="fas fa-save"></i> Perbarui Kas Akhir
                </button>
            </div>
        </form>

        <!-- Data Kas Akhir Terbaru Card -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Data Kas Akhir Terbaru</h3>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-container" style="border: none; box-shadow: none;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Kode Transaksi</th>
                                <th>Total Nilai</th>
                                <th>Tanggal</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody id="data-kas-terbaru">
                            <tr>
                                <td style="font-weight: 600;"><?php echo htmlspecialchars($kode_transaksi); ?></td>
                                <td style="font-weight: 600; color: var(--primary-color);">Rp <?php echo number_format($kas_akhir, 0, ',', '.'); ?></td>
                                <td><?php echo date('Y-m-d'); ?></td>
                                <td><?php echo date('H:i:s'); ?></td>
                            </tr>
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

        // Fungsi untuk menghitung total nilai per nominal
        function hitungTotal(nominal) {
            // Ambil jumlah keping dari input
            var keping = document.getElementById('keping_' + nominal).value || 0;

            // Hitung total nilai berdasarkan nominal dan jumlah keping
            var totalNilai = nominal * keping;

            // Format total nilai dengan format "Rp"
            var totalFormatted = "Rp " + totalNilai.toLocaleString('id-ID', { minimumFractionDigits: 0 });
            document.getElementById('total_' + nominal).value = totalFormatted;

            // Update total kas akhir setelah mengubah salah satu nilai keping
            hitungKasAkhir();
        }

        // Fungsi untuk menghitung total kas akhir
        function hitungKasAkhir() {
            var totalKasAkhir = 0;

            <?php foreach ($keping_data as $row): ?>
                // Ambil nominal dan keping untuk setiap baris
                var nominal = <?php echo $row['nominal']; ?>;
                var keping = document.getElementById('keping_' + nominal).value || 0;
                
                // Jumlahkan ke total kas akhir
                totalKasAkhir += nominal * keping;
            <?php endforeach; ?>

            // Format total kas akhir dan tampilkan
            var totalFormatted = "Rp " + totalKasAkhir.toLocaleString('id-ID', { minimumFractionDigits: 0 });
            document.getElementById('kas_akhir_display').value = totalFormatted;
            document.getElementById('kas_akhir').value = totalKasAkhir;
        }

        // Handle form submission with AJAX
        document.getElementById('editKasForm').addEventListener('submit', function (e) {
            e.preventDefault(); // Prevent default form submission

            var formData = new FormData(this);

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show a success notification popup
                    alert('Kas Akhir berhasil diperbarui.');

                    // Update total kas akhir display
                    document.getElementById('kas_akhir_display').value = "Rp " + data.total_kas_akhir.toLocaleString('id-ID', { minimumFractionDigits: 0 });
                    document.getElementById('kas_akhir').value = data.total_kas_akhir;

                    // Update data kas akhir terbaru
                    document.getElementById('data-kas-terbaru').innerHTML = `
                        <tr>
                            <td style="font-weight: 600;">${data.kode_transaksi}</td>
                            <td style="font-weight: 600; color: var(--primary-color);">Rp ${data.total_kas_akhir.toLocaleString('id-ID', { minimumFractionDigits: 0 })}</td>
                            <td>${data.tanggal}</td>
                            <td>${data.waktu}</td>
                        </tr>
                    `;
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat memperbarui data.');
            });
        });

        // Initialize calculation on page load
        document.addEventListener('DOMContentLoaded', function() {
            hitungKasAkhir();
        });
    </script>
</body>
</html>