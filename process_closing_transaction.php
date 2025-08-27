<?php
/**
 * Process Closing Transaction Handler
 * Menangani proses transaksi "DARI CLOSING" dengan integrasi lengkap
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once 'utils.php'; // Shared utility functions

date_default_timezone_set('Asia/Jakarta');

// Cek session dan role
if (!isset($_SESSION['kode_karyawan']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    header('Location: ../../login_dashboard/login.php');
    exit;
}

if (!in_array($_SESSION['role'], ['kasir', 'admin', 'super_admin'])) {
    http_response_code(403);
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }
    header('Location: ../../login_dashboard/login.php');
    exit;
}

/**
 * Function untuk memproses transaksi closing
 */
function processClosingTransaction($pdo, $data) {
    try {
        $pdo->beginTransaction();

        // Validasi data input
        if (empty($data['nomor_transaksi_closing']) || empty($data['nama_cabang']) || empty($data['tanggal'])) {
            throw new Exception("Data transaksi closing tidak lengkap");
        }

        // Validate jumlah if provided
        if (isset($data['jumlah']) && (!is_numeric($data['jumlah']) || $data['jumlah'] <= 0)) {
            throw new Exception("Jumlah tidak valid");
        }

        // Validate keterangan_transaksi if provided
        if (isset($data['keterangan_transaksi']) && empty(trim($data['keterangan_transaksi']))) {
            throw new Exception("Keterangan transaksi tidak boleh kosong");
        }

        // Tentukan jenis closing otomatis
        $jenis_closing = determineClosingType($pdo, $data['nomor_transaksi_closing'], $data['nama_cabang']);

        // Update kasir_transactions (only update deposit_status, assuming status is already 'end proses')
        $sql_update_kasir = "UPDATE kasir_transactions 
                            SET deposit_status = 'Belum Disetor'
                            WHERE kode_transaksi = ? AND status = 'end proses'";
        
        $stmt_update = $pdo->prepare($sql_update_kasir);
        $stmt_update->execute([$data['nomor_transaksi_closing']]);

        if ($stmt_update->rowCount() === 0) {
            throw new Exception("Transaksi closing tidak ditemukan atau bukan status 'end proses'");
        }

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Transaksi closing berhasil diproses',
            'jenis_closing' => $jenis_closing
        ];

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Database error in processClosingTransaction: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Database error occurred'
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error in processClosingTransaction: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
}

/**
 * Get available closing transactions for dropdown
 */
function getAvailableClosingTransactions($pdo, $nama_cabang) {
    try {
        $sql = "SELECT kode_transaksi, tanggal_transaksi, setoran_real, kode_karyawan 
                FROM kasir_transactions 
                WHERE nama_cabang = ? 
                AND status = 'end proses' 
                AND (deposit_status IS NULL OR deposit_status = '' OR deposit_status = 'Belum Disetor')
                AND kode_transaksi NOT IN (
                    SELECT DISTINCT nomor_transaksi_closing 
                    FROM pemasukan_kasir 
                    WHERE nomor_transaksi_closing IS NOT NULL
                )
                ORDER BY tanggal_transaksi DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nama_cabang]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Database error in getAvailableClosingTransactions: " . $e->getMessage());
        return [];
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'determine_closing_type':
                $kode_transaksi = trim($_POST['kode_transaksi'] ?? '');
                $nama_cabang = trim($_POST['nama_cabang'] ?? $_SESSION['nama_cabang'] ?? '');

                if (empty($kode_transaksi) || empty($nama_cabang)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Kode transaksi atau nama cabang tidak boleh kosong']);
                    exit;
                }

                $jenis_closing = determineClosingType($pdo, $kode_transaksi, $nama_cabang);
                echo json_encode([
                    'success' => true,
                    'jenis_closing' => $jenis_closing,
                    'message' => 'Jenis closing berhasil ditentukan'
                ]);
                break;

            case 'process_closing':
                $data = [
                    'nomor_transaksi_closing' => trim($_POST['nomor_transaksi_closing'] ?? ''),
                    'jumlah' => $_POST['jumlah'] ?? 0,
                    'keterangan_transaksi' => trim($_POST['keterangan_transaksi'] ?? ''),
                    'nama_cabang' => trim($_SESSION['nama_cabang'] ?? ''),
                    'tanggal' => date('Y-m-d')
                ];

                $result = processClosingTransaction($pdo, $data);
                echo json_encode($result);
                break;

            case 'get_closing_transactions':
                $nama_cabang = trim($_POST['nama_cabang'] ?? $_SESSION['nama_cabang'] ?? '');
                if (empty($nama_cabang)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Nama cabang tidak boleh kosong']);
                    exit;
                }
                $transactions = getAvailableClosingTransactions($pdo, $nama_cabang);
                echo json_encode([
                    'success' => true,
                    'data' => $transactions
                ]);
                break;

            default:
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Action tidak dikenali: ' . htmlspecialchars($action)
                ]);
                break;
        }
    } catch (PDOException $e) {
        error_log("Database error in AJAX handler: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error occurred']);
    } catch (Exception $e) {
        error_log("Error in AJAX handler: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    }

    exit;
}
?>