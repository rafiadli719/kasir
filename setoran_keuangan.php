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
$username = $_SESSION['nama_karyawan'] ?? 'Unknown User';
$role = $_SESSION['role'] ?? 'User';

$kode_karyawan = $_SESSION['kode_karyawan'];

// Debug: Log all POST requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("POST REQUEST received to setoran_keuangan.php");
    error_log("POST keys: " . print_r(array_keys($_POST), true));
}

// Check if validation columns exist in kasir_transactions table
$validation_columns_exist = false;
$selisih_fisik_is_generated = false;

try {
    // Check if columns exist
    $stmt = $pdo->query("SHOW COLUMNS FROM kasir_transactions LIKE 'jumlah_diterima_fisik'");
    $validation_columns_exist = $stmt->rowCount() > 0;
    
    // Check if selisih_fisik is a generated column
    if ($validation_columns_exist) {
        $stmt = $pdo->query("SHOW COLUMNS FROM kasir_transactions LIKE 'selisih_fisik'");
        $column_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($column_info && strpos(strtoupper($column_info['Extra']), 'GENERATED') !== false) {
            $selisih_fisik_is_generated = true;
        }
    }
} catch (Exception $e) {
    // Column doesn't exist, that's ok
    error_log("Column check error: " . $e->getMessage());
}

// Helper function to detect closing transactions
function isClosingTransaction($kode_transaksi) {
    return strpos($kode_transaksi, 'CLOSING') !== false || strpos($kode_transaksi, 'CLO') !== false;
}

// PERBAIKAN: Enhanced function to get closing transaction details dengan kalkulasi gabungan yang benar
function getClosingTransactionDetails($pdo, $kode_setoran) {
    $sql = "SELECT 
                kt.*,
                CASE 
                    WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' THEN 'DARI CLOSING'
                    WHEN EXISTS (
                        SELECT 1 FROM pemasukan_kasir pk 
                        WHERE pk.nomor_transaksi_closing = kt.kode_transaksi
                    ) THEN 'DARI CLOSING'
                    ELSE 'TRANSAKSI BIASA'
                END as jenis_transaksi,
                sk.nama_cabang, 
                sk.tanggal_setoran, 
                sk.nama_pengantar,
                COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan,
                -- TAMBAHAN: Informasi pemasukan terkait untuk closing
                pk.jumlah as jumlah_pemasukan_closing,
                pk.keterangan_transaksi as keterangan_closing
            FROM kasir_transactions kt
            LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
            LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
            LEFT JOIN pemasukan_kasir pk ON pk.nomor_transaksi_closing = kt.kode_transaksi
            WHERE kt.kode_setoran = ?
            ORDER BY 
                CASE 
                    WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' THEN 0
                    WHEN EXISTS (
                        SELECT 1 FROM pemasukan_kasir pk2 
                        WHERE pk2.nomor_transaksi_closing = kt.kode_transaksi
                    ) THEN 0
                    ELSE 1
                END,
                kt.tanggal_transaksi ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$kode_setoran]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// PERBAIKAN: Enhanced function to get aggregated closing info dengan kalkulasi gabungan
function getClosingAggregatedInfo($pdo, $kode_setoran) {
    $transactions = getClosingTransactionDetails($pdo, $kode_setoran);
    $closingInfo = [];
    
    // Group by jenis_transaksi and cabang
    foreach ($transactions as $trans) {
        $key = $trans['nama_cabang'] . '_' . $trans['jenis_transaksi'];
        
        if (!isset($closingInfo[$key])) {
            $closingInfo[$key] = [
                'cabang' => $trans['nama_cabang'],
                'jenis' => $trans['jenis_transaksi'],
                'transactions' => [],
                'total_sistem' => 0,
                'total_diterima' => 0,
                'total_selisih' => 0,
                'count' => 0,
                // TAMBAHAN: Informasi closing gabungan
                'total_closing_original' => 0,
                'total_closing_borrowed' => 0,
                'total_closing_lent' => 0
            ];
        }
        
        $closingInfo[$key]['transactions'][] = $trans;
        
        // PERBAIKAN: Kalkulasi gabungan untuk closing
        if ($trans['jenis_transaksi'] == 'DARI CLOSING') {
            // Untuk transaksi closing, hitung gabungan
            if (!empty($trans['jumlah_pemasukan_closing'])) {
                // Ini adalah transaksi yang dipinjam/meminjam
                $closingInfo[$key]['total_closing_borrowed'] += abs($trans['jumlah_pemasukan_closing']);
                $closingInfo[$key]['total_sistem'] += $trans['setoran_real'] - abs($trans['jumlah_pemasukan_closing']);
            } else {
                // Ini adalah transaksi closing asli
                $closingInfo[$key]['total_closing_original'] += $trans['setoran_real'];
                $closingInfo[$key]['total_sistem'] += $trans['setoran_real'];
            }
        } else {
            $closingInfo[$key]['total_sistem'] += $trans['setoran_real'];
        }
        
        if (isset($trans['jumlah_diterima_fisik']) && $trans['jumlah_diterima_fisik'] !== null) {
            $closingInfo[$key]['total_diterima'] += $trans['jumlah_diterima_fisik'];
            if (isset($trans['selisih_fisik'])) {
                $closingInfo[$key]['total_selisih'] += $trans['selisih_fisik'];
            } else {
                $closingInfo[$key]['total_selisih'] += ($trans['jumlah_diterima_fisik'] - $trans['setoran_real']);
            }
        } else {
            $closingInfo[$key]['total_diterima'] += $trans['setoran_real'];
        }
        
        $closingInfo[$key]['count']++;
    }
    
    return $closingInfo;
}

// Handle penerimaan setoran
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['terima_setoran'])) {
    $setoran_ids = $_POST['setoran_ids'] ?? [];
    
    if (empty($setoran_ids)) {
        $error = "Pilih setidaknya satu setoran untuk diterima.";
    } else {
        $pdo->beginTransaction();
        try {
            $success_count = 0;
            $received_setorans = []; // Untuk menyimpan data setoran yang diterima
            
            foreach ($setoran_ids as $setoran_id) {
                // Get setoran data before update
                $sql_get_detail = "SELECT * FROM setoran_keuangan WHERE id = ? AND status = 'Sedang Dibawa Kurir'";
                $stmt_get_detail = $pdo->prepare($sql_get_detail);
                $stmt_get_detail->execute([$setoran_id]);
                $setoran_detail = $stmt_get_detail->fetch(PDO::FETCH_ASSOC);
                
                if ($setoran_detail) {
                    $sql_update = "UPDATE setoran_keuangan SET 
                                  status = 'Diterima Staff Keuangan', 
                                  updated_by = ?, 
                                  updated_at = NOW()
                                  WHERE id = ? AND status = 'Sedang Dibawa Kurir'";
                    $stmt_update = $pdo->prepare($sql_update);
                    if ($stmt_update->execute([$kode_karyawan, $setoran_id])) {
                        if ($stmt_update->rowCount() > 0) {
                            $success_count++;
                            $received_setorans[] = $setoran_detail; // Simpan data untuk bukti penerimaan
                            
                            $sql_update_kasir = "UPDATE kasir_transactions SET 
                                                deposit_status = 'Diterima Staff Keuangan'
                                                WHERE kode_setoran = ? AND deposit_status = 'Sedang Dibawa Kurir'";
                            $stmt_update_kasir = $pdo->prepare($sql_update_kasir);
                            $stmt_update_kasir->execute([$setoran_detail['kode_setoran']]);
                        }
                    }
                }
            }

            $pdo->commit();
            $message = "$success_count setoran berhasil diterima. Silakan lakukan validasi fisik selanjutnya.";
            
            // Store received setorans in session untuk ditampilkan sebagai bukti
            $_SESSION['received_setorans'] = $received_setorans;
            $_SESSION['received_by'] = $username;
            $_SESSION['received_at'] = date('Y-m-d H:i:s');
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error: " . $e->getMessage();
        }
    }
}

// PERBAIKAN: Handle validasi fisik transaksi individual dengan kalkulasi closing yang benar
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['validasi_individual'])) {
    $transaksi_id = $_POST['transaksi_id'];
    $jumlah_diterima = str_replace(['Rp ', '.', ','], ['', '', ''], $_POST['jumlah_diterima'] ?? 0);
    $catatan_validasi = $_POST['catatan_validasi'] ?? '';

    if (!is_numeric($jumlah_diterima) || $jumlah_diterima < 0) {
        $error = "Jumlah diterima tidak valid.";
    } else {
        // Check if this is a closing transaction
        $is_closing = isClosingTransaction($transaksi_id);
        
        // PERBAIKAN: Enhanced query untuk mendapatkan informasi closing lengkap
        $sql_transaksi = "SELECT kt.setoran_real, kt.kode_setoran,
                                 -- Informasi closing jika ada
                                 pk.jumlah as jumlah_pemasukan_closing,
                                 pk.keterangan_transaksi as keterangan_closing,
                                 -- Hitung total gabungan closing jika ada
                                 CASE 
                                    WHEN EXISTS (SELECT 1 FROM pemasukan_kasir pk2 WHERE pk2.nomor_transaksi_closing = kt.kode_transaksi)
                                    THEN (
                                        SELECT COALESCE(SUM(pk3.jumlah), 0) 
                                        FROM pemasukan_kasir pk3 
                                        WHERE pk3.nomor_transaksi_closing = kt.kode_transaksi
                                    )
                                    ELSE 0
                                 END as total_closing_borrowed
                          FROM kasir_transactions kt
                          LEFT JOIN pemasukan_kasir pk ON pk.nomor_transaksi_closing = kt.kode_transaksi
                          WHERE kt.kode_transaksi = ? AND kt.deposit_status = 'Diterima Staff Keuangan'";
        $stmt = $pdo->prepare($sql_transaksi);
        $stmt->execute([$transaksi_id]);
        $data_transaksi = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data_transaksi) {
            // PERBAIKAN: Kalkulasi selisih dengan mempertimbangkan gabungan closing
            $setoran_real = $data_transaksi['setoran_real'];
            
            // Jika ini transaksi closing yang meminjam/dipinjam, sesuaikan kalkulasi
            if ($data_transaksi['total_closing_borrowed'] > 0) {
                // Untuk transaksi yang ada peminjamannya, sistem seharusnya menghitung:
                // Setoran Real - Jumlah yang dipinjam = Setoran yang seharusnya diterima fisik
                $expected_physical = $setoran_real - $data_transaksi['total_closing_borrowed'];
                $selisih = $jumlah_diterima - $expected_physical;
                
                // Log untuk debugging
                error_log("Closing Transaction Validation: $transaksi_id - Setoran Real: $setoran_real, Borrowed: {$data_transaksi['total_closing_borrowed']}, Expected: $expected_physical, Received: $jumlah_diterima, Selisih: $selisih");
            } else {
                $selisih = $jumlah_diterima - $setoran_real;
            }
            
            $kode_setoran = $data_transaksi['kode_setoran'];

            $pdo->beginTransaction();
            try {
                $new_status = ($selisih == 0) ? 'Validasi Keuangan OK' : 'Validasi Keuangan SELISIH';

                if ($validation_columns_exist) {
                    // Handle generated vs manual selisih_fisik column
                    if ($selisih_fisik_is_generated) {
                        $sql_update = "UPDATE kasir_transactions SET 
                                      jumlah_diterima_fisik = ?, 
                                      deposit_status = ?, 
                                      catatan_validasi = ?,
                                      validasi_at = NOW(),
                                      validasi_by = ?
                                      WHERE kode_transaksi = ? AND deposit_status = 'Diterima Staff Keuangan'";
                        $stmt_update = $pdo->prepare($sql_update);
                        $stmt_update->execute([$jumlah_diterima, $new_status, $catatan_validasi, $kode_karyawan, $transaksi_id]);
                    } else {
                        $sql_update = "UPDATE kasir_transactions SET 
                                      jumlah_diterima_fisik = ?, 
                                      selisih_fisik = ?, 
                                      deposit_status = ?, 
                                      catatan_validasi = ?,
                                      validasi_at = NOW(),
                                      validasi_by = ?
                                      WHERE kode_transaksi = ? AND deposit_status = 'Diterima Staff Keuangan'";
                        $stmt_update = $pdo->prepare($sql_update);
                        $stmt_update->execute([$jumlah_diterima, $selisih, $new_status, $catatan_validasi, $kode_karyawan, $transaksi_id]);
                    }
                } else {
                    $sql_update = "UPDATE kasir_transactions SET 
                                  deposit_status = ?
                                  WHERE kode_transaksi = ? AND deposit_status = 'Diterima Staff Keuangan'";
                    $stmt_update = $pdo->prepare($sql_update);
                    $stmt_update->execute([$new_status, $transaksi_id]);
                }

                if ($stmt_update->rowCount() > 0) {
                    // Update setoran_keuangan status with improved logic for closing transactions
                    updateSetoranKeuanganStatus($pdo, $kode_setoran, $kode_karyawan, $validation_columns_exist, $selisih_fisik_is_generated);
                    
                    $pdo->commit();
                    
                    // Enhanced success message for closing transactions
                    $closing_info = $is_closing ? " [DARI CLOSING]" : "";
                    if ($data_transaksi['total_closing_borrowed'] > 0) {
                        $closing_info .= " [GABUNGAN: " . formatRupiah($data_transaksi['total_closing_borrowed']) . " dipinjam]";
                    }
                    $message = "Validasi berhasil$closing_info. Status: $new_status. Total diterima: " . formatRupiah($jumlah_diterima) . 
                              ($selisih != 0 ? " | Selisih: " . formatRupiah($selisih) : " | Sesuai dengan sistem");
                } else {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = "Transaksi tidak dapat divalidasi. Pastikan status transaksi adalah 'Diterima Staff Keuangan'.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error validasi: " . $e->getMessage();
                error_log("Validation error: " . $e->getMessage());
            }
        } else {
            $error = "Transaksi tidak ditemukan atau belum diterima.";
        }
    }
}

// PERBAIKAN: Handle edit selisih dengan kalkulasi closing yang benar
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_selisih'])) {
    $transaksi_id = $_POST['transaksi_id'];
    $jumlah_diterima_baru = str_replace(['Rp ', '.', ','], ['', '', ''], $_POST['jumlah_diterima_baru'] ?? 0);
    $catatan_validasi = $_POST['catatan_validasi'] ?? '';

    if (!is_numeric($jumlah_diterima_baru) || $jumlah_diterima_baru < 0) {
        $error = "Jumlah diterima tidak valid.";
    } else {
        $is_closing = isClosingTransaction($transaksi_id);
        
        // PERBAIKAN: Enhanced query untuk edit selisih closing
        $sql_transaksi = "SELECT kt.setoran_real, kt.kode_setoran,
                                 -- Informasi closing jika ada
                                 pk.jumlah as jumlah_pemasukan_closing,
                                 pk.keterangan_transaksi as keterangan_closing,
                                 -- Hitung total gabungan closing jika ada
                                 CASE 
                                    WHEN EXISTS (SELECT 1 FROM pemasukan_kasir pk2 WHERE pk2.nomor_transaksi_closing = kt.kode_transaksi)
                                    THEN (
                                        SELECT COALESCE(SUM(pk3.jumlah), 0) 
                                        FROM pemasukan_kasir pk3 
                                        WHERE pk3.nomor_transaksi_closing = kt.kode_transaksi
                                    )
                                    ELSE 0
                                 END as total_closing_borrowed
                          FROM kasir_transactions kt
                          LEFT JOIN pemasukan_kasir pk ON pk.nomor_transaksi_closing = kt.kode_transaksi
                          WHERE kt.kode_transaksi = ? AND kt.deposit_status = 'Validasi Keuangan SELISIH'";
        $stmt = $pdo->prepare($sql_transaksi);
        $stmt->execute([$transaksi_id]);
        $data_transaksi = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data_transaksi) {
            // PERBAIKAN: Kalkulasi selisih baru dengan mempertimbangkan gabungan closing
            $setoran_real = $data_transaksi['setoran_real'];
            
            // Jika ini transaksi closing yang meminjam/dipinjam, sesuaikan kalkulasi
            if ($data_transaksi['total_closing_borrowed'] > 0) {
                $expected_physical = $setoran_real - $data_transaksi['total_closing_borrowed'];
                $selisih_baru = $jumlah_diterima_baru - $expected_physical;
            } else {
                $selisih_baru = $jumlah_diterima_baru - $setoran_real;
            }
            
            $kode_setoran = $data_transaksi['kode_setoran'];

            $pdo->beginTransaction();
            try {
                $new_status = ($selisih_baru == 0) ? 'Validasi Keuangan OK' : 'Validasi Keuangan SELISIH';

                if ($validation_columns_exist) {
                    if ($selisih_fisik_is_generated) {
                        $sql_update = "UPDATE kasir_transactions SET 
                                      jumlah_diterima_fisik = ?, 
                                      deposit_status = ?, 
                                      catatan_validasi = ?,
                                      validasi_at = NOW(),
                                      validasi_by = ?
                                      WHERE kode_transaksi = ?";
                        $stmt_update = $pdo->prepare($sql_update);
                        $stmt_update->execute([$jumlah_diterima_baru, $new_status, $catatan_validasi, $kode_karyawan, $transaksi_id]);
                    } else {
                        $sql_update = "UPDATE kasir_transactions SET 
                                      jumlah_diterima_fisik = ?, 
                                      selisih_fisik = ?, 
                                      deposit_status = ?, 
                                      catatan_validasi = ?,
                                      validasi_at = NOW(),
                                      validasi_by = ?
                                      WHERE kode_transaksi = ?";
                        $stmt_update = $pdo->prepare($sql_update);
                        $stmt_update->execute([$jumlah_diterima_baru, $selisih_baru, $new_status, $catatan_validasi, $kode_karyawan, $transaksi_id]);
                    }
                } else {
                    $sql_update = "UPDATE kasir_transactions SET 
                                  deposit_status = ?
                                  WHERE kode_transaksi = ?";
                    $stmt_update = $pdo->prepare($sql_update);
                    $stmt_update->execute([$new_status, $transaksi_id]);
                }

                if ($stmt_update->rowCount() > 0) {
                    // Update setoran_keuangan status
                    updateSetoranKeuanganStatus($pdo, $kode_setoran, $kode_karyawan, $validation_columns_exist, $selisih_fisik_is_generated);

                    $pdo->commit();
                    
                    $closing_info = $is_closing ? " [DARI CLOSING]" : "";
                    if ($data_transaksi['total_closing_borrowed'] > 0) {
                        $closing_info .= " [GABUNGAN: " . formatRupiah($data_transaksi['total_closing_borrowed']) . " dipinjam]";
                    }
                    $message = "Edit selisih berhasil$closing_info. Status: $new_status. Total diterima: " . formatRupiah($jumlah_diterima_baru) . 
                              ($selisih_baru != 0 ? " | Selisih: " . formatRupiah($selisih_baru) : " | Sesuai dengan sistem");
                } else {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = "Transaksi tidak dapat diupdate.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error edit selisih: " . $e->getMessage();
                error_log("Edit selisih error: " . $e->getMessage());
            }
        } else {
            $error = "Transaksi tidak ditemukan atau bukan status selisih.";
        }
    }
}

// Handle kembalikan setoran ke CS pengirim
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['kembalikan_ke_cs'])) {
    $transaksi_id = $_POST['transaksi_id'];
    $alasan_kembalikan = $_POST['alasan_kembalikan'] ?? '';
    
    // Validasi transaksi exists dan status yang bisa dikembalikan (selisih atau masih dalam validasi)
    $sql_check = "SELECT kt.*, sk.kode_karyawan, sk.kode_cabang, u.nama_karyawan, u.nama_cabang
                  FROM kasir_transactions kt
                  LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
                  LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
                  WHERE kt.kode_transaksi = ? AND kt.deposit_status IN ('Validasi Keuangan SELISIH', 'Diterima Staff Keuangan')";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([$transaksi_id]);
    $data_transaksi = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if ($data_transaksi) {
        $pdo->beginTransaction();
        try {
            // PERBAIKAN: Update status transaksi ke "Dikembalikan ke CS" dan handle closing transactions
            $transaksi_dikembalikan = [$transaksi_id];
            $catatan_kembalikan = "DIKEMBALIKAN KE CS - Alasan: " . $alasan_kembalikan;
            
            // Check if this is a closing transaction that involves related transactions
            // 1. Check if this transaction is taken by another transaction (closing B mengambil dari A)
            $sql_check_taken = "
                SELECT kt_taking.kode_transaksi as taking_transaction
                FROM pemasukan_kasir pk 
                INNER JOIN kasir_transactions kt_taking ON pk.kode_transaksi = kt_taking.kode_transaksi
                WHERE pk.nomor_transaksi_closing = ?
                AND kt_taking.kode_setoran = ?
            ";
            $stmt_taken = $pdo->prepare($sql_check_taken);
            $stmt_taken->execute([$transaksi_id, $data_transaksi['kode_setoran']]);
            $taking_transaction = $stmt_taken->fetchColumn();
            
            if ($taking_transaction) {
                $transaksi_dikembalikan[] = $taking_transaction;
                $catatan_kembalikan .= " (Termasuk transaksi terkait: {$taking_transaction})";
            }
            
            // 2. Check if this transaction takes from another transaction (closing A yang diambil oleh B)
            $sql_check_takes_from = "
                SELECT pk.nomor_transaksi_closing as source_transaction
                FROM pemasukan_kasir pk 
                INNER JOIN kasir_transactions kt_source ON pk.nomor_transaksi_closing = kt_source.kode_transaksi
                WHERE pk.kode_transaksi = ?
                AND kt_source.kode_setoran = ?
            ";
            $stmt_takes_from = $pdo->prepare($sql_check_takes_from);
            $stmt_takes_from->execute([$transaksi_id, $data_transaksi['kode_setoran']]);
            $source_transaction = $stmt_takes_from->fetchColumn();
            
            if ($source_transaction && !in_array($source_transaction, $transaksi_dikembalikan)) {
                $transaksi_dikembalikan[] = $source_transaction;
                $catatan_kembalikan .= " (Termasuk sumber transaksi: {$source_transaction})";
            }
            
            // Update all related transactions
            $updated_count = 0;
            foreach ($transaksi_dikembalikan as $kode_trans) {
                $sql_update = "UPDATE kasir_transactions SET 
                              deposit_status = 'Dikembalikan ke CS',
                              catatan_validasi = ?,
                              validasi_at = NOW(),
                              validasi_by = ?
                              WHERE kode_transaksi = ? AND kode_setoran = ?";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->execute([$catatan_kembalikan, $_SESSION['kode_karyawan'], $kode_trans, $data_transaksi['kode_setoran']]);
                $updated_count += $stmt_update->rowCount();
            }
            
            if ($updated_count > 0) {
                // Update setoran_keuangan status
                updateSetoranKeuanganStatus($pdo, $data_transaksi['kode_setoran'], $data_transaksi['kode_karyawan'], $validation_columns_exist, $selisih_fisik_is_generated);
                
                // Log aktivitas
                $transaksi_list = implode(', ', $transaksi_dikembalikan);
                $log_message = "Transaksi closing dikembalikan: {$transaksi_list} ke CS {$data_transaksi['nama_karyawan']} - Cabang {$data_transaksi['nama_cabang']}. Alasan: {$alasan_kembalikan}";
                error_log("KEMBALIKAN KE CS CLOSING: " . $log_message);
                
                $pdo->commit();
                
                if (count($transaksi_dikembalikan) > 1) {
                    $message = "Berhasil dikembalikan " . count($transaksi_dikembalikan) . " transaksi terkait closing ke CS: " . $data_transaksi['nama_karyawan'] . " (" . $data_transaksi['nama_cabang'] . ")\\n\\nTransaksi: " . $transaksi_list . "\\n\\nSemua transaksi akan otomatis dikurangi dari setoran.";
                } else {
                    $message = "Transaksi berhasil dikembalikan ke CS pengirim: " . $data_transaksi['nama_karyawan'] . " (" . $data_transaksi['nama_cabang'] . ")";
                }
            } else {
                throw new Exception("Gagal mengupdate status transaksi");
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error kembalikan ke CS: " . $e->getMessage();
            error_log("Kembalikan ke CS error: " . $e->getMessage());
        }
    } else {
        $error = "Transaksi tidak ditemukan atau bukan status selisih.";
    }
}

// Helper function to update setoran_keuangan status
function updateSetoranKeuanganStatus($pdo, $kode_setoran, $kode_karyawan, $validation_columns_exist, $selisih_fisik_is_generated) {
    // Count total and validated transactions (termasuk yang dikembalikan ke CS)
    $sql_count_total = "SELECT COUNT(*) FROM kasir_transactions WHERE kode_setoran = ? AND deposit_status IN ('Diterima Staff Keuangan', 'Validasi Keuangan OK', 'Validasi Keuangan SELISIH', 'Dikembalikan ke CS')";
    $stmt_total = $pdo->prepare($sql_count_total);
    $stmt_total->execute([$kode_setoran]);
    $total_transaksi = $stmt_total->fetchColumn();

    $sql_count_validated = "SELECT COUNT(*) FROM kasir_transactions WHERE kode_setoran = ? AND deposit_status IN ('Validasi Keuangan OK', 'Validasi Keuangan SELISIH', 'Dikembalikan ke CS')";
    $stmt_validated = $pdo->prepare($sql_count_validated);
    $stmt_validated->execute([$kode_setoran]);
    $validated_transaksi = $stmt_validated->fetchColumn();

    if ($total_transaksi == $validated_transaksi && $total_transaksi > 0) {
        // Check berbagai status untuk menentukan status setoran
        $sql_count_selisih = "SELECT COUNT(*) FROM kasir_transactions WHERE kode_setoran = ? AND deposit_status = 'Validasi Keuangan SELISIH'";
        $stmt_selisih = $pdo->prepare($sql_count_selisih);
        $stmt_selisih->execute([$kode_setoran]);
        $selisih_count = $stmt_selisih->fetchColumn();
        
        $sql_count_dikembalikan = "SELECT COUNT(*) FROM kasir_transactions WHERE kode_setoran = ? AND deposit_status = 'Dikembalikan ke CS'";
        $stmt_dikembalikan = $pdo->prepare($sql_count_dikembalikan);
        $stmt_dikembalikan->execute([$kode_setoran]);
        $dikembalikan_count = $stmt_dikembalikan->fetchColumn();

        // Tentukan status berdasarkan prioritas: Dikembalikan > Selisih > OK
        if ($dikembalikan_count > 0) {
            $setoran_status = 'Ada yang Dikembalikan ke CS';
        } elseif ($selisih_count > 0) {
            $setoran_status = 'Validasi Keuangan SELISIH';
        } else {
            $setoran_status = 'Validasi Keuangan OK';
        }

        // Calculate totals
        if ($validation_columns_exist) {
            if ($selisih_fisik_is_generated) {
                $sql_sum = "SELECT SUM(COALESCE(jumlah_diterima_fisik, setoran_real)) as total_diterima, 
                                  SUM(COALESCE(selisih_fisik, 0)) as total_selisih 
                           FROM kasir_transactions WHERE kode_setoran = ?";
                $stmt_sum = $pdo->prepare($sql_sum);
                $stmt_sum->execute([$kode_setoran]);
            } else {
                $sql_sum = "SELECT SUM(COALESCE(jumlah_diterima_fisik, setoran_real)) as total_diterima, 
                                  SUM(COALESCE(jumlah_diterima_fisik, setoran_real) - setoran_real) as total_selisih 
                           FROM kasir_transactions WHERE kode_setoran = ?";
                $stmt_sum = $pdo->prepare($sql_sum);
                $stmt_sum->execute([$kode_setoran]);
            }
        } else {
            $sql_sum = "SELECT SUM(setoran_real) as total_diterima, 0 as total_selisih 
                       FROM kasir_transactions WHERE kode_setoran = ?";
            $stmt_sum = $pdo->prepare($sql_sum);
            $stmt_sum->execute([$kode_setoran]);
        }
        
        $sum_data = $stmt_sum->fetch(PDO::FETCH_ASSOC);

        $sql_update_setoran = "UPDATE setoran_keuangan SET 
                              jumlah_diterima = ?, 
                              selisih_setoran = ?, 
                              status = ?, 
                              updated_by = ?, 
                              updated_at = NOW()
                              WHERE kode_setoran = ? AND status = 'Diterima Staff Keuangan'";
        $stmt_update_setoran = $pdo->prepare($sql_update_setoran);
        $stmt_update_setoran->execute([
            $sum_data['total_diterima'], 
            $sum_data['total_selisih'], 
            $setoran_status,
            $kode_karyawan, 
            $kode_setoran
        ]);
    }
}
// Handle setor ke bank
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['setor_bank'])) {
    // Debug logging
    error_log("SETOR BANK: POST data received");
    error_log("closing_ids: " . print_r($_POST['closing_ids'] ?? [], true));
    error_log("rekening_cabang_id: " . ($_POST['rekening_cabang_id'] ?? 'empty'));
    error_log("tanggal_setoran: " . ($_POST['tanggal_setoran'] ?? 'empty'));
    
    $closing_ids = $_POST['closing_ids'] ?? [];
    $rekening_cabang_id = $_POST['rekening_cabang_id'] ?? '';
    $tanggal_setor = $_POST['tanggal_setoran'] ?? date('Y-m-d');

    if (empty($closing_ids)) {
        $error = "Pilih transaksi closing untuk disetor.";
        error_log("SETOR BANK ERROR: No closing transactions selected");
    } elseif (empty($rekening_cabang_id)) {
        $error = "Pilih rekening cabang tujuan.";
        error_log("SETOR BANK ERROR: No rekening selected");
    } else {
        // Check if all selected closing transactions are valid and from same cabang as rekening
        $placeholders = array_fill(0, count($closing_ids), '?');
        
        // Get no_rekening from selected rekening to allow multiple cabang with same rekening
        // Handle multiple rekening IDs (comma separated)
        error_log("SETOR BANK: Processing rekening_cabang_id: " . $rekening_cabang_id);
        $rekening_ids = explode(',', $rekening_cabang_id);
        $first_rekening_id = $rekening_ids[0];
        error_log("SETOR BANK: First rekening ID: " . $first_rekening_id);
        
        $sql_get_rekening = "SELECT no_rekening FROM master_rekening_cabang WHERE id = ?";
        $stmt_get_rekening = $pdo->prepare($sql_get_rekening);
        $stmt_get_rekening->execute([$first_rekening_id]);
        $target_no_rekening = $stmt_get_rekening->fetchColumn();
        
        if (!$target_no_rekening) {
            $error = "Rekening cabang tidak ditemukan.";
        } else {
            // Get all kode_cabang that use the same no_rekening
            $sql_get_cabang_list = "SELECT kode_cabang FROM master_rekening_cabang WHERE no_rekening = ? AND status = 'active'";
            $stmt_get_cabang_list = $pdo->prepare($sql_get_cabang_list);
            $stmt_get_cabang_list->execute([$target_no_rekening]);
            $allowed_cabang = $stmt_get_cabang_list->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($allowed_cabang)) {
                $error = "Tidak ada cabang yang menggunakan rekening ini.";
            } else {
                // Check if all closing transactions are valid and from cabang that use the same no_rekening
                $cabang_placeholders = array_fill(0, count($allowed_cabang), '?');
                $sql_check = "SELECT COUNT(*) FROM kasir_transactions kt
                             LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
                             WHERE kt.id IN (" . implode(',', $placeholders) . ") 
                             AND (kt.deposit_status != 'Validasi Keuangan OK' OR sk.kode_cabang NOT IN (" . implode(',', $cabang_placeholders) . "))";
                $stmt_check = $pdo->prepare($sql_check);
                $params_check = array_merge($closing_ids, $allowed_cabang);
                $stmt_check->execute($params_check);
            
                if ($stmt_check->fetchColumn() > 0) {
                    $error = "Tidak dapat setor ke bank. Pastikan semua transaksi closing dari cabang yang menggunakan rekening tujuan yang sama dan tidak ada selisih.";
                } else {
                $sql_check_status = "SELECT COUNT(*) FROM kasir_transactions kt LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran WHERE kt.id IN (" . implode(',', $placeholders) . ") AND (kt.deposit_status NOT IN ('Validasi Keuangan OK') OR sk.status NOT IN ('Validasi Keuangan OK'))";
                $stmt_check_status = $pdo->prepare($sql_check_status);
                foreach ($closing_ids as $index => $id) {
                    $stmt_check_status->bindValue($index + 1, $id, PDO::PARAM_INT);
                }
                $stmt_check_status->execute();
                
                if ($stmt_check_status->fetchColumn() > 0) {
                    $error = "Semua transaksi closing harus dalam status 'Validasi Keuangan OK' sebelum disetor ke bank.";
                } else {
                    $sql_total = "SELECT SUM(kt.setoran_real) FROM kasir_transactions kt WHERE kt.id IN (" . implode(',', $placeholders) . ")";
                    $stmt_total = $pdo->prepare($sql_total);
                    foreach ($closing_ids as $index => $id) {
                        $stmt_total->bindValue($index + 1, $id, PDO::PARAM_INT);
                    }
                    $stmt_total->execute();
                    $total_setoran = $stmt_total->fetchColumn();

                    // Get setoran IDs from selected closing transactions
                    $sql_get_setoran_ids = "SELECT DISTINCT sk.id FROM kasir_transactions kt 
                                           JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran 
                                           WHERE kt.id IN (" . implode(',', $placeholders) . ")";
                    $stmt_get_setoran_ids = $pdo->prepare($sql_get_setoran_ids);
                    foreach ($closing_ids as $index => $id) {
                        $stmt_get_setoran_ids->bindValue($index + 1, $id, PDO::PARAM_INT);
                    }
                    $stmt_get_setoran_ids->execute();
                    $setoran_ids = $stmt_get_setoran_ids->fetchAll(PDO::FETCH_COLUMN);

                    // Temporarily disable the trigger to avoid recursive update conflict
                    // Note: DDL statements (DROP/CREATE TRIGGER) implicitly commit transactions
                    try {
                        $pdo->exec("DROP TRIGGER IF EXISTS tr_update_setoran_status_backup");
                        $pdo->exec("CREATE TRIGGER tr_update_setoran_status_backup AFTER UPDATE ON kasir_transactions FOR EACH ROW BEGIN END");
                        $pdo->exec("DROP TRIGGER IF EXISTS tr_update_setoran_status");
                        error_log("SETOR BANK: Trigger temporarily disabled");
                    } catch (Exception $trigger_drop_error) {
                        error_log("SETOR BANK WARNING: Could not drop trigger: " . $trigger_drop_error->getMessage());
                    }
                    
                    $pdo->beginTransaction();
                    try {
                        $year = date('Y');
                        $sql_count = "SELECT COUNT(*) FROM setoran_ke_bank WHERE YEAR(created_at) = ?";
                        $stmt_count = $pdo->prepare($sql_count);
                        $stmt_count->execute([$year]);
                        $count = $stmt_count->fetchColumn() + 1;
                        $kode_setoran_bank = "BANK-$year-" . str_pad($count, 4, '0', STR_PAD_LEFT);

                        // Get rekening info - use first rekening ID from the selected group
                        $selected_rekening_ids = explode(',', $rekening_cabang_id);
                        $first_selected_id = $selected_rekening_ids[0];
                        
                        $sql_rekening = "SELECT * FROM master_rekening_cabang WHERE id = ?";
                        $stmt_rekening = $pdo->prepare($sql_rekening);
                        $stmt_rekening->execute([$first_selected_id]);
                        $rekening_info = $stmt_rekening->fetch(PDO::FETCH_ASSOC);

                        $rekening_tujuan = $rekening_info['nama_bank'] . ' - ' . $rekening_info['no_rekening'] . ' (' . $rekening_info['nama_rekening'] . ')';

                        $sql_bank = "INSERT INTO setoran_ke_bank (kode_setoran, tanggal_setoran, metode_setoran, rekening_tujuan, total_setoran, created_by)
                                     VALUES (?, ?, 'Tunai', ?, ?, ?)";
                        $stmt_bank = $pdo->prepare($sql_bank);
                        $stmt_bank->execute([$kode_setoran_bank, $tanggal_setor, $rekening_tujuan, $total_setoran, $kode_karyawan]);

                        $setoran_ke_bank_id = $pdo->lastInsertId();

                        $sql_detail = "INSERT INTO setoran_ke_bank_detail (setoran_ke_bank_id, setoran_keuangan_id) VALUES (?, ?)";
                        $stmt_detail = $pdo->prepare($sql_detail);
                        foreach ($setoran_ids as $id) {
                            $stmt_detail->execute([$setoran_ke_bank_id, $id]);
                        }

                        // Now we can safely update both tables since trigger is disabled
                        // Create placeholders for setoran_ids
                        $setoran_placeholders = array_fill(0, count($setoran_ids), '?');
                        $sql_update_sk = "UPDATE setoran_keuangan SET status = 'Sudah Disetor ke Bank', updated_by = ?, updated_at = NOW() 
                                         WHERE id IN (" . implode(',', $setoran_placeholders) . ")";
                        $stmt_update_sk = $pdo->prepare($sql_update_sk);
                        $stmt_update_sk->bindValue(1, $kode_karyawan, PDO::PARAM_STR);
                        foreach ($setoran_ids as $index => $id) {
                            $stmt_update_sk->bindValue($index + 2, $id, PDO::PARAM_INT);
                        }
                        $stmt_update_sk->execute();

                        // Update kasir_transactions
                        $sql_update_trans = "UPDATE kasir_transactions kt
                                            JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
                                            SET kt.deposit_status = 'Sudah Disetor ke Bank', kt.rekening_tujuan_id = ?, kt.validasi_by = ?
                                            WHERE sk.id IN (" . implode(',', $setoran_placeholders) . ")";
                        $stmt_update_trans = $pdo->prepare($sql_update_trans);
                        $stmt_update_trans->bindValue(1, $first_rekening_id, PDO::PARAM_INT);
                        $stmt_update_trans->bindValue(2, $kode_karyawan, PDO::PARAM_STR);
                        foreach ($setoran_ids as $index => $id) {
                            $stmt_update_trans->bindValue($index + 3, $id, PDO::PARAM_INT);
                        }
                        $stmt_update_trans->execute();

                        $pdo->commit();
                        $message = "Setoran ke bank berhasil dengan kode: " . $kode_setoran_bank . " | Rekening: " . $rekening_tujuan;
                        error_log("SETOR BANK SUCCESS: " . $message);
                        
                        // Recreate the original trigger after successful transaction
                        try {
                            $pdo->exec("DROP TRIGGER IF EXISTS tr_update_setoran_status");
                            
                            $trigger_sql = "CREATE TRIGGER tr_update_setoran_status
                                AFTER UPDATE ON kasir_transactions
                                FOR EACH ROW
                                BEGIN
                                    DECLARE total_transaksi INT DEFAULT 0;
                                    DECLARE transaksi_validated INT DEFAULT 0;
                                    DECLARE transaksi_selisih INT DEFAULT 0;
                                    DECLARE transaksi_dikembalikan INT DEFAULT 0;
                                    DECLARE new_status VARCHAR(50);
                                    
                                    SELECT COUNT(*) INTO total_transaksi
                                    FROM kasir_transactions
                                    WHERE kode_setoran = NEW.kode_setoran 
                                    AND deposit_status IN ('Diterima Staff Keuangan', 'Validasi Keuangan OK', 'Validasi Keuangan SELISIH', 'Dikembalikan ke CS');
                                    
                                    SELECT COUNT(*) INTO transaksi_validated
                                    FROM kasir_transactions
                                    WHERE kode_setoran = NEW.kode_setoran
                                    AND deposit_status IN ('Validasi Keuangan OK', 'Validasi Keuangan SELISIH', 'Dikembalikan ke CS');
                                    
                                    SELECT COUNT(*) INTO transaksi_selisih
                                    FROM kasir_transactions
                                    WHERE kode_setoran = NEW.kode_setoran
                                    AND deposit_status = 'Validasi Keuangan SELISIH';
                                    
                                    SELECT COUNT(*) INTO transaksi_dikembalikan
                                    FROM kasir_transactions
                                    WHERE kode_setoran = NEW.kode_setoran
                                    AND deposit_status = 'Dikembalikan ke CS';
                                    
                                    IF total_transaksi = transaksi_validated AND total_transaksi > 0 THEN
                                        IF transaksi_dikembalikan > 0 THEN
                                            SET new_status = 'Ada yang Dikembalikan ke CS';
                                        ELSEIF transaksi_selisih > 0 THEN
                                            SET new_status = 'Validasi Keuangan SELISIH';
                                        ELSE
                                            SET new_status = 'Validasi Keuangan OK';
                                        END IF;
                                        
                                        UPDATE setoran_keuangan 
                                        SET 
                                            status = new_status,
                                            updated_at = CURRENT_TIMESTAMP,
                                            updated_by = NEW.validasi_by
                                        WHERE kode_setoran = NEW.kode_setoran;
                                    END IF;
                                END";
                            $pdo->exec($trigger_sql);
                            error_log("SETOR BANK: Trigger recreated after successful transaction");
                        } catch (Exception $trigger_recreate_error) {
                            error_log("SETOR BANK WARNING: Failed to recreate trigger after success: " . $trigger_recreate_error->getMessage());
                        }
                    } catch (Exception $e) {
                        error_log("SETOR BANK EXCEPTION: " . $e->getMessage());
                        error_log("SETOR BANK TRACE: " . $e->getTraceAsString());
                        
                        // Only rollback if transaction is active
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                            error_log("SETOR BANK: Transaction rolled back");
                        } else {
                            error_log("SETOR BANK: No active transaction to rollback");
                        }
                        
                        $error = "Error: " . $e->getMessage();
                        error_log("SETOR BANK ERROR: " . $e->getMessage());
                        
                        // Always recreate trigger even on error (outside transaction)
                        try {
                            $pdo->exec("DROP TRIGGER IF EXISTS tr_update_setoran_status");
                            
                            $trigger_sql = "CREATE TRIGGER tr_update_setoran_status
                                AFTER UPDATE ON kasir_transactions
                                FOR EACH ROW
                                BEGIN
                                    DECLARE total_transaksi INT DEFAULT 0;
                                    DECLARE transaksi_validated INT DEFAULT 0;
                                    DECLARE transaksi_selisih INT DEFAULT 0;
                                    DECLARE transaksi_dikembalikan INT DEFAULT 0;
                                    DECLARE new_status VARCHAR(50);
                                    
                                    SELECT COUNT(*) INTO total_transaksi
                                    FROM kasir_transactions
                                    WHERE kode_setoran = NEW.kode_setoran 
                                    AND deposit_status IN ('Diterima Staff Keuangan', 'Validasi Keuangan OK', 'Validasi Keuangan SELISIH', 'Dikembalikan ke CS');
                                    
                                    SELECT COUNT(*) INTO transaksi_validated
                                    FROM kasir_transactions
                                    WHERE kode_setoran = NEW.kode_setoran
                                    AND deposit_status IN ('Validasi Keuangan OK', 'Validasi Keuangan SELISIH', 'Dikembalikan ke CS');
                                    
                                    SELECT COUNT(*) INTO transaksi_selisih
                                    FROM kasir_transactions
                                    WHERE kode_setoran = NEW.kode_setoran
                                    AND deposit_status = 'Validasi Keuangan SELISIH';
                                    
                                    SELECT COUNT(*) INTO transaksi_dikembalikan
                                    FROM kasir_transactions
                                    WHERE kode_setoran = NEW.kode_setoran
                                    AND deposit_status = 'Dikembalikan ke CS';
                                    
                                    IF total_transaksi = transaksi_validated AND total_transaksi > 0 THEN
                                        IF transaksi_dikembalikan > 0 THEN
                                            SET new_status = 'Ada yang Dikembalikan ke CS';
                                        ELSEIF transaksi_selisih > 0 THEN
                                            SET new_status = 'Validasi Keuangan SELISIH';
                                        ELSE
                                            SET new_status = 'Validasi Keuangan OK';
                                        END IF;
                                        
                                        UPDATE setoran_keuangan 
                                        SET 
                                            status = new_status,
                                            updated_at = CURRENT_TIMESTAMP,
                                            updated_by = NEW.validasi_by
                                        WHERE kode_setoran = NEW.kode_setoran;
                                    END IF;
                                END";
                            $pdo->exec($trigger_sql);
                            error_log("SETOR BANK ERROR RECOVERY: Trigger recreated after error");
                        } catch (Exception $trigger_error) {
                            error_log("SETOR BANK CRITICAL: Failed to recreate trigger after error: " . $trigger_error->getMessage());
                        }
                    }
                }
                }
            }
        }
    }
}

// Initialize tab variable dengan default value
$tab = $_GET['tab'] ?? $_POST['tab_filter'] ?? 'terima';

// Fetch setoran data with filters - IMPROVED with closing transaction handling
$tanggal_awal = $_POST['tanggal_awal'] ?? $_GET['tanggal_awal'] ?? '';
$tanggal_akhir = $_POST['tanggal_akhir'] ?? $_GET['tanggal_akhir'] ?? '';
$cabang = $_POST['cabang'] ?? $_GET['cabang'] ?? 'all';
$status = $_POST['status'] ?? $_GET['status'] ?? 'all';
$status_filter = $_POST['status_filter'] ?? $_GET['status_filter'] ?? 'all';
$rekening_filter = $_POST['rekening_filter'] ?? $_GET['rekening_filter'] ?? 'all';

// Debug logging
error_log("Rekening filter: " . $rekening_filter);
error_log("Tab: " . $tab);

$sql_setoran = "
    SELECT sk.*, COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan
    FROM setoran_keuangan sk
    LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
    WHERE 1=1";

$params = [];

if ($tab == 'terima') {
    $sql_setoran .= " AND sk.status = 'Sedang Dibawa Kurir'";
} elseif ($tab == 'validasi') {
    // IMPROVED query to show closing transaction details
    $sql_setoran = "
        SELECT kt.*, sk.nama_cabang, sk.tanggal_setoran, sk.nama_pengantar, 
               COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan,
               CASE 
                   WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' THEN 'DARI CLOSING'
                   WHEN EXISTS (
                       SELECT 1 FROM pemasukan_kasir pk 
                       WHERE pk.nomor_transaksi_closing = kt.kode_transaksi
                   ) THEN 'DARI CLOSING'
                   ELSE 'TRANSAKSI BIASA'
               END as jenis_transaksi,
               -- TAMBAHAN: Informasi closing gabungan
               pk.jumlah as jumlah_pemasukan_closing,
               pk.keterangan_transaksi as keterangan_closing,
               (SELECT COUNT(*) FROM kasir_transactions kt2 
                WHERE kt2.kode_setoran = kt.kode_setoran 
                AND (kt2.kode_transaksi LIKE '%CLOSING%' OR kt2.kode_transaksi LIKE '%CLO%'
                     OR EXISTS (
                         SELECT 1 FROM pemasukan_kasir pk2 
                         WHERE pk2.nomor_transaksi_closing = kt2.kode_transaksi
                     ))) as total_closing_in_setoran
        FROM kasir_transactions kt
        LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
        LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
        LEFT JOIN pemasukan_kasir pk ON pk.nomor_transaksi_closing = kt.kode_transaksi
        WHERE kt.deposit_status = 'Diterima Staff Keuangan'";
} elseif ($tab == 'validasi_selisih') {
    // IMPROVED query to show closing transaction details for selisih
    $sql_setoran = "
        SELECT kt.*, sk.nama_cabang, sk.tanggal_setoran, sk.nama_pengantar, 
               COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan,
               CASE 
                   WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' THEN 'DARI CLOSING'
                   WHEN EXISTS (
                       SELECT 1 FROM pemasukan_kasir pk 
                       WHERE pk.nomor_transaksi_closing = kt.kode_transaksi
                   ) THEN 'DARI CLOSING'
                   ELSE 'TRANSAKSI BIASA'
               END as jenis_transaksi,
               -- TAMBAHAN: Informasi closing gabungan
               pk.jumlah as jumlah_pemasukan_closing,
               pk.keterangan_transaksi as keterangan_closing,
               (SELECT COUNT(*) FROM kasir_transactions kt2 
                WHERE kt2.kode_setoran = kt.kode_setoran 
                AND (kt2.kode_transaksi LIKE '%CLOSING%' OR kt2.kode_transaksi LIKE '%CLO%'
                     OR EXISTS (
                         SELECT 1 FROM pemasukan_kasir pk2 
                         WHERE pk2.nomor_transaksi_closing = kt2.kode_transaksi
                     ))) as total_closing_in_setoran
        FROM kasir_transactions kt
        LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
        LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
        LEFT JOIN pemasukan_kasir pk ON pk.nomor_transaksi_closing = kt.kode_transaksi
        WHERE kt.deposit_status = 'Validasi Keuangan SELISIH'";
} elseif ($tab == 'setor_bank') {
    // Filter siap setor bank by status, show individual closing transactions instead of grouped setoran
    $sql_setoran = "
        SELECT 
            kt.id,
            kt.kode_transaksi,
            kt.tanggal_transaksi,
            kt.tanggal_closing,
            kt.jam_closing,
            kt.setoran_real,
            kt.omset,
            kt.data_setoran,
            kt.deposit_status,
            kt.kode_setoran,
            kt.nama_cabang,
            kt.kode_karyawan as kt_kode_karyawan,
            sk.kode_setoran as setoran_kode,
            sk.tanggal_setoran,
            sk.jumlah_setoran,
            sk.nama_pengantar,
            sk.status as setoran_status,
            sk.kode_karyawan,
            sk.kode_cabang,
            sk.nama_cabang as sk_nama_cabang,
            COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan
        FROM kasir_transactions kt
        LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
        LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan
        WHERE sk.status = 'Validasi Keuangan OK' 
        AND kt.status = 'end proses'
        AND kt.deposit_status IN ('Validasi Keuangan OK')";

    // Add rekening filter for setor_bank - filter by cabang matching rekening with same no_rekening
    if ($rekening_filter !== 'all' && !empty($rekening_filter)) {
        // Handle multiple rekening IDs (comma separated)
        $rekening_ids = explode(',', $rekening_filter);
        $placeholders = array_fill(0, count($rekening_ids), '?');
        $sql_setoran .= " AND sk.kode_cabang IN (
            SELECT kode_cabang FROM master_rekening_cabang 
            WHERE id IN (" . implode(',', $placeholders) . ") AND status = 'active'
        )";
        $params = array_merge($params, $rekening_ids);
        error_log("Adding rekening filter with IDs: " . $rekening_filter);
    }
} elseif ($tab == 'monitoring') {
    // Monitoring query for individual closing transactions with detailed status tracking
    $sql_setoran = "
        SELECT 
            kt.id,
            kt.kode_transaksi,
            kt.tanggal_transaksi,
            kt.tanggal_closing,
            kt.jam_closing,
            kt.setoran_real,
            kt.deposit_status,
            kt.kode_setoran,
            kt.nama_cabang,
            kt.validasi_at,
            kt.catatan_validasi,
            kt.jumlah_diterima_fisik,
            COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan,
            sk.tanggal_setoran,
            sk.status as setoran_status,
            -- Bank deposit information
            sb.id as setor_bank_id,
            sb.tanggal_setoran as tanggal_setor_bank,
            sb.total_setoran as total_setor_bank,
            sb.rekening_tujuan as bank_account,
            sb.metode_setoran,
            sb.bukti_transfer,
            sb.created_at as bank_created_at,
            sb.created_by as bank_created_by,
            -- Check if it's a closing transaction
            CASE 
                WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' THEN 'CLOSING'
                WHEN EXISTS (
                    SELECT 1 FROM pemasukan_kasir pk 
                    WHERE pk.nomor_transaksi_closing = kt.kode_transaksi
                ) THEN 'DARI_CLOSING'
                ELSE 'REGULER'
            END as jenis_transaksi,
            -- Get selisih if available
            CASE 
                WHEN kt.jumlah_diterima_fisik IS NOT NULL 
                THEN (kt.setoran_real - kt.jumlah_diterima_fisik) 
                ELSE 0 
            END as selisih_fisik
        FROM kasir_transactions kt
        LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
        LEFT JOIN users u ON kt.kode_karyawan = u.kode_karyawan
        LEFT JOIN setoran_ke_bank_detail sbd ON sk.id = sbd.setoran_keuangan_id
        LEFT JOIN setoran_ke_bank sb ON sbd.setoran_ke_bank_id = sb.id
        WHERE (kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' 
               OR EXISTS (
                   SELECT 1 FROM pemasukan_kasir pk 
                   WHERE pk.nomor_transaksi_closing = kt.kode_transaksi
               ))
        AND (kt.status = 'end proses' 
             OR kt.deposit_status IN ('Validasi Keuangan OK', 'Validasi Keuangan SELISIH', 'Sedang Dibawa Kurir', 'Diterima Staff Keuangan', 'Dikembalikan ke CS', 'Sudah Disetor ke Bank'))";
} elseif ($tab == 'bank_history') {
    // Bank history query
    $sql_setoran = "
        SELECT sb.*, 
               GROUP_CONCAT(DISTINCT c.nama_cabang) as cabang_names,
               COUNT(sbd.setoran_keuangan_id) as total_setoran_count,
               u.nama_karyawan as created_by_name,
               MIN(kt.tanggal_closing) as tanggal_closing_transaksi,
               SUM(CASE WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' 
                        OR EXISTS (
                            SELECT 1 FROM pemasukan_kasir pk 
                            WHERE pk.nomor_transaksi_closing = kt.kode_transaksi
                        ) THEN 1 ELSE 0 END) as total_closing_transactions
        FROM setoran_ke_bank sb
        JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
        JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
        JOIN cabang c ON sk.kode_cabang = c.kode_cabang
        LEFT JOIN users u ON sb.created_by = u.kode_karyawan
        LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
        WHERE 1=1";
}

// Apply filters
if ($tanggal_awal && $tanggal_akhir) {
    if ($tab == 'validasi' || $tab == 'validasi_selisih') {
        $sql_setoran .= " AND sk.tanggal_setoran BETWEEN ? AND ?";
    } elseif ($tab == 'setor_bank') {
        // Filter Setor Bank by tanggal_setoran to ensure all dates in range are shown
        $sql_setoran .= " AND sk.tanggal_setoran BETWEEN ? AND ?";
    } elseif ($tab == 'bank_history') {
        $sql_setoran .= " AND sb.tanggal_setoran BETWEEN ? AND ?";
    } elseif ($tab == 'monitoring') {
        $sql_setoran .= " AND kt.tanggal_transaksi BETWEEN ? AND ?";
    } else {
        $sql_setoran .= " AND sk.tanggal_setoran BETWEEN ? AND ?";
    }
    $params[] = $tanggal_awal;
    $params[] = $tanggal_akhir;
}

if ($cabang !== 'all') {
    if ($tab == 'validasi' || $tab == 'validasi_selisih') {
        $sql_setoran .= " AND sk.nama_cabang = ?";
    } elseif ($tab == 'monitoring') {
        $sql_setoran .= " AND kt.nama_cabang = ?";
    } else {
        $sql_setoran .= " AND sk.nama_cabang = ?";
    }
    $params[] = $cabang;
}

// Add rekening filter for bank_history tab
if ($tab == 'bank_history' && $rekening_filter !== 'all' && !empty($rekening_filter)) {
    // Handle multiple rekening IDs (comma separated)
    $rekening_ids = explode(',', $rekening_filter);
    
    // Get the account info for filtering
    $placeholders = array_fill(0, count($rekening_ids), '?');
    $sql_get_rekening_info = "SELECT DISTINCT CONCAT(nama_bank, ' - ', no_rekening) as rekening_pattern 
                              FROM master_rekening_cabang 
                              WHERE id IN (" . implode(',', $placeholders) . ") AND status = 'active'";
    $stmt_get_rekening = $pdo->prepare($sql_get_rekening_info);
    $stmt_get_rekening->execute($rekening_ids);
    $rekening_patterns = $stmt_get_rekening->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($rekening_patterns)) {
        $rekening_conditions = array();
        foreach ($rekening_patterns as $pattern) {
            $rekening_conditions[] = "sb.rekening_tujuan LIKE ?";
            $params[] = $pattern . '%';
        }
        $sql_setoran .= " AND (" . implode(' OR ', $rekening_conditions) . ")";
    }
    error_log("Adding rekening filter for bank_history with patterns: " . implode(', ', $rekening_patterns));
}

// Add status filter for monitoring tab
if ($tab == 'monitoring' && $status_filter !== 'all') {
    $sql_setoran .= " AND kt.deposit_status = ?";
    $params[] = $status_filter;
}

// Add ORDER BY
if ($tab == 'validasi' || $tab == 'validasi_selisih') {
    $sql_setoran .= " ORDER BY 
        CASE 
            WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' THEN 0
            WHEN EXISTS (
                SELECT 1 FROM pemasukan_kasir pk3 
                WHERE pk3.nomor_transaksi_closing = kt.kode_transaksi
            ) THEN 0
            ELSE 1
        END,
        sk.tanggal_setoran DESC, kt.tanggal_transaksi DESC";
} elseif ($tab == 'bank_history') {
    $sql_setoran .= " GROUP BY sb.id ORDER BY sb.tanggal_setoran DESC";
} elseif ($tab == 'setor_bank') {
    // Order by tanggal closing for setor_bank tab (per closing transaction)
    $sql_setoran .= " ORDER BY kt.tanggal_closing DESC, kt.jam_closing DESC";
} elseif ($tab == 'monitoring') {
    $sql_setoran .= " ORDER BY 
        CASE kt.deposit_status 
            WHEN 'Sedang Dibawa Kurir' THEN 1
            WHEN 'Diterima Staff Keuangan' THEN 2
            WHEN 'Validasi Keuangan SELISIH' THEN 3
            WHEN 'Validasi Keuangan OK' THEN 4
            WHEN 'Dikembalikan ke CS' THEN 5
            WHEN 'Sudah Disetor ke Bank' THEN 6
            ELSE 7
        END,
        kt.tanggal_transaksi DESC, kt.jam_closing DESC";
} else {
    $sql_setoran .= " ORDER BY sk.tanggal_setoran DESC";
}

// Execute query
$stmt_setoran = $pdo->prepare($sql_setoran);
$stmt_setoran->execute($params);
$setoran_list = $stmt_setoran->fetchAll(PDO::FETCH_ASSOC);

// Debug output
error_log("Query: " . $sql_setoran);
error_log("Params: " . print_r($params, true));
error_log("Result count: " . count($setoran_list));

$sql_cabang = "SELECT DISTINCT nama_cabang FROM setoran_keuangan WHERE nama_cabang IS NOT NULL AND nama_cabang != '' ORDER BY nama_cabang";
$stmt_cabang = $pdo->query($sql_cabang);
$cabang_list = $stmt_cabang->fetchAll(PDO::FETCH_COLUMN);

// Get rekening list for dropdown grouped by no_rekening with all cabang names
$sql_rekening = "
    SELECT 
        mr.no_rekening,
        mr.nama_bank,
        MAX(mr.nama_rekening) as nama_rekening,
        MAX(mr.jenis_rekening) as jenis_rekening,
        GROUP_CONCAT(DISTINCT CONCAT(c.nama_cabang, '|', mr.id) ORDER BY c.nama_cabang SEPARATOR ';;') as cabang_info,
        GROUP_CONCAT(DISTINCT mr.id ORDER BY c.nama_cabang) as rekening_ids
    FROM master_rekening_cabang mr
    JOIN cabang c ON mr.kode_cabang = c.kode_cabang
    WHERE mr.status = 'active' 
    GROUP BY mr.no_rekening, mr.nama_bank
    ORDER BY mr.nama_bank, mr.no_rekening
";

// Also keep individual rekening list for form dropdown
$sql_rekening_individual = "
    SELECT mr.*, c.nama_cabang 
    FROM master_rekening_cabang mr
    JOIN cabang c ON mr.kode_cabang = c.kode_cabang
    WHERE mr.status = 'active' 
    ORDER BY c.nama_cabang, mr.nama_bank
";
$stmt_rekening = $pdo->query($sql_rekening);
$rekening_list = $stmt_rekening->fetchAll(PDO::FETCH_ASSOC);

$stmt_rekening_individual = $pdo->query($sql_rekening_individual);
$rekening_individual_list = $stmt_rekening_individual->fetchAll(PDO::FETCH_ASSOC);

// Query results ready for dropdown display

// Handle detail view for closing transactions
$detail_view = null;
$transaksi_detail = [];
$closing_info = [];
if (isset($_GET['detail_id'])) {
    $detail_id = $_GET['detail_id'];
    
    $sql_detail = "SELECT sk.*, u.nama_karyawan FROM setoran_keuangan sk 
                   LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan 
                   WHERE sk.id = ?";
    $stmt_detail = $pdo->prepare($sql_detail);
    $stmt_detail->execute([$detail_id]);
    $detail_view = $stmt_detail->fetch(PDO::FETCH_ASSOC);

    if ($detail_view) {
        $transaksi_detail = getClosingTransactionDetails($pdo, $detail_view['kode_setoran']);
        $closing_info = getClosingAggregatedInfo($pdo, $detail_view['kode_setoran']);
        
        $sql_bank = "SELECT sb.*, u.nama_karyawan as created_by_name 
                     FROM setoran_ke_bank sb 
                     JOIN setoran_ke_bank_detail sbd ON sb.id = sbd.setoran_ke_bank_id
                     JOIN users u ON sb.created_by = u.kode_karyawan
                     WHERE sbd.setoran_keuangan_id = ?";
        $stmt_bank = $pdo->prepare($sql_bank);
        $stmt_bank->execute([$detail_id]);
        $bank_detail = $stmt_bank->fetch(PDO::FETCH_ASSOC);
        
        if ($bank_detail) {
            $detail_view['bank_info'] = $bank_detail;
        }
    }
}
// Handle bank detail view for closing report
$bank_detail_view = null;
$closing_detail = [];
$all_closing_detail = [];
if (isset($_GET['bank_detail_id'])) {
    $bank_detail_id = $_GET['bank_detail_id'];
    
    $sql_bank_detail = "SELECT sb.*, u.nama_karyawan as created_by_name 
                       FROM setoran_ke_bank sb 
                       LEFT JOIN users u ON sb.created_by = u.kode_karyawan 
                       WHERE sb.id = ?";
    $stmt_bank_detail = $pdo->prepare($sql_bank_detail);
    $stmt_bank_detail->execute([$bank_detail_id]);
    $bank_detail_view = $stmt_bank_detail->fetch(PDO::FETCH_ASSOC);

    if ($bank_detail_view) {
        // Get all setoran details grouped by cabang dengan closing info (untuk ringkasan)
        $sql_closing = "SELECT 
                           sk.kode_cabang,
                           c.nama_cabang,
                           COUNT(sk.id) as total_setoran,
                           SUM(sk.jumlah_diterima) as total_nominal,
                           GROUP_CONCAT(sk.kode_setoran ORDER BY sk.tanggal_setoran) as kode_setoran_list,
                           MIN(sk.tanggal_setoran) as tanggal_awal,
                           MAX(sk.tanggal_setoran) as tanggal_akhir,
                           SUM(CASE WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' 
                                    OR EXISTS (
                                        SELECT 1 FROM pemasukan_kasir pk 
                                        WHERE pk.nomor_transaksi_closing = kt.kode_transaksi
                                    ) THEN 1 ELSE 0 END) as total_closing_transactions
                       FROM setoran_ke_bank_detail sbd
                       JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
                       JOIN cabang c ON sk.kode_cabang = c.kode_cabang
                       LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
                       WHERE sbd.setoran_ke_bank_id = ?
                       GROUP BY sk.kode_cabang, c.nama_cabang
                       ORDER BY c.nama_cabang";
        $stmt_closing = $pdo->prepare($sql_closing);
        $stmt_closing->execute([$bank_detail_id]);
        $closing_detail = $stmt_closing->fetchAll(PDO::FETCH_ASSOC);

        // Ambil keseluruhan detail transaksi (lintas cabang) untuk ditampilkan sekaligus
        $sql_all_detail = "SELECT 
                                c.nama_cabang,
                                sk.kode_setoran,
                                sk.tanggal_setoran,
                                kt.kode_transaksi,
                                kt.tanggal_transaksi,
                                kt.setoran_real,
                                CASE 
                                    WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' THEN 'DARI CLOSING'
                                    WHEN EXISTS (
                                        SELECT 1 FROM pemasukan_kasir pk2 
                                        WHERE pk2.nomor_transaksi_closing = kt.kode_transaksi
                                    ) THEN 'DARI CLOSING'
                                    ELSE 'TRANSAKSI BIASA'
                                END as jenis_transaksi
                           FROM setoran_ke_bank_detail sbd
                           JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
                           JOIN cabang c ON sk.kode_cabang = c.kode_cabang
                           LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
                           WHERE sbd.setoran_ke_bank_id = ?
                           ORDER BY sk.tanggal_setoran, kt.tanggal_transaksi";
        $stmt_all = $pdo->prepare($sql_all_detail);
        $stmt_all->execute([$bank_detail_id]);
        $all_closing_detail = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Handle specific cabang closing detail with enhanced closing info
$cabang_closing_detail = [];
if (isset($_GET['cabang_closing']) && isset($_GET['bank_detail_id'])) {
    $cabang_name = $_GET['cabang_closing'];
    $bank_detail_id = $_GET['bank_detail_id'];
    
    $sql_cabang_detail = "SELECT 
                             sk.*,
                             kt.kode_transaksi,
                             kt.tanggal_transaksi,
                             kt.setoran_real,
                             kt.deposit_status,
                             CASE 
                                 WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' THEN 'DARI CLOSING'
                                 WHEN EXISTS (
                                     SELECT 1 FROM pemasukan_kasir pk 
                                     WHERE pk.nomor_transaksi_closing = kt.kode_transaksi
                                 ) THEN 'DARI CLOSING'
                                 ELSE 'TRANSAKSI BIASA'
                             END as jenis_transaksi
                         FROM setoran_ke_bank_detail sbd
                         JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id
                         JOIN cabang c ON sk.kode_cabang = c.kode_cabang
                         LEFT JOIN kasir_transactions kt ON sk.kode_setoran = kt.kode_setoran
                         WHERE sbd.setoran_ke_bank_id = ? AND c.nama_cabang = ?
                         ORDER BY 
                             CASE 
                                 WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' THEN 0
                                 WHEN EXISTS (
                                     SELECT 1 FROM pemasukan_kasir pk2 
                                     WHERE pk2.nomor_transaksi_closing = kt.kode_transaksi
                                 ) THEN 0
                                 ELSE 1
                             END,
                             sk.tanggal_setoran, kt.tanggal_transaksi";
    $stmt_cabang_detail = $pdo->prepare($sql_cabang_detail);
    $stmt_cabang_detail->execute([$bank_detail_id, $cabang_name]);
    $cabang_closing_detail = $stmt_cabang_detail->fetchAll(PDO::FETCH_ASSOC);
}

// PERBAIKAN: Handle validation modal for individual transactions dengan info closing gabungan
$transaksi_detail = null;
if (isset($_GET['validate_id'])) {
    $sql_detail = "SELECT kt.*, sk.nama_cabang, sk.tanggal_setoran, sk.nama_pengantar, 
                          COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan,
                          CASE 
                              WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' THEN 'DARI CLOSING'
                              WHEN EXISTS (
                                  SELECT 1 FROM pemasukan_kasir pk 
                                  WHERE pk.nomor_transaksi_closing = kt.kode_transaksi
                              ) THEN 'DARI CLOSING'
                              ELSE 'TRANSAKSI BIASA'
                          END as jenis_transaksi,
                          -- TAMBAHAN: Informasi closing gabungan
                          pk.jumlah as jumlah_pemasukan_closing,
                          pk.keterangan_transaksi as keterangan_closing,
                          (
                              SELECT COALESCE(SUM(pk2.jumlah), 0) 
                              FROM pemasukan_kasir pk2 
                              WHERE pk2.nomor_transaksi_closing = kt.kode_transaksi
                          ) as total_closing_borrowed
                   FROM kasir_transactions kt
                   LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
                   LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan 
                   LEFT JOIN pemasukan_kasir pk ON pk.nomor_transaksi_closing = kt.kode_transaksi
                   WHERE kt.kode_transaksi = ? AND kt.deposit_status = 'Diterima Staff Keuangan'";
    $stmt_detail = $pdo->prepare($sql_detail);
    $stmt_detail->execute([$_GET['validate_id']]);
    $transaksi_detail = $stmt_detail->fetch(PDO::FETCH_ASSOC);
    
    // Get additional closing info if this is a closing transaction
    if ($transaksi_detail && $transaksi_detail['jenis_transaksi'] == 'DARI CLOSING') {
        $transaksi_detail['closing_info'] = getClosingAggregatedInfo($pdo, $transaksi_detail['kode_setoran']);
    }
}

// PERBAIKAN: Handle edit selisih modal for individual transactions dengan info closing gabungan
$edit_selisih_detail = null;
if (isset($_GET['edit_selisih_id'])) {
    $sql_detail = "SELECT kt.*, sk.nama_cabang, sk.tanggal_setoran, sk.nama_pengantar, 
                          COALESCE(u.nama_karyawan, 'Unknown User') AS nama_karyawan,
                          CASE 
                              WHEN kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' THEN 'DARI CLOSING'
                              WHEN EXISTS (
                                  SELECT 1 FROM pemasukan_kasir pk 
                                  WHERE pk.nomor_transaksi_closing = kt.kode_transaksi
                              ) THEN 'DARI CLOSING'
                              ELSE 'TRANSAKSI BIASA'
                          END as jenis_transaksi,
                          -- TAMBAHAN: Informasi closing gabungan
                          pk.jumlah as jumlah_pemasukan_closing,
                          pk.keterangan_transaksi as keterangan_closing,
                          (
                              SELECT COALESCE(SUM(pk2.jumlah), 0) 
                              FROM pemasukan_kasir pk2 
                              WHERE pk2.nomor_transaksi_closing = kt.kode_transaksi
                          ) as total_closing_borrowed
                   FROM kasir_transactions kt
                   LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran
                   LEFT JOIN users u ON sk.kode_karyawan = u.kode_karyawan 
                   LEFT JOIN pemasukan_kasir pk ON pk.nomor_transaksi_closing = kt.kode_transaksi
                   WHERE kt.kode_transaksi = ? AND kt.deposit_status = 'Validasi Keuangan SELISIH'";
    $stmt_detail = $pdo->prepare($sql_detail);
    $stmt_detail->execute([$_GET['edit_selisih_id']]);
    $edit_selisih_detail = $stmt_detail->fetch(PDO::FETCH_ASSOC);
    
    // Get additional closing info if this is a closing transaction
    if ($edit_selisih_detail && $edit_selisih_detail['jenis_transaksi'] == 'DARI CLOSING') {
        $edit_selisih_detail['closing_info'] = getClosingAggregatedInfo($pdo, $edit_selisih_detail['kode_setoran']);
    }
}

function formatRupiah($angka) {
    if ($angka === null || $angka === '') {
        return 'Rp 0';
    }
    return 'Rp ' . number_format((float)$angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Setoran Keuangan Pusat</title>
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
    --closing-color: #9c27b0; /* Purple for closing transactions */
}

body {
    font-family: 'Inter', sans-serif;
    background: var(--background-light);
    color: var(--text-dark);
    display: flex;
    min-height: 100vh;
}

.main-content.fullscreen {
    margin-left: 0;
    width: 100%;
}

.sidebar.hidden {
    transform: translateX(-100%);
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

.stats-card.info {
    border-left-color: var(--info-color);
}

.stats-card.danger {
    border-left-color: var(--danger-color);
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

.stats-card.info .stats-icon {
    color: var(--info-color);
}

.stats-card.danger .stats-icon {
    color: var(--danger-color);
}

/* Receipt card styles - Updated for simple format */
.receipt-card {
    background: white;
    border: 2px dashed var(--border-color);
    border-radius: 12px;
    padding: 24px;
    margin: 20px 0;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.receipt-header {
    text-align: center;
    border-bottom: 1px solid var(--border-color);
    padding-bottom: 15px;
    margin-bottom: 20px;
}

.receipt-title {
    font-size: 20px;
    font-weight: bold;
    color: var(--text-dark);
    margin-bottom: 5px;
}

.receipt-subtitle {
    color: var(--text-muted);
    font-size: 14px;
}

.receipt-body {
    margin-bottom: 20px;
}

.receipt-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px dotted var(--border-color);
}

.receipt-row:last-child {
    border-bottom: none;
    font-weight: bold;
    margin-top: 10px;
    padding-top: 15px;
    border-top: 2px solid var(--border-color);
}

.receipt-footer {
    text-align: center;
    padding-top: 15px;
    border-top: 1px solid var(--border-color);
    color: var(--text-muted);
    font-size: 12px;
}

.nav-tabs {
    background: white;
    border-radius: 16px 16px 0 0;
    padding: 0;
    border: 1px solid var(--border-color);
    border-bottom: none;
    margin-bottom: 0;
    display: flex;
    overflow-x: auto;
}

.nav-tabs .nav-item {
    margin-bottom: 0;
}

.nav-tabs .nav-link {
    padding: 16px 24px;
    border: none;
    background: transparent;
    color: var(--text-muted);
    font-weight: 500;
    transition: all 0.3s ease;
    text-decoration: none;
    border-radius: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}

.nav-tabs .nav-link:hover {
    background: var(--background-light);
    color: var(--text-dark);
}

.nav-tabs .nav-link.active {
    background: var(--primary-color);
    color: white;
    position: relative;
}

.nav-tabs .nav-link.active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--primary-color);
}

.badge {
    background: rgba(255,255,255,0.2);
    color: inherit;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 5px;
}

.nav-tabs .nav-link:not(.active) .badge {
    background: var(--background-light);
    color: var(--text-dark);
}

.filter-card {
    background: white;
    border-radius: 0 0 16px 16px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid var(--border-color);
    border-top: none;
    margin-bottom: 24px;
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

.form-label {
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

.bulk-actions {
    background: var(--background-light);
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    border: 1px solid var(--border-color);
}

/* ===== PERBAIKAN: Enhanced table container untuk SEMUA tab ===== */
.table-container {
    overflow: hidden;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 20px;
    border: 1px solid var(--border-color);
    background: white;
    position: relative;
}

.table-wrapper {
    overflow-x: auto !important; /* PENTING: Force scroll horizontal untuk semua tabel */
    overflow-y: visible;
    max-width: 100%;
    position: relative;
    /* Enhanced scroll bar yang visible untuk SEMUA tab */
    scrollbar-width: auto;
    scrollbar-color: #007bff #f8f9fa;
}

/* Enhanced WebKit scrollbar styling - BERLAKU UNTUK SEMUA TAB */
.table-wrapper::-webkit-scrollbar {
    height: 12px !important; /* Force tinggi scrollbar */
    background: #f8f9fa;
    border-radius: 6px;
}

.table-wrapper::-webkit-scrollbar-track {
    background: #f8f9fa;
    border-radius: 6px;
    border: 1px solid #e9ecef;
}

.table-wrapper::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #007bff, #0056b3);
    border-radius: 6px;
    border: 1px solid #0056b3;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
}

.table-wrapper::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #0056b3, #004494);
    cursor: pointer;
}

.table-wrapper::-webkit-scrollbar-thumb:active {
    background: #004494;
}

/* Scroll indicators untuk SEMUA tab */
.table-container::before,
.table-container::after {
    content: '';
    position: absolute;
    top: 0;
    bottom: 12px;
    width: 20px;
    pointer-events: none;
    z-index: 10;
    transition: opacity 0.3s ease;
}

.table-container::before {
    left: 0;
    background: linear-gradient(to right, rgba(255,255,255,0.9), transparent);
    opacity: 0;
}

.table-container::after {
    right: 0;
    background: linear-gradient(to left, rgba(255,255,255,0.9), transparent);
    opacity: 1;
}

.table-container.scrolled-left::before {
    opacity: 1;
}

.table-container.scrolled-right::after {
    opacity: 0;
}

/* ===== PERBAIKAN: Table styling untuk SEMUA tab ===== */
.table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
    /* PENTING: Lebar minimum berbeda per tab */
    min-width: 1200px; /* Default untuk tab terima */
    background: white;
    position: relative;
}

/* PERBAIKAN: Lebar tabel spesifik per tab */
/* Tab Validasi & Edit Selisih - lebih lebar karena banyak kolom */
.tab-validasi .table,
.tab-validasi_selisih .table {
    min-width: 1800px !important; /* Lebih lebar untuk kolom validasi */
}

/* Tab Setor Bank - sedang */
.tab-setor_bank .table {
    min-width: 1400px !important;
}

/* Enhanced horizontal scrollbar untuk tab setor bank */
.tab-setor_bank .table-wrapper,
#setorBankTableWrapper {
    overflow-x: scroll !important;
    overflow-y: visible !important;
    scrollbar-width: thick !important;
    scrollbar-color: #dc3545 #f8f9fa !important;
}

.tab-setor_bank .table-wrapper::-webkit-scrollbar,
#setorBankTableWrapper::-webkit-scrollbar {
    height: 20px !important;
    background: #f8f9fa !important;
    border-radius: 10px !important;
    border: 2px solid #dee2e6 !important;
    display: block !important;
    -webkit-appearance: none !important;
}

.tab-setor_bank .table-wrapper::-webkit-scrollbar-track,
#setorBankTableWrapper::-webkit-scrollbar-track {
    background: #e9ecef !important;
    border-radius: 10px !important;
    border: 1px solid #ced4da !important;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.2) !important;
}

.tab-setor_bank .table-wrapper::-webkit-scrollbar-thumb,
#setorBankTableWrapper::-webkit-scrollbar-thumb {
    background: #dc3545 !important;
    border-radius: 10px !important;
    border: 3px solid #fff !important;
    box-shadow: 0 4px 8px rgba(220,53,69,0.5) !important;
    min-width: 60px !important;
    -webkit-appearance: none !important;
}

.tab-setor_bank .table-wrapper::-webkit-scrollbar-thumb:hover,
#setorBankTableWrapper::-webkit-scrollbar-thumb:hover {
    background: #c82333 !important;
    cursor: grab !important;
    transform: scale(1.1) !important;
    transition: all 0.3s ease !important;
    box-shadow: 0 6px 12px rgba(220,53,69,0.7) !important;
}

.tab-setor_bank .table-wrapper::-webkit-scrollbar-thumb:active,
#setorBankTableWrapper::-webkit-scrollbar-thumb:active {
    background: #a71e2a !important;
    cursor: grabbing !important;
    transform: scale(1.05) !important;
}

/* Tambahan untuk memastikan scrollbar selalu terlihat */
.tab-setor_bank .table-container {
    position: relative;
    border: 2px solid #007bff;
    border-radius: 12px;
    background: #fff;
    overflow: visible;
}

.tab-setor_bank .table {
    min-width: 1500px !important;
    margin-bottom: 20px !important;
}

/* Force scrollbar visibility with CSS injection */
body.tab-setor_bank #setorBankTableWrapper {
    overflow-x: scroll !important;
    -webkit-overflow-scrolling: touch !important;
}

/* Tab Bank History - dengan text wrapping dan horizontal scroll */
.tab-bank_history .table {
    min-width: 1600px !important;
    table-layout: fixed !important;
}

.tab-bank_history .table td {
    word-wrap: break-word !important;
    overflow-wrap: break-word !important;
    white-space: normal !important;
    vertical-align: top !important;
    padding: 8px !important;
}

.tab-bank_history .table th {
    word-wrap: break-word !important;
    overflow-wrap: break-word !important;
    white-space: normal !important;
    vertical-align: top !important;
    padding: 8px !important;
}

.tab-bank_history .table code {
    word-break: break-all !important;
    white-space: normal !important;
}

/* Enhanced horizontal scrollbar untuk tab bank history */
.tab-bank_history .table-wrapper,
#bankHistoryTableWrapper {
    overflow-x: scroll !important;
    overflow-y: visible !important;
    scrollbar-width: thick !important;
    scrollbar-color: #dc3545 #f8f9fa !important;
}

.tab-bank_history .table-wrapper::-webkit-scrollbar,
#bankHistoryTableWrapper::-webkit-scrollbar {
    height: 18px !important;
    background: #f8f9fa !important;
    border-radius: 9px !important;
    border: 2px solid #dee2e6 !important;
    display: block !important;
    -webkit-appearance: none !important;
}

.tab-bank_history .table-wrapper::-webkit-scrollbar-track,
#bankHistoryTableWrapper::-webkit-scrollbar-track {
    background: #e9ecef !important;
    border-radius: 9px !important;
    border: 1px solid #ced4da !important;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.2) !important;
}

.tab-bank_history .table-wrapper::-webkit-scrollbar-thumb,
#bankHistoryTableWrapper::-webkit-scrollbar-thumb {
    background: #dc3545 !important;
    border-radius: 9px !important;
    border: 2px solid #fff !important;
    box-shadow: 0 4px 8px rgba(220,53,69,0.5) !important;
    min-width: 60px !important;
    -webkit-appearance: none !important;
}

.tab-bank_history .table-wrapper::-webkit-scrollbar-thumb:hover,
#bankHistoryTableWrapper::-webkit-scrollbar-thumb:hover {
    background: #c82333 !important;
    cursor: grab !important;
    transform: scale(1.05) !important;
    transition: all 0.3s ease !important;
    box-shadow: 0 6px 12px rgba(220,53,69,0.7) !important;
}

.tab-bank_history .table-wrapper::-webkit-scrollbar-thumb:active,
#bankHistoryTableWrapper::-webkit-scrollbar-thumb:active {
    background: #a71e2a !important;
    cursor: grabbing !important;
    transform: scale(1.02) !important;
}

/* Tambahan untuk memastikan scrollbar selalu terlihat */
.tab-bank_history .table-container {
    position: relative;
    border: 2px solid #007bff;
    border-radius: 12px;
    background: #fff;
    overflow: visible;
}

/* Fixed styling untuk grand total row yang tidak boleh hilang */
.grand-total-row-fixed,
#grandTotalRow {
    background: #28a745 !important;
    color: white !important;
    font-weight: bold !important;
    font-size: 16px !important;
    border: 2px solid #007bff !important;
    position: relative !important;
    z-index: 999 !important;
    display: table-row !important;
    visibility: visible !important;
    opacity: 1 !important;
}

.grand-total-row-fixed td,
#grandTotalRow td {
    background: #28a745 !important;
    color: white !important;
    font-weight: bold !important;
    font-size: 16px !important;
    display: table-cell !important;
    visibility: visible !important;
    opacity: 1 !important;
}

/* Sticky header dengan shadow untuk SEMUA tab */
.table th {
    background: var(--background-light);
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: var(--text-dark);
    font-size: 13px;
    border-bottom: 2px solid var(--border-color);
    white-space: nowrap;
    position: sticky;
    top: 0;
    z-index: 20;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* Column width specifications untuk validasi dan edit selisih */
.tab-validasi .table th:nth-child(1),
.tab-validasi_selisih .table th:nth-child(1) { min-width: 100px; } /* Tanggal */
.tab-validasi .table th:nth-child(2),
.tab-validasi_selisih .table th:nth-child(2) { min-width: 180px; } /* Kode Transaksi */
.tab-validasi .table th:nth-child(3),
.tab-validasi_selisih .table th:nth-child(3) { min-width: 120px; } /* Jenis */  
.tab-validasi .table th:nth-child(4),
.tab-validasi_selisih .table th:nth-child(4) { min-width: 150px; } /* Kode Setoran */
.tab-validasi .table th:nth-child(5),
.tab-validasi_selisih .table th:nth-child(5) { min-width: 120px; } /* Cabang */
.tab-validasi .table th:nth-child(6),
.tab-validasi_selisih .table th:nth-child(6) { min-width: 120px; } /* Kasir */
.tab-validasi .table th:nth-child(7),
.tab-validasi_selisih .table th:nth-child(7) { min-width: 140px; } /* Nominal */
.tab-validasi .table th:nth-child(8),
.tab-validasi_selisih .table th:nth-child(8) { min-width: 200px; } /* Info Closing */
.tab-validasi .table th:nth-child(9),
.tab-validasi_selisih .table th:nth-child(9) { min-width: 120px; } /* Status */
.tab-validasi .table th:nth-child(10),
.tab-validasi_selisih .table th:nth-child(10) { min-width: 180px; } /* Info tambahan */
.tab-validasi .table th:nth-child(11),
.tab-validasi_selisih .table th:nth-child(11) { min-width: 100px; } /* Aksi */

.table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
    font-size: 14px;
    vertical-align: middle;
    white-space: nowrap;
}

.table tbody tr:hover {
    background: rgba(0,123,255,0.05);
}

.table tbody tr:last-child td {
    border-bottom: none;
}

.table-enhanced {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 20px;
    border: 1px solid var(--border-color);
}

.table-enhanced .table {
    margin: 0;
}

.table-enhanced .table th {
    background: linear-gradient(135deg, var(--primary-color), #0056b3);
    color: white;
    font-weight: 600;
    border: none;
    box-shadow: 0 2px 8px rgba(0,123,255,0.3);
}

/* Enhanced table focus state */
.table-wrapper:focus-within {
    box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
    border-radius: 12px;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.status-badge.bg-warning {
    background: rgba(255,193,7,0.2);
    color: #856404;
}

.status-badge.bg-info {
    background: rgba(23,162,184,0.2);
    color: #0c5460;
}

.status-badge.bg-success {
    background: rgba(40,167,69,0.2);
    color: #155724;
}

.status-badge.bg-primary {
    background: rgba(0,123,255,0.2);
    color: #004085;
}

.status-badge.bg-danger {
    background: rgba(220,53,69,0.2);
    color: #721c24;
}

/* New styles for closing transactions */
.status-badge.bg-closing {
    background: rgba(156,39,176,0.2);
    color: #4a148c;
}

/* Closing transaction specific scrolling */
.closing-transaction {
    background: rgba(156,39,176,0.05) !important;
    border-left: 4px solid var(--closing-color) !important;
    transition: all 0.3s ease;
}

.closing-transaction:hover {
    background: rgba(156,39,176,0.15) !important;
    transform: translateX(2px);
}

.closing-info-badge {
    background: var(--closing-color);
    color: white;
    padding: 2px 6px;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 600;
    margin-left: 5px;
}

.transaction-type-indicator {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    flex-wrap: wrap;
}

.action-buttons {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
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
    z-index: 9999;
    overflow-y: auto;
    padding: 20px;
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
    max-width: 800px;
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
    flex: 1;
}

.btn-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--text-muted);
    padding: 0;
    margin-left: 10px;
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

.validation-summary {
    background: var(--background-light);
    padding: 15px;
    border-radius: 12px;
    margin-bottom: 20px;
    border: 1px solid var(--border-color);
}

/* Enhanced styles for closing transaction validation */
.closing-validation-info {
    background: linear-gradient(135deg, rgba(156,39,176,0.1), rgba(156,39,176,0.05));
    border: 1px solid rgba(156,39,176,0.2);
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
}

.closing-validation-info h6 {
    color: var(--closing-color);
    font-weight: 600;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.closing-aggregation {
    background: white;
    border: 1px solid rgba(156,39,176,0.2);
    border-radius: 8px;
    padding: 12px;
    margin-top: 10px;
}

.closing-summary-item {
    display: flex;
    justify-content: space-between;
    padding: 4px 0;
    font-size: 13px;
}

.closing-summary-item.total {
    font-weight: bold;
    border-top: 1px solid var(--border-color);
    margin-top: 8px;
    padding-top: 8px;
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

.workflow-info {
    background: linear-gradient(135deg, rgba(23,162,184,0.1), rgba(23,162,184,0.05));
    border: 1px solid rgba(23,162,184,0.2);
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
}
.workflow-info h6 {
    color: var(--info-color);
    font-weight: 600;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}

.workflow-info p {
    margin: 0;
    color: var(--text-dark);
    font-size: 14px;
}

.required {
    color: var(--danger-color);
}

.closing-summary {
    background: linear-gradient(135deg, #f8f9fa, #e9ecef);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid var(--border-color);
}

.closing-summary h4 {
    color: var(--text-dark);
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.closing-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.closing-item {
    background: white;
    padding: 15px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.closing-label {
    font-size: 12px;
    color: var(--text-muted);
    text-transform: uppercase;
    font-weight: 600;
    margin-bottom: 5px;
}

.closing-value {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-dark);
}

.closing-value.amount {
    color: var(--success-color);
    font-size: 18px;
}

/* PERBAIKAN: Scroll hint untuk user experience - SEMUA TAB */
.scroll-hint {
    position: absolute;
    bottom: 15px;
    right: 20px;
    background: rgba(0,123,255,0.9);
    color: white;
    padding: 5px 10px;
    border-radius: 15px;
    font-size: 11px;
    font-weight: 500;
    z-index: 15;
    animation: fadeInOut 3s ease-in-out;
    pointer-events: none;
}

.scroll-progress-container {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: rgba(0,0,0,0.1);
    z-index: 10;
}

.scroll-progress {
    height: 100%;
    background: linear-gradient(90deg, var(--primary-color), var(--info-color));
    width: 0%;
    transition: width 0.3s ease;
}

.table-scroll-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 15;
    background: rgba(0,123,255,0.9);
    color: white;
    border: none;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    transition: all 0.3s ease;
    opacity: 0;
}

.table-scroll-left {
    left: 5px;
}

.table-scroll-right {
    right: 5px;
}

.closing-borrowed-info {
    background: rgba(156,39,176,0.1);
    border: 1px solid rgba(156,39,176,0.2);
    padding: 10px;
    border-radius: 8px;
    margin: 10px 0;
    font-size: 12px;
    color: var(--closing-color);
}

.expected-amount {
    font-weight: 600;
    color: var(--warning-color);
}

.borrowed-amount {
    font-weight: 600;
    color: var(--danger-color);
}

.export-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.no-setoran-message {
    background: linear-gradient(135deg, rgba(255,193,7,0.1), rgba(255,193,7,0.05));
    border: 1px solid rgba(255,193,7,0.2);
    padding: 20px;
    border-radius: 12px;
    text-align: center;
    margin-bottom: 20px;
}

.no-setoran-message h6 {
    color: var(--warning-color);
    font-weight: 600;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

/* Hide/show sidebar controls */
.sidebar-toggle {
    position: fixed;
    top: 20px;
    left: 20px;
    z-index: 1100;
    background: var(--primary-color);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 8px 12px;
    cursor: pointer;
    display: none;
}

.sidebar-toggle.show {
    display: block;
}

/* PERBAIKAN: Responsive styles dengan scroll yang lebih baik - SEMUA TAB */
@media (max-width: 768px) {
    
    .sidebar.active {
        transform: translateX(0);
    }
    
    .form-inline {
        flex-direction: column;
        align-items: stretch;
    }
    
    .form-group {
        width: 100%;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .detail-grid {
        grid-template-columns: 1fr;
    }
    
    .closing-grid {
        grid-template-columns: 1fr;
    }
    
    .nav-tabs {
        overflow-x: auto;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
    
    .modal-dialog {
        max-width: 95%;
        margin: 10px;
    }
    
    /* PERBAIKAN: Mobile table styles - SEMUA TAB */
    .table-container {
        margin: 0 -20px;
        border-radius: 0;
        border-left: none;
        border-right: none;
    }
    
    .table-wrapper::-webkit-scrollbar {
        height: 8px !important; /* Smaller on mobile */
    }
    
    .table {
        font-size: 12px;
        /* Mobile minimum widths - lebih kecil tapi tetap scrollable */
        min-width: 1000px !important; /* Override semua min-width di mobile */
    }
    
    .tab-validasi .table,
    .tab-validasi_selisih .table {
        min-width: 1400px !important; /* Tetap lebar untuk validasi di mobile */
    }
    
    .table th,
    .table td {
        padding: 8px 10px;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 5px;
    }
    
    .btn-sm {
        width: 100%;
        justify-content: center;
    }
    
    /* Mobile scroll indicators */
    .table-container::before,
    .table-container::after {
        bottom: 8px;
    }
    
    .closing-transaction {
        border-left: 2px solid var(--closing-color) !important;
    }
}

/* Print styles */
@media print {
    body * {
        visibility: hidden;
    }
    .receipt-card, .receipt-card * {
        visibility: visible;
    }
    .receipt-card {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        box-shadow: none;
        border: 2px solid #000;
    }
    .no-print {
        display: none !important;
    }
}

/* CSS animations for closing transactions */
@keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(156,39,176,0.4); }
    70% { box-shadow: 0 0 0 10px rgba(156,39,176,0); }
    100% { box-shadow: 0 0 0 0 rgba(156,39,176,0); }
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

@keyframes slideOut {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(100%); opacity: 0; }
}

@keyframes fadeOut {
    from { opacity: 1; }
    to { opacity: 0; }
}

@keyframes fadeInOut {
    0%, 100% { opacity: 0; }
    50% { opacity: 1; }
}

.closing-info-badge {
    animation: pulse 2s infinite;
}
    </style>
</head>
<body class="tab-<?php echo $tab; ?>">
<!-- Sidebar Toggle Button -->
<button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()">
    <i class="fas fa-bars"></i>
</button>

<?php include 'includes/sidebar.php'; ?>
<div class="main-content" id="mainContent">
    <div class="user-profile">
        <div class="user-avatar"><?php echo strtoupper(substr($username, 0, 1)); ?></div>
        <div>
            <strong><?php echo htmlspecialchars($username); ?></strong>
            <p style="color: var(--text-muted); font-size: 12px;">Staff Keuangan Pusat</p>
        </div>
    </div>

    <div class="welcome-card">
        <h1><i class="fas fa-hand-holding-usd"></i> Manajemen Setoran Keuangan Pusat</h1>
        <p style="color: var(--text-muted); margin-bottom: 0;">Kelola penerimaan, validasi, dan penyetoran dana dari seluruh cabang FIT MOTOR dengan dukungan khusus untuk transaksi closing</p>
        <div class="info-tags">
            <div class="info-tag"><i class="fas fa-user"></i> User: <?php echo htmlspecialchars($username); ?></div>
            <div class="info-tag"><i class="fas fa-shield-alt"></i> Role: Staff Keuangan Pusat</div>
            <div class="info-tag"><i class="fas fa-calendar-day"></i> Tanggal: <?php echo date('d M Y'); ?></div>
            <div class="info-tag"><i class="fas fa-sync-alt"></i> Closing Support: Active</div>
        </div>
    </div>

    <!-- Enhanced Statistics Grid with Closing Info -->
    <div class="stats-grid">
        <div class="stats-card info">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Sedang Dibawa Kurir</h4>
                    <p class="stats-number"><?php 
                        $stmt_count = $pdo->query("SELECT COUNT(*) FROM setoran_keuangan WHERE status = 'Sedang Dibawa Kurir'");
                        echo $stmt_count->fetchColumn();
                    ?></p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-truck"></i>
                </div>
            </div>
        </div>
        <div class="stats-card warning">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Perlu Validasi</h4>
                    <p class="stats-number"><?php 
                        $stmt_count = $pdo->query("SELECT COUNT(*) FROM kasir_transactions WHERE deposit_status = 'Diterima Staff Keuangan'");
                        $total_validasi = $stmt_count->fetchColumn();
                        echo $total_validasi;
                    ?></p>
                    <small style="font-size: 10px; color: var(--text-muted);">
                        <?php 
                        $stmt_closing = $pdo->query("SELECT COUNT(*) FROM kasir_transactions WHERE deposit_status = 'Diterima Staff Keuangan' AND (kode_transaksi LIKE '%CLOSING%' OR kode_transaksi LIKE '%CLO%' OR EXISTS (SELECT 1 FROM pemasukan_kasir pk WHERE pk.nomor_transaksi_closing = kasir_transactions.kode_transaksi))");
                        $closing_count = $stmt_closing->fetchColumn();
                        echo $closing_count > 0 ? "($closing_count dari closing)" : "";
                        ?>
                    </small>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-search"></i>
                </div>
            </div>
        </div>
        <div class="stats-card danger">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Ada Selisih</h4>
                    <p class="stats-number"><?php 
                        $stmt_count = $pdo->query("SELECT COUNT(*) FROM kasir_transactions WHERE deposit_status = 'Validasi Keuangan SELISIH'");
                        $total_selisih = $stmt_count->fetchColumn();
                        echo $total_selisih;
                    ?></p>
                    <small style="font-size: 10px; color: var(--text-muted);">
                        <?php 
                        $stmt_closing_selisih = $pdo->query("SELECT COUNT(*) FROM kasir_transactions WHERE deposit_status = 'Validasi Keuangan SELISIH' AND (kode_transaksi LIKE '%CLOSING%' OR kode_transaksi LIKE '%CLO%' OR EXISTS (SELECT 1 FROM pemasukan_kasir pk WHERE pk.nomor_transaksi_closing = kasir_transactions.kode_transaksi))");
                        $closing_selisih_count = $stmt_closing_selisih->fetchColumn();
                        echo $closing_selisih_count > 0 ? "($closing_selisih_count dari closing)" : "";
                        ?>
                    </small>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>
        </div>
        <div class="stats-card warning">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Dikembalikan ke CS</h4>
                    <p class="stats-number"><?php 
                        $stmt_count = $pdo->query("SELECT COUNT(*) FROM kasir_transactions WHERE deposit_status = 'Dikembalikan ke CS'");
                        $total_dikembalikan = $stmt_count->fetchColumn();
                        echo $total_dikembalikan;
                    ?></p>
                    <small style="font-size: 10px; color: var(--text-muted);">
                        <?php 
                        $stmt_closing_dikembalikan = $pdo->query("SELECT COUNT(*) FROM kasir_transactions WHERE deposit_status = 'Dikembalikan ke CS' AND (kode_transaksi LIKE '%CLOSING%' OR kode_transaksi LIKE '%CLO%' OR EXISTS (SELECT 1 FROM pemasukan_kasir pk WHERE pk.nomor_transaksi_closing = kasir_transactions.kode_transaksi))");
                        $closing_dikembalikan_count = $stmt_closing_dikembalikan->fetchColumn();
                        echo $closing_dikembalikan_count > 0 ? "($closing_dikembalikan_count dari closing)" : "";
                        ?>
                    </small>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-undo"></i>
                </div>
            </div>
        </div>
        <div class="stats-card success">
            <div class="stats-content">
                <div class="stats-info">
                    <h4>Transaksi Siap Setor Bank</h4>
                    <p class="stats-number"><?php 
                        $stmt_count = $pdo->query("SELECT COUNT(*) FROM kasir_transactions kt LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran WHERE sk.status = 'Validasi Keuangan OK' AND kt.status = 'end proses' AND kt.deposit_status = 'Validasi Keuangan OK'");
                        echo $stmt_count->fetchColumn();
                    ?></p>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-university"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Receipt Card for Received Deposits -->
    <?php if (isset($_SESSION['received_setorans']) && !empty($_SESSION['received_setorans'])): ?>
    <div class="receipt-card">
        <div class="receipt-header">
            <div class="receipt-title">BUKTI PENERIMAAN SETORAN</div>
            <div class="receipt-subtitle">FIT MOTOR - KEUANGAN PUSAT</div>
        </div>
        <div class="receipt-body">
            <div class="receipt-row">
                <span>Tanggal Penerimaan:</span>
                <span><?php echo date('d/m/Y H:i', strtotime($_SESSION['received_at'])); ?></span>
            </div>
            <div class="receipt-row">
                <span>Diterima Oleh:</span>
                <span><?php echo htmlspecialchars($_SESSION['received_by']); ?></span>
            </div>
            <div class="receipt-row">
                <span>Jumlah Setoran:</span>
                <span><?php echo count($_SESSION['received_setorans']); ?> paket</span>
            </div>
            <hr style="margin: 15px 0;">
            <?php foreach ($_SESSION['received_setorans'] as $setoran): ?>
            <div class="receipt-row">
                <span><?php echo htmlspecialchars($setoran['kode_setoran']); ?> - <?php echo htmlspecialchars($setoran['nama_cabang']); ?></span>
                <span>Diterima</span>
            </div>
            <?php endforeach; ?>
            <div class="receipt-row">
                <span><strong>TOTAL PAKET DITERIMA:</strong></span>
                <span><strong><?php echo count($_SESSION['received_setorans']); ?> paket</strong></span>
            </div>
        </div>
        <div class="receipt-footer">
            <p>Bukti ini merupakan konfirmasi penerimaan setoran dari kurir cabang</p>
            <p>Simpan bukti ini sebagai dokumen penerimaan</p>
            <div class="no-print" style="margin-top: 15px;">
                <a href="export_excel_setoran.php?type=receipt&data=<?php echo base64_encode(json_encode($_SESSION['received_setorans'])); ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <button onclick="closeReceipt()" class="btn btn-secondary btn-sm">
                    <i class="fas fa-times"></i> Tutup
                </button>
            </div>
        </div>
    </div>
    <?php 
        // Clear the session after showing
        unset($_SESSION['received_setorans']);
        unset($_SESSION['received_by']);
        unset($_SESSION['received_at']);
    endif; 
    ?>

    <?php if (isset($message)): ?>
        <div class="alert alert-success show">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger show">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Tab Navigation (Hidden for fullscreen tabs) -->
    <?php if (!in_array($tab, ['bank_history', 'monitoring'])): ?>
    <div class="nav-tabs">
        <div class="nav-item">
            <a class="nav-link <?php echo $tab == 'terima' ? 'active' : ''; ?>" href="?tab=terima">
                <i class="fas fa-download"></i> Terima Setoran 
                <span class="badge"><?php 
                    $stmt_count = $pdo->query("SELECT COUNT(*) FROM setoran_keuangan WHERE status = 'Sedang Dibawa Kurir'");
                    echo $stmt_count->fetchColumn();
                ?></span>
            </a>
        </div>
        <div class="nav-item">
            <a class="nav-link <?php echo $tab == 'validasi' ? 'active' : ''; ?>" href="?tab=validasi">
                <i class="fas fa-search"></i> Validasi Fisik 
                <span class="badge"><?php 
                    $stmt_count = $pdo->query("SELECT COUNT(*) FROM kasir_transactions WHERE deposit_status = 'Diterima Staff Keuangan'");
                    echo $stmt_count->fetchColumn();
                ?></span>
            </a>
        </div>
        <div class="nav-item">
            <a class="nav-link <?php echo $tab == 'validasi_selisih' ? 'active' : ''; ?>" href="?tab=validasi_selisih">
                <i class="fas fa-edit"></i> Edit Selisih 
                <span class="badge"><?php 
                    $stmt_count = $pdo->query("SELECT COUNT(*) FROM kasir_transactions WHERE deposit_status = 'Validasi Keuangan SELISIH'");
                    echo $stmt_count->fetchColumn();
                ?></span>
            </a>
        </div>
        <div class="nav-item">
            <a class="nav-link <?php echo $tab == 'setor_bank' ? 'active' : ''; ?>" href="?tab=setor_bank">
                <i class="fas fa-university"></i> Setor ke Bank 
                <span class="badge"><?php 
                    $stmt_count = $pdo->query("SELECT COUNT(*) FROM kasir_transactions kt LEFT JOIN setoran_keuangan sk ON kt.kode_setoran = sk.kode_setoran WHERE sk.status = 'Validasi Keuangan OK' AND kt.status = 'end proses' AND kt.deposit_status = 'Validasi Keuangan OK'");
                    echo $stmt_count->fetchColumn();
                ?></span>
            </a>
        </div>
        <div class="nav-item">
            <a class="nav-link <?php echo $tab == 'monitoring' ? 'active' : ''; ?>" href="?tab=monitoring">
                <i class="fas fa-chart-line"></i> Monitoring Setoran
                <span class="badge"><?php 
                    $stmt_count = $pdo->query("SELECT COUNT(DISTINCT kt.kode_setoran) FROM kasir_transactions kt WHERE kt.kode_transaksi LIKE '%CLOSING%' OR kt.kode_transaksi LIKE '%CLO%' OR EXISTS (SELECT 1 FROM pemasukan_kasir pk WHERE pk.nomor_transaksi_closing = kt.kode_transaksi)");
                    echo $stmt_count->fetchColumn();
                ?></span>
            </a>
        </div>
        <div class="nav-item">
            <a class="nav-link" href="?tab=bank_history">
                <i class="fas fa-file-alt"></i> Riwayat Setoran Bank
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter Card for Monitoring Tab -->
    <?php if ($tab == 'monitoring'): ?>
    <div class="filter-card">
        <form action="" method="POST" class="form-inline">
            <input type="hidden" name="tab_filter" value="monitoring">
            <div class="form-group">
                <label class="form-label">Tanggal Awal:</label>
                <input type="date" name="tanggal_awal" class="form-control" value="<?php echo htmlspecialchars($tanggal_awal); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Tanggal Akhir:</label>
                <input type="date" name="tanggal_akhir" class="form-control" value="<?php echo htmlspecialchars($tanggal_akhir); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Cabang:</label>
                <select name="cabang" class="form-control">
                    <option value="all">Semua Cabang</option>
                    <?php foreach ($cabang_list as $nama_cabang): ?>
                        <option value="<?php echo htmlspecialchars($nama_cabang); ?>" <?php echo $cabang == $nama_cabang ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($nama_cabang)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Status:</label>
                <select name="status_filter" class="form-control">
                    <option value="all">Semua Status</option>
                    <option value="Sedang Dibawa Kurir" <?php echo ($status_filter == 'Sedang Dibawa Kurir') ? 'selected' : ''; ?>>Sedang Dibawa Kurir</option>
                    <option value="Diterima Staff Keuangan" <?php echo ($status_filter == 'Diterima Staff Keuangan') ? 'selected' : ''; ?>>Diterima Staff Keuangan</option>
                    <option value="Validasi Keuangan OK" <?php echo ($status_filter == 'Validasi Keuangan OK') ? 'selected' : ''; ?>>Validasi Keuangan OK</option>
                    <option value="Validasi Keuangan SELISIH" <?php echo ($status_filter == 'Validasi Keuangan SELISIH') ? 'selected' : ''; ?>>Validasi Keuangan SELISIH</option>
                    <option value="Dikembalikan ke CS" <?php echo ($status_filter == 'Dikembalikan ke CS') ? 'selected' : ''; ?>>Dikembalikan ke CS</option>
                    <option value="Sudah Disetor ke Bank" <?php echo ($status_filter == 'Sudah Disetor ke Bank') ? 'selected' : ''; ?>>Sudah Disetor ke Bank</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Filter
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Filter Card (Hidden for fullscreen tabs) -->
    <?php if (!in_array($tab, ['bank_history', 'monitoring'])): ?>
    <div class="filter-card">
        <form action="" method="POST" class="form-inline">
            <input type="hidden" name="tab_filter" value="<?php echo $tab; ?>">
            <div class="form-group">
                <label class="form-label">Tanggal Awal:</label>
                <input type="date" name="tanggal_awal" class="form-control" value="<?php echo htmlspecialchars($tanggal_awal); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Tanggal Akhir:</label>
                <input type="date" name="tanggal_akhir" class="form-control" value="<?php echo htmlspecialchars($tanggal_akhir); ?>">
            </div>
            <?php if ($tab != 'setor_bank'): ?>
            <div class="form-group">
                <label class="form-label">Cabang:</label>
                <select name="cabang" class="form-control">
                    <option value="all">Semua Cabang</option>
                    <?php foreach ($cabang_list as $nama_cabang): ?>
                        <option value="<?php echo htmlspecialchars($nama_cabang); ?>" <?php echo $cabang == $nama_cabang ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($nama_cabang)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($tab == 'setor_bank'): ?>
            <div class="form-group">
                <label class="form-label">Rekening Cabang:</label>
                <select name="rekening_filter" class="form-control" onchange="filterByCabang(this.value)">
                    <option value="all">Pilih Rekening Cabang</option>
                    <?php foreach ($rekening_list as $rekening): ?>
                        <?php
                        // Parse cabang info to get all cabang names and IDs
                        $cabang_items = explode(';;', $rekening['cabang_info']);
                        $rekening_ids = explode(',', $rekening['rekening_ids']);
                        $cabang_names = array();
                        foreach ($cabang_items as $item) {
                            $parts = explode('|', $item);
                            if (count($parts) == 2) {
                                $cabang_names[] = $parts[0];
                            }
                        }
                        $cabang_display = '(' . implode('-', $cabang_names) . ')';
                        $jenis_badge = $rekening['jenis_rekening'] == 'Mitra' ? ' (MITRA)' : ' (MILIK SENDIRI)';
                        
                        // Use all rekening IDs as comma separated values for the option
                        $all_rekening_ids = $rekening['rekening_ids'];
                        
                        // Format: Nama Bank (No Rek) - (Cabang1-Cabang2) (Jenis)
                        $display_text = $rekening['nama_bank'] . ' (' . $rekening['no_rekening'] . ') - ' . $cabang_display . $jenis_badge;
                        ?>
                        <option value="<?php echo htmlspecialchars($all_rekening_ids); ?>" <?php echo ($rekening_filter !== 'all' && !empty($rekening_filter) && ($rekening_filter == $all_rekening_ids || in_array($rekening_filter, explode(',', $all_rekening_ids)))) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($display_text); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Filter
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Tab Content -->
    <?php if ($tab == 'terima'): ?>
    <div class="content-card">
        <div class="content-header">
            <h3><i class="fas fa-download"></i> Terima Setoran dari CS/Kasir Cabang</h3>
            <div class="export-buttons">
                <a href="export_excel_setoran.php?type=terima&tab=<?php echo $tab; ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a href="export_csv.php?type=terima&tab=<?php echo $tab; ?>" class="btn btn-info btn-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
            </div>
        </div>
        <div class="content-body">
            <div class="workflow-info">
                <h6><i class="fas fa-info-circle"></i> Informasi Workflow</h6>
                <p>Setoran yang sedang dibawa oleh kurir dan menunggu konfirmasi penerimaan dari staff keuangan pusat. Sistem mendukung penuh transaksi closing yang merupakan gabungan dari berbagai transaksi per cabang.</p>
            </div>
            
            <?php if ($setoran_list): ?>
            <div class="bulk-actions">
                <form action="" method="POST" id="terimaSetoranForm">
                    <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                        <label style="display: flex; align-items: center; gap: 8px; margin: 0;">
                            <input type="checkbox" id="selectAllTerima" class="form-check-input">
                            <span style="font-weight: 500;">Pilih Semua</span>
                        </label>
                        <button type="submit" name="terima_setoran" class="btn btn-success" onclick="return confirm('Yakin ingin menerima setoran yang dipilih?')">
                            <i class="fas fa-check"></i> Terima Setoran Terpilih
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            
            <div class="table-container">
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th width="50">Pilih</th>
                                <th>Tanggal</th>
                                <th>Kode Setoran</th>
                                <th>Cabang</th>
                                <th>Kasir</th>
                                <th>Pengantar</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($setoran_list): ?>
                                <?php foreach ($setoran_list as $row): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="setoran_ids[]" value="<?php echo $row['id']; ?>" 
                                                   class="terimaCheckbox form-check-input" form="terimaSetoranForm">
                                        </td>
                                        <td><?php echo date('d/m/Y', strtotime($row['tanggal_setoran'])); ?></td>
                                        <td><code><?php echo htmlspecialchars($row['kode_setoran']); ?></code></td>
                                        <td><?php echo htmlspecialchars(ucfirst($row['nama_cabang'])); ?></td>
                                        <td><?php echo htmlspecialchars($row['nama_karyawan']); ?></td>
                                        <td><?php echo htmlspecialchars($row['nama_pengantar']); ?></td>
                                        <td>
                                            <span class="status-badge bg-info">Sedang Dibawa Kurir</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="no-data">
                                        <i class="fas fa-inbox"></i><br>
                                        Tidak ada setoran yang perlu diterima
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>





    <!-- Tab Setor Bank - Updated with better UX -->
    <?php if ($tab == 'setor_bank'): ?>
    <div class="content-card">
        <div class="content-header">
            <h3><i class="fas fa-university"></i> Setor ke Bank</h3>
            <div class="export-buttons">
                <a href="export_excel_setoran.php?type=setor_bank&tab=<?php echo $tab; ?>&rekening_filter=<?php echo $rekening_filter; ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a href="export_csv.php?type=setor_bank&tab=<?php echo $tab; ?>&rekening_filter=<?php echo $rekening_filter; ?>" class="btn btn-info btn-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
            </div>
        </div>
        <div class="content-body">
            <div class="workflow-info">
                <h6><i class="fas fa-info-circle"></i> Informasi Workflow</h6>
                <p>Transaksi closing yang sudah divalidasi tanpa selisih dan siap disetor ke bank. Tampilan menunjukkan setiap transaksi closing secara individual, bukan per paket setoran. <strong>Pilih rekening cabang terlebih dahulu untuk melihat transaksi closing yang dapat disetor dari cabang tersebut.</strong></p>
            </div>

            <?php if ($rekening_filter == 'all' || empty($setoran_list)): ?>
            <div class="no-setoran-message">
                <h6><i class="fas fa-exclamation-triangle"></i> Pilih Rekening Cabang</h6>
                <p>Silakan pilih rekening cabang di filter untuk menampilkan transaksi closing yang siap disetor ke bank dari cabang tersebut.</p>
            </div>
            <?php endif; ?>
            
            <form action="" method="POST" id="setorBankForm">
                <?php if ($rekening_filter !== 'all' && !empty($rekening_filter)): ?>
                    <!-- Rekening sudah dipilih dari filter, otomatis set sebagai tujuan -->
                    <input type="hidden" name="rekening_cabang_id" value="<?php echo htmlspecialchars($rekening_filter); ?>">
                    <div class="alert alert-success" style="margin-bottom: 20px;">
                        <i class="fas fa-check-circle"></i>
                        <strong>Rekening Tujuan:</strong> Otomatis menggunakan rekening yang dipilih dari filter di atas.
                    </div>
                <?php else: ?>
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label class="form-label">Rekening Cabang Tujuan <span class="required">*</span></label>
                        <select name="rekening_cabang_id" id="rekeningCabang" class="form-control" required>
                            <option value="">Pilih Rekening Cabang (Gabungan: <?php echo date('H:i:s'); ?>)</option>
                            <?php foreach ($rekening_list as $rekening): ?>
                                <?php
                                // Parse cabang info to get all cabang names and IDs
                                $cabang_items = explode(';;', $rekening['cabang_info']);
                                $rekening_ids = explode(',', $rekening['rekening_ids']);
                                $cabang_names = array();
                                foreach ($cabang_items as $item) {
                                    $parts = explode('|', $item);
                                    if (count($parts) == 2) {
                                        $cabang_names[] = $parts[0];
                                    }
                                }
                                $cabang_display = '(' . implode('-', $cabang_names) . ')';
                                $jenis_badge = $rekening['jenis_rekening'] == 'Mitra' ? ' (MITRA)' : ' (MILIK SENDIRI)';
                                
                                // Use all rekening IDs as comma separated values for the option
                                $all_rekening_ids = $rekening['rekening_ids'];
                                
                                // Format: Nama Bank (No Rek) - (Cabang1-Cabang2) (Jenis)
                                $display_text = $rekening['nama_bank'] . ' (' . $rekening['no_rekening'] . ') - ' . $cabang_display . $jenis_badge;
                                ?>
                                <option value="<?php echo htmlspecialchars($all_rekening_ids); ?>">
                                    <?php echo htmlspecialchars($display_text); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: var(--text-muted); font-size: 12px; margin-top: 5px;">
                            Pilih rekening tujuan. Sistem akan menampilkan setoran dari semua cabang yang menggunakan nomor rekening yang sama.
                        </small>
                    </div>
                <?php endif; ?>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label">Tanggal Setor <span class="required">*</span></label>
                    <input type="date" name="tanggal_setoran" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <?php if ($rekening_filter !== 'all' && !empty($setoran_list)): ?>
                <div class="alert alert-info show" style="margin-bottom: 20px;">
                    <i class="fas fa-info-circle"></i>
                    Menampilkan transaksi closing dari cabang yang sesuai dengan rekening yang dipilih. Total transaksi closing yang dapat disetor: <strong><?php echo count($setoran_list); ?> transaksi</strong>
                </div>

                <div class="table-container">
                    <div class="table-wrapper" id="setorBankTableWrapper" style="overflow-x: scroll !important; overflow-y: visible !important; max-width: 100%; width: 100%; border: 1px solid #dee2e6; border-radius: 8px; scrollbar-width: thick; scrollbar-color: #dc3545 #f8f9fa;">
                        <div class="table-enhanced" style="min-width: 1500px; width: 1500px;">
                            <table class="table" style="min-width: 1500px !important; width: 1500px !important; white-space: nowrap; table-layout: fixed;">
                                <thead>
                                    <tr>
                                        <th width="50">
                                            <input type="checkbox" id="selectAllBank">
                                        </th>
                                        <th>Tanggal Closing</th>
                                        <th>Kode Transaksi</th>
                                        <th>Kode Setoran</th>
                                        <th>Cabang</th>
                                        <th>Setoran Real</th>
                                        <th>Data Setoran</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_all_setoran = 0;
                                    foreach ($setoran_list as $row): 
                                        $total_all_setoran += ($row['setoran_real'] ?? 0);
                                    ?>
                                        <tr>
                                            <td><input type="checkbox" name="closing_ids[]" value="<?php echo $row['id']; ?>" class="bankCheckbox"></td>
                                            <td><?php 
                                                $tgl_closing = $row['tanggal_closing'];
                                                $jam_closing = $row['jam_closing'];
                                                if ($tgl_closing) {
                                                    echo date('d/m/Y', strtotime($tgl_closing));
                                                    if ($jam_closing) {
                                                        echo '<br><small>' . $jam_closing . '</small>';
                                                    }
                                                } else {
                                                    echo '-';
                                                }
                                            ?></td>

                                            <td><code><?php echo htmlspecialchars($row['kode_transaksi']); ?></code></td>
                                            <td><code style="font-size: 11px;"><?php echo htmlspecialchars($row['kode_setoran']); ?></code></td>
                                            <td><?php echo htmlspecialchars(ucfirst($row['nama_cabang'])); ?></td>
                                            <td style="text-align: right; font-weight: 600;"><?php echo formatRupiah($row['setoran_real'] ?? 0); ?></td>
                                            <td style="text-align: right; font-weight: 600;"><?php echo formatRupiah($row['data_setoran'] ?? 0); ?></td>
                                            <td>
                                                <span class="status-badge bg-success"><?php echo htmlspecialchars($row['deposit_status']); ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr style="background: var(--background-light); font-weight: bold;">
                                        <td colspan="5" style="text-align: right;">Total Keseluruhan:</td>
                                        <td style="text-align: right; color: var(--success-color);"><?php echo formatRupiah($total_all_setoran); ?></td>
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <style>
                    /* Force scrollbar untuk setor bank table - Super specific */
                    #setorBankTableWrapper {
                        overflow-x: scroll !important;
                        overflow-y: visible !important;
                        max-width: 100% !important;
                        scrollbar-width: thick !important;
                        scrollbar-color: #dc3545 #f8f9fa;
                    }
                    
                    #setorBankTableWrapper::-webkit-scrollbar {
                        height: 16px !important;
                        background: #f1f1f1 !important;
                        border: 2px solid #ccc !important;
                        border-radius: 8px !important;
                        -webkit-appearance: none !important;
                        display: block !important;
                    }
                    
                    #setorBankTableWrapper::-webkit-scrollbar-track {
                        background: #e0e0e0 !important;
                        border-radius: 8px !important;
                        border: 1px solid #bbb !important;
                    }
                    
                    #setorBankTableWrapper::-webkit-scrollbar-thumb {
                        background: #dc3545 !important;
                        border-radius: 8px !important;
                        border: 2px solid #fff !important;
                        min-width: 40px !important;
                        -webkit-appearance: none !important;
                    }
                    
                    #setorBankTableWrapper::-webkit-scrollbar-thumb:hover {
                        background: #c82333 !important;
                        cursor: grab !important;
                    }
                    
                    #setorBankTableWrapper::-webkit-scrollbar-thumb:active {
                        background: #a71e2a !important;
                        cursor: grabbing !important;
                    }
                </style>

                <script>
                    // Force scrollbar visibility on page load
                    document.addEventListener('DOMContentLoaded', function() {
                        const wrapper = document.getElementById('setorBankTableWrapper');
                        if (wrapper) {
                            wrapper.style.overflowX = 'scroll';
                            wrapper.style.overflowY = 'visible';
                            wrapper.style.maxWidth = '100%';
                            wrapper.style.width = '100%';
                            
                            // Force redraw
                            setTimeout(() => {
                                wrapper.style.display = 'none';
                                wrapper.offsetHeight; // trigger reflow
                                wrapper.style.display = 'block';
                            }, 100);
                        }
                    });
                </script>

                <div style="margin-top: 20px;">
                    <button type="submit" name="setor_bank" class="btn btn-success">
                        <i class="fas fa-university"></i> Setor ke Bank
                    </button>
                    <button type="button" class="btn btn-info" onclick="showSetoranSummary()">
                        <i class="fas fa-calculator"></i> Lihat Ringkasan
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <!-- Tab Riwayat Setoran Bank - Full Page Layout with closing info -->
    <?php if ($tab == 'bank_history'): ?>
    <div class="content-card">
        <div class="content-header">
            <h3><i class="fas fa-file-alt"></i> Riwayat Setoran ke Bank</h3>
            <div class="export-buttons">
                <a href="export_excel_setoran.php?type=bank_history&tab=<?php echo $tab; ?>&rekening_filter=<?php echo $rekening_filter; ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a href="export_csv.php?type=bank_history&tab=<?php echo $tab; ?>&rekening_filter=<?php echo $rekening_filter; ?>" class="btn btn-info btn-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <a href="?tab=terima" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Kembali ke Tab
                </a>
            </div>
        </div>
        <div class="content-body">
            <!-- Filter khusus untuk bank history -->
            <div class="filter-card" style="margin-bottom: 20px;">
                <form action="" method="POST" class="form-inline">
                    <input type="hidden" name="tab_filter" value="<?php echo $tab; ?>">
                    <div class="form-group">
                        <label class="form-label">Tanggal Awal:</label>
                        <input type="date" name="tanggal_awal" class="form-control" value="<?php echo htmlspecialchars($tanggal_awal); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal Akhir:</label>
                        <input type="date" name="tanggal_akhir" class="form-control" value="<?php echo htmlspecialchars($tanggal_akhir); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Rekening:</label>
                        <select name="rekening_filter" class="form-control">
                            <option value="all">Semua Rekening</option>
                            <?php foreach ($rekening_list as $rekening): ?>
                                <?php
                                // Parse cabang info to get all cabang names and IDs
                                $cabang_items = explode(';;', $rekening['cabang_info']);
                                $rekening_ids = explode(',', $rekening['rekening_ids']);
                                $cabang_names = array();
                                foreach ($cabang_items as $item) {
                                    $parts = explode('|', $item);
                                    if (count($parts) == 2) {
                                        $cabang_names[] = $parts[0];
                                    }
                                }
                                $cabang_display = '(' . implode('-', $cabang_names) . ')';
                                $jenis_badge = $rekening['jenis_rekening'] == 'Mitra' ? ' (MITRA)' : ' (MILIK SENDIRI)';
                                
                                // Use all rekening IDs as comma separated values for the option
                                $all_rekening_ids = $rekening['rekening_ids'];
                                
                                // Format: Nama Bank (No Rek) - (Cabang1-Cabang2) (Jenis)
                                $display_text = $rekening['nama_bank'] . ' (' . $rekening['no_rekening'] . ') - ' . $cabang_display . $jenis_badge;
                                ?>
                                <option value="<?php echo htmlspecialchars($all_rekening_ids); ?>" <?php echo ($rekening_filter !== 'all' && !empty($rekening_filter) && ($rekening_filter == $all_rekening_ids || in_array($rekening_filter, explode(',', $all_rekening_ids)))) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($display_text); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </form>
            </div>

            <div class="workflow-info">
                <h6><i class="fas fa-info-circle"></i> Informasi</h6>
                <p>Riwayat semua setoran yang telah disetor ke bank. Sistem menampilkan informasi transaksi closing jika ada. Klik "Detail" untuk melihat detail setoran closing per cabang.</p>
            </div>
            
            <div class="table-container">
                <div class="table-wrapper" id="bankHistoryTableWrapper" style="overflow-x: scroll !important; overflow-y: visible !important; max-width: 100%; width: 100%; border: 1px solid #dee2e6; border-radius: 8px; scrollbar-width: thick; scrollbar-color: #dc3545 #f8f9fa;">
                    <div class="table-enhanced" style="min-width: 1600px; width: 1600px;">
                        <table class="table" style="min-width: 1600px !important; width: 1600px !important; table-layout: fixed; white-space: nowrap;">
                            <thead>
                                <tr>
                                    <th style="width: 100px;">Tanggal Setor</th>
                                    <th style="width: 180px;">Kode Setoran Bank</th>
                                    <th style="width: 200px;">Cabang Terkait</th>
                                    <th style="width: 200px;">Rekening Tujuan</th>
                                    <th style="width: 130px;">Total Setoran</th>
                                    <th style="width: 100px;">Jumlah Paket</th>
                                    <th style="width: 120px;">Transaksi Closing</th>
                                    <th style="width: 150px;">Disetor Oleh</th>
                                    <th style="width: 100px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($setoran_list): ?>
                                    <?php foreach ($setoran_list as $row): ?>
                                        <tr>
                                            <td style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal;"><?php 
                                                $tgl_disp = $row['tanggal_closing_transaksi'] ?? $row['tanggal_setoran'];
                                                echo $tgl_disp ? date('d/m/Y', strtotime($tgl_disp)) : '-';
                                            ?></td>

                                            <td style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal;"><code style="word-break: break-all;"><?php echo htmlspecialchars($row['kode_setoran']); ?></code></td>
                                            <td style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal;"><?php echo htmlspecialchars($row['cabang_names']); ?></td>
                                            <td style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal;"><?php echo htmlspecialchars($row['rekening_tujuan']); ?></td>
                                            <td style="text-align: right; font-weight: 600; color: var(--success-color); word-wrap: break-word; overflow-wrap: break-word; white-space: normal;">
                                                <?php echo formatRupiah($row['total_setoran']); ?>
                                            </td>
                                            <td style="text-align: center; word-wrap: break-word; overflow-wrap: break-word; white-space: normal;"><?php echo $row['total_setoran_count']; ?> paket</td>
                                            <td style="text-align: center; word-wrap: break-word; overflow-wrap: break-word; white-space: normal;">
                                                <?php if (isset($row['total_closing_transactions']) && $row['total_closing_transactions'] > 0): ?>
                                                    <span class="status-badge bg-closing"><?php echo $row['total_closing_transactions']; ?> CLOSING</span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted); font-size: 12px;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="word-wrap: break-word; overflow-wrap: break-word; white-space: normal;"><?php echo htmlspecialchars($row['created_by_name']); ?></td>
                                            <td>
                                                <?php
                                                // Get first cabang for direct closing detail
                                                $sql_first_cabang = "SELECT c.nama_cabang FROM setoran_ke_bank_detail sbd 
                                                                     JOIN setoran_keuangan sk ON sbd.setoran_keuangan_id = sk.id 
                                                                     JOIN cabang c ON sk.kode_cabang = c.kode_cabang 
                                                                     WHERE sbd.setoran_ke_bank_id = ? LIMIT 1";
                                                $stmt_first_cabang = $pdo->prepare($sql_first_cabang);
                                                $stmt_first_cabang->execute([$row['id']]);
                                                $first_cabang = $stmt_first_cabang->fetchColumn();
                                                ?>
                                                <a href="?tab=bank_history&bank_detail_id=<?php echo $row['id']; ?>" class="btn btn-info btn-sm">
                                                    <i class="fas fa-eye"></i> Detail
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="no-data">
                                            <i class="fas fa-file-alt"></i><br>
                                            Tidak ada riwayat setoran ke bank ditemukan
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Total Keseluruhan Setoran untuk Bank History -->
                <?php if ($setoran_list && !empty($setoran_list)): ?>
                    <?php 
                    $total_keseluruhan = 0;
                    $total_paket = 0;
                    foreach ($setoran_list as $row) {
                        $total_keseluruhan += $row['total_setoran'];
                        $total_paket += $row['total_setoran_count'];
                    }
                    ?>
                    <div class="total-summary-card" style="margin-top: 20px; background: linear-gradient(135deg, #007bff, #0056b3); color: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,123,255,0.3);">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h4 style="margin: 0; font-weight: 600; display: flex; align-items: center;">
                                    <i class="fas fa-calculator" style="margin-right: 10px; font-size: 20px;"></i>
                                    Total Keseluruhan Setoran
                                    <?php if ($rekening_filter !== 'all' && !empty($rekening_filter)): ?>
                                        <span style="font-size: 14px; opacity: 0.8; margin-left: 10px;">(Filtered)</span>
                                    <?php endif; ?>
                                </h4>
                            </div>
                            <div class="col-md-4 text-right">
                                <div style="font-size: 24px; font-weight: 700; margin-bottom: 5px;">
                                    <?php echo formatRupiah($total_keseluruhan); ?>
                                </div>
                                <div style="font-size: 14px; opacity: 0.9;">
                                    <i class="fas fa-boxes" style="margin-right: 5px;"></i>
                                    <?php echo number_format($total_paket); ?> paket setoran
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <style>
                /* Force scrollbar untuk bank history table - Super specific */
                #bankHistoryTableWrapper {
                    overflow-x: scroll !important;
                    overflow-y: visible !important;
                    max-width: 100% !important;
                    scrollbar-width: thick !important;
                    scrollbar-color: #dc3545 #f1f1f1 !important;
                }
                
                #bankHistoryTableWrapper::-webkit-scrollbar {
                    height: 16px !important;
                    background: #f1f1f1 !important;
                    border: 2px solid #ccc !important;
                    border-radius: 8px !important;
                    -webkit-appearance: none !important;
                    display: block !important;
                }
                
                #bankHistoryTableWrapper::-webkit-scrollbar-track {
                    background: #e0e0e0 !important;
                    border-radius: 8px !important;
                    border: 1px solid #bbb !important;
                }
                
                #bankHistoryTableWrapper::-webkit-scrollbar-thumb {
                    background: #dc3545 !important;
                    border-radius: 8px !important;
                    border: 2px solid #fff !important;
                    min-width: 40px !important;
                    -webkit-appearance: none !important;
                }
                
                #bankHistoryTableWrapper::-webkit-scrollbar-thumb:hover {
                    background: #c82333 !important;
                    cursor: grab !important;
                }
                
                #bankHistoryTableWrapper::-webkit-scrollbar-thumb:active {
                    background: #a71e2a !important;
                    cursor: grabbing !important;
                }
            </style>

            <script>
                // Force scrollbar visibility on bank history table
                document.addEventListener('DOMContentLoaded', function() {
                    const wrapper = document.getElementById('bankHistoryTableWrapper');
                    if (wrapper) {
                        wrapper.style.overflowX = 'scroll';
                        wrapper.style.overflowY = 'visible';
                        wrapper.style.maxWidth = '100%';
                        wrapper.style.width = '100%';
                        
                        // Force redraw
                        setTimeout(() => {
                            wrapper.style.display = 'none';
                            wrapper.offsetHeight; // trigger reflow
                            wrapper.style.display = 'block';
                        }, 100);
                    }
                });
            </script>
        </div>
    </div>
    <?php endif; ?>

    <!-- PERBAIKAN UTAMA: Tab Validasi Fisik dengan informasi closing yang detail -->
    <?php if ($tab == 'validasi'): ?>
    <div class="content-card tab-validasi">
        <div class="content-header">
            <h3><i class="fas fa-search"></i> Validasi Fisik Uang per Transaksi - DIPERBAIKI UNTUK CLOSING</h3>
            <div class="export-buttons">
                <a href="export_excel_setoran.php?type=validasi&tab=<?php echo $tab; ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a href="export_csv.php?type=validasi&tab=<?php echo $tab; ?>" class="btn btn-info btn-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
            </div>
        </div>
        <div class="content-body">
            <div class="workflow-info">
                <h6><i class="fas fa-info-circle"></i> Informasi Workflow - PERBAIKAN CLOSING</h6>
                <p><strong>PERBAIKAN:</strong> Validasi dilakukan per transaksi individual dengan kalkulasi yang diperbaiki untuk transaksi closing. Untuk transaksi "DARI CLOSING" yang memiliki pemasukan terkait (dipinjam), sistem akan menghitung <strong>Expected Physical Amount = Setoran Real - Pemasukan</strong>. Transaksi closing ditandai dengan warna ungu dan informasi detail.</p>
            </div>
            
            <div class="table-container">
                <div class="table-wrapper">
                    <div class="table-enhanced" style="overflow-x: auto;">
                        <table class="table" style="min-width: 1200px; white-space: nowrap;">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Kode Transaksi</th>
                                    <th>Jenis</th>
                                    <th>Kode Setoran</th>
                                    <th>Cabang</th>
                                    <th>Kasir</th>
                                    <th>Nominal Sistem</th>
                                    <th class="closing-info-column">Info Closing (DIPERBAIKI)</th>
                                    <th class="closing-details-column">Expected Physical</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($setoran_list): ?>
                                    <?php foreach ($setoran_list as $row): ?>
                                        <tr class="<?php echo $row['jenis_transaksi'] == 'DARI CLOSING' ? 'closing-transaction' : ''; ?>">
                                            <td><?php echo date('d/m/Y', strtotime($row['tanggal_transaksi'])); ?></td>
                                            <td>
                                                <div class="transaction-type-indicator">
                                                    <code><?php echo htmlspecialchars($row['kode_transaksi']); ?></code>
                                                    <?php if ($row['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                                        <span class="closing-info-badge">CLOSING</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($row['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                                    <span class="status-badge bg-closing">DARI CLOSING</span>
                                                    <?php if ($row['total_closing_in_setoran'] > 1): ?>
                                                        <small style="display: block; font-size: 10px; color: var(--text-muted); margin-top: 2px;">
                                                            <?php echo $row['total_closing_in_setoran']; ?> transaksi closing dalam setoran ini
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="status-badge bg-primary">TRANSAKSI BIASA</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?php echo htmlspecialchars($row['kode_setoran']); ?></code></td>
                                            <td><?php echo htmlspecialchars(ucfirst($row['nama_cabang'])); ?></td>
                                            <td><?php echo htmlspecialchars($row['nama_karyawan']); ?></td>
                                            <td style="text-align: right; font-weight: 600;"><?php echo formatRupiah($row['setoran_real']); ?></td>
                                            <td class="closing-info-column">
                                                <?php if ($row['jenis_transaksi'] == 'DARI CLOSING' && !empty($row['total_pemasukan_closing'])): ?>
                                                    <div class="closing-borrowed-info">
                                                        <div><strong>Dipinjam:</strong> <span class="borrowed-amount"><?php echo formatRupiah($row['total_pemasukan_closing']); ?></span></div>
                                                        <small><?php echo htmlspecialchars($row['keterangan_closing_gabungan'] ?? 'Transaksi closing gabungan'); ?></small>
                                                    </div>
                                                <?php elseif ($row['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                                    <div class="closing-borrowed-info">
                                                        <div><strong>Closing Dari</strong></div>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="closing-details-column">
                                                <?php if ($row['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                                    <div class="expected-physical-display">
                                                        <span class="amount"><?php echo formatRupiah($row['expected_physical_amount'] ?? $row['setoran_real']); ?></span>
                                                        <small class="label">Harusnya Diterima</small>
                                                    </div>
                                                <?php else: ?>
                                                    <div style="text-align: center; color: var(--text-muted);">
                                                        <?php echo formatRupiah($row['setoran_real']); ?>
                                                        <br><small>Normal</small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge bg-warning">Diterima Staff Keuangan</span>
                                            </td>
                                            <td>
                                                <a href="?tab=validasi&validate_id=<?php echo $row['kode_transaksi']; ?>" 
                                                   class="btn <?php echo $row['jenis_transaksi'] == 'DARI CLOSING' ? 'btn-danger' : 'btn-warning'; ?> btn-sm"
                                                   title="<?php echo $row['jenis_transaksi'] == 'DARI CLOSING' ? 'Validasi Transaksi Closing' : 'Validasi Transaksi Biasa'; ?>">
                                                    <i class="fas fa-edit"></i> Validasi
                                                    <?php if ($row['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                                        <span style="font-size: 9px;">CLOSING</span>
                                                    <?php endif; ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="11" class="no-data">
                                            <i class="fas fa-search"></i><br>
                                            Tidak ada transaksi yang perlu divalidasi fisik
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- PERBAIKAN UTAMA: Tab Validasi Selisih dengan informasi closing yang detail -->
    <?php if ($tab == 'validasi_selisih'): ?>
    <div class="content-card tab-validasi_selisih">
        <div class="content-header">
            <h3><i class="fas fa-edit"></i> Edit Validasi Selisih - DIPERBAIKI UNTUK CLOSING</h3>
            <div class="export-buttons">
                <a href="export_excel_setoran.php?type=validasi_selisih&tab=<?php echo $tab; ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a href="export_csv.php?type=validasi_selisih&tab=<?php echo $tab; ?>" class="btn btn-info btn-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
            </div>
        </div>
        <div class="content-body">
            <div class="workflow-info">
                <h6><i class="fas fa-info-circle"></i> Informasi Workflow - PERBAIKAN CLOSING & FITUR KEMBALIKAN</h6>
                <p><strong>PERBAIKAN:</strong> Transaksi yang memiliki selisih dalam validasi fisik dengan kalkulasi yang diperbaiki untuk transaksi closing. Anda dapat mengedit ulang jumlah yang diterima untuk mengoreksi selisih. Sistem telah diperbaiki untuk menghitung selisih berdasarkan <strong>Expected Physical Amount</strong> untuk transaksi closing yang memiliki pinjaman.</p>
                <p><strong>FITUR BARU:</strong> <i class="fas fa-undo" style="color: var(--warning-color);"></i> <strong>Kembalikan ke CS</strong> - Untuk transaksi dengan selisih yang tidak dapat diperbaiki, Anda dapat mengembalikannya ke CS pengirim untuk diperbaiki di sumber. CS akan menerima notifikasi dan dapat melakukan perbaikan sebelum mengirim ulang.</p>
            </div>
            
            <div class="table-container">
                <div class="table-wrapper">
                    <div class="table-enhanced" style="overflow-x: auto;">
                        <table class="table" style="min-width: 1400px;">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Kode Transaksi</th>
                                    <th>Jenis</th>
                                    <th>Kode Setoran</th>
                                    <th>Cabang</th>
                                    <th>Kasir</th>
                                    <th>Nominal Sistem</th>
                                    <th>Diterima Fisik</th>
                                    <th>Selisih</th>
                                    <th class="closing-info-column">Info Closing (DIPERBAIKI)</th>
                                    <th class="closing-details-column">Expected Physical</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($setoran_list): ?>
                                    <?php foreach ($setoran_list as $row): ?>
                                        <tr class="<?php echo $row['jenis_transaksi'] == 'DARI CLOSING' ? 'closing-transaction' : ''; ?>">
                                            <td><?php echo date('d/m/Y', strtotime($row['tanggal_transaksi'])); ?></td>
                                            <td>
                                                <div class="transaction-type-indicator">
                                                    <code><?php echo htmlspecialchars($row['kode_transaksi']); ?></code>
                                                    <?php if ($row['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                                        <span class="closing-info-badge">CLOSING</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($row['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                                    <span class="status-badge bg-closing">DARI CLOSING</span>
                                                    <?php if ($row['total_closing_in_setoran'] > 1): ?>
                                                        <small style="display: block; font-size: 10px; color: var(--text-muted); margin-top: 2px;">
                                                            <?php echo $row['total_closing_in_setoran']; ?> transaksi closing dalam setoran ini
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="status-badge bg-primary">TRANSAKSI BIASA</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?php echo htmlspecialchars($row['kode_setoran']); ?></code></td>
                                            <td><?php echo htmlspecialchars(ucfirst($row['nama_cabang'])); ?></td>
                                            <td><?php echo htmlspecialchars($row['nama_karyawan']); ?></td>
                                            <td style="text-align: right; font-weight: 600;"><?php echo formatRupiah($row['setoran_real']); ?></td>
                                            <td style="text-align: right; font-weight: 600;">
                                                <?php 
                                                $diterima_fisik = ($validation_columns_exist && isset($row['jumlah_diterima_fisik'])) 
                                                    ? $row['jumlah_diterima_fisik'] 
                                                    : $row['setoran_real'];
                                                echo formatRupiah($diterima_fisik); 
                                                ?>
                                            </td>
                                            <td style="text-align: right; font-weight: 600; color: var(--danger-color);">
                                                <?php 
                                                // PERBAIKAN: Hitung selisih berdasarkan expected_physical_amount untuk closing
                                                $expected_amount = $row['expected_physical_amount'] ?? $row['setoran_real'];
                                                
                                                if ($validation_columns_exist && isset($row['selisih_fisik'])) {
                                                    $selisih = $row['selisih_fisik'];
                                                } elseif ($validation_columns_exist && isset($row['jumlah_diterima_fisik'])) {
                                                    $selisih = $row['jumlah_diterima_fisik'] - $expected_amount;
                                                } else {
                                                    $selisih = 0;
                                                }
                                                echo formatRupiah($selisih);
                                                ?>
                                            </td>
                                            <td class="closing-info-column">
                                                <?php if ($row['jenis_transaksi'] == 'DARI CLOSING' && !empty($row['total_pemasukan_closing'])): ?>
                                                    <div class="closing-borrowed-info">
                                                        <div><strong>Dipinjam:</strong> <span class="borrowed-amount"><?php echo formatRupiah($row['total_pemasukan_closing']); ?></span></div>
                                                        <small><?php echo htmlspecialchars($row['keterangan_closing_gabungan'] ?? 'Transaksi closing gabungan'); ?></small>
                                                    </div>
                                                <?php elseif ($row['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                                    <div class="closing-borrowed-info">
                                                        <div><strong>Closing Murni</strong></div>
                                                        <small>Tidak ada pinjaman</small>
                                                    </div>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="closing-details-column">
                                                <?php if ($row['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                                    <div class="expected-physical-display">
                                                        <span class="amount"><?php echo formatRupiah($expected_amount); ?></span>
                                                        <small class="label">Harusnya Diterima</small>
                                                    </div>
                                                <?php else: ?>
                                                    <div style="text-align: center; color: var(--text-muted);">
                                                        <?php echo formatRupiah($row['setoran_real']); ?>
                                                        <br><small>Normal</small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group-vertical" style="gap: 5px;">
                                                    <a href="?tab=validasi_selisih&edit_selisih_id=<?php echo $row['kode_transaksi']; ?>" 
                                                       class="btn <?php echo $row['jenis_transaksi'] == 'DARI CLOSING' ? 'btn-danger' : 'btn-warning'; ?> btn-sm"
                                                       title="<?php echo $row['jenis_transaksi'] == 'DARI CLOSING' ? 'Edit Selisih Transaksi Closing' : 'Edit Selisih Transaksi Biasa'; ?>">
                                                        <i class="fas fa-edit"></i> Edit Selisih
                                                        <?php if ($row['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                                            <span style="font-size: 9px;">CLOSING</span>
                                                        <?php endif; ?>
                                                    </a>
                                                    <button type="button" 
                                                            class="btn btn-secondary btn-sm"
                                                            onclick="showKembalikanKeCSModal('<?php echo $row['kode_transaksi']; ?>', '<?php echo htmlspecialchars($row['nama_karyawan'] ?? 'CS'); ?>', '<?php echo htmlspecialchars($row['nama_cabang'] ?? 'Cabang'); ?>')"
                                                            title="Kembalikan setoran ini ke CS pengirim untuk diperbaiki">
                                                        <i class="fas fa-undo"></i> Kembalikan ke CS
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="12" class="no-data">
                                            <i class="fas fa-check-circle"></i><br>
                                            Tidak ada transaksi dengan selisih yang perlu diperbaiki
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <!-- PERBAIKAN: Enhanced Validation Modal for Closing Transactions dengan informasi gabungan -->
    <?php if ($transaksi_detail): ?>
    <div class="modal show" style="z-index: 10000;">
        <div class="modal-dialog modal-lg" style="margin: 20px auto; max-width: 800px;">
            <div class="modal-content" style="background: white; border-radius: 16px; position: relative;">
                <div class="modal-header" style="position: relative; z-index: 10001;">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i> Validasi Fisik Transaksi
                        <?php if ($transaksi_detail['jenis_transaksi'] == 'DARI CLOSING'): ?>
                            <span class="closing-info-badge">CLOSING</span>
                        <?php endif; ?>
                    </h5>
                    <a href="?tab=validasi" class="btn-close" style="position: relative; z-index: 10002;">&times;</a>
                </div>
                <form action="" method="POST">
                    <div class="modal-body" style="position: relative; z-index: 10001; max-height: 70vh; overflow-y: auto;">
                        <input type="hidden" name="transaksi_id" value="<?php echo htmlspecialchars($transaksi_detail['kode_transaksi']); ?>">
                        
                        <!-- PERBAIKAN: Enhanced info for closing transactions dengan kalkulasi gabungan -->
                        <?php if ($transaksi_detail['jenis_transaksi'] == 'DARI CLOSING'): ?>
                        <div class="closing-validation-info">
                            <h6><i class="fas fa-sync-alt"></i> Informasi Transaksi Closing Gabungan</h6>
                            <p style="margin: 0; font-size: 14px;">
                                Transaksi ini adalah hasil closing yang merupakan gabungan dari transaksi closing, transaksi yang dipinjam, dan transaksi yang meminjam untuk cabang <strong><?php echo htmlspecialchars($transaksi_detail['nama_cabang']); ?></strong>.
                            </p>
                            
                            <?php if (!empty($transaksi_detail['total_closing_borrowed']) && $transaksi_detail['total_closing_borrowed'] > 0): ?>
                            <div class="closing-aggregation">
                                <h6 style="font-size: 13px; margin-bottom: 8px; color: var(--closing-color);">Kalkulasi Gabungan:</h6>
                                <div class="closing-summary-item">
                                    <span>Nominal Closing Asli</span>
                                    <span><?php echo formatRupiah($transaksi_detail['setoran_real']); ?></span>
                                </div>
                                <div class="closing-summary-item">
                                    <span>Di Kurangi Pemasukan </span>
                                    <span class="borrowed-amount">-<?php echo formatRupiah($transaksi_detail['total_closing_borrowed']); ?></span>
                                </div>
                                <div class="closing-summary-item total">
                                    <span>Seharusnya Diterima Fisik</span>
                                    <span class="expected-amount"><?php echo formatRupiah($transaksi_detail['setoran_real'] - $transaksi_detail['total_closing_borrowed']); ?></span>
                                </div>
                                <small style="color: var(--text-muted); font-size: 11px;">
                                    Keterangan: <?php echo htmlspecialchars($transaksi_detail['keterangan_closing']); ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (isset($transaksi_detail['closing_info']) && !empty($transaksi_detail['closing_info'])): ?>
                            <div class="closing-aggregation">
                                <h6 style="font-size: 13px; margin-bottom: 8px; color: var(--closing-color);">Rincian Gabungan per Cabang:</h6>
                                <?php foreach ($transaksi_detail['closing_info'] as $info): ?>
                                <div class="closing-summary-item">
                                    <span><?php echo $info['jenis']; ?> (<?php echo $info['count']; ?> transaksi)</span>
                                    <span><?php echo formatRupiah($info['total_sistem']); ?></span>
                                </div>
                                <?php endforeach; ?>
                                <div class="closing-summary-item total">
                                    <span>Total Gabungan</span>
                                    <span><?php echo formatRupiah(array_sum(array_column($transaksi_detail['closing_info'], 'total_sistem'))); ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="validation-summary">
                            <h6 style="margin-bottom: 15px; color: var(--text-dark);"><i class="fas fa-info-circle"></i> Informasi Transaksi</h6>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Kode Transaksi</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($transaksi_detail['kode_transaksi']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Jenis Transaksi</div>
                                    <div class="detail-value">
                                        <?php if ($transaksi_detail['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                            <span style="color: var(--closing-color); font-weight: 600;">DARI CLOSING</span>
                                        <?php else: ?>
                                            <span style="color: var(--primary-color); font-weight: 600;">TRANSAKSI BIASA</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Kode Setoran</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($transaksi_detail['kode_setoran']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Tanggal Transaksi</div>
                                    <div class="detail-value"><?php echo date('d/m/Y H:i', strtotime($transaksi_detail['tanggal_transaksi'])); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Cabang</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($transaksi_detail['nama_cabang']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Kasir</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($transaksi_detail['nama_karyawan']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Nominal Sistem</div>
                                    <div class="detail-value amount"><?php echo formatRupiah($transaksi_detail['setoran_real']); ?></div>
                                </div>
                                <?php if (!empty($transaksi_detail['total_closing_borrowed']) && $transaksi_detail['total_closing_borrowed'] > 0): ?>
                                <div class="detail-item">
                                    <div class="detail-label">Yang Seharusnya Diterima</div>
                                    <div class="detail-value expected-amount"><?php echo formatRupiah($transaksi_detail['setoran_real'] - $transaksi_detail['total_closing_borrowed']); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <h6 style="margin-bottom: 10px; color: var(--text-dark);"><i class="fas fa-calculator"></i> Input Validasi Fisik Uang</h6>
                        <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 15px;">
                            <?php if ($transaksi_detail['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                <?php if (!empty($transaksi_detail['total_closing_borrowed']) && $transaksi_detail['total_closing_borrowed'] > 0): ?>
                                    Masukkan jumlah uang yang benar-benar diterima secara fisik. Sistem akan otomatis menghitung selisih berdasarkan jumlah yang seharusnya diterima (<?php echo formatRupiah($transaksi_detail['setoran_real'] - $transaksi_detail['total_closing_borrowed']); ?>):
                                <?php else: ?>
                                    Masukkan jumlah uang gabungan yang benar-benar diterima secara fisik untuk transaksi closing ini:
                                <?php endif; ?>
                            <?php else: ?>
                                Masukkan jumlah uang yang benar-benar diterima secara fisik untuk transaksi ini:
                            <?php endif; ?>
                        </p>
                        <div class="detail-grid" style="margin-bottom: 15px;">
                            <div class="detail-item">
                                <label class="form-label">Jumlah Diterima Fisik:</label>
                                <input type="text" name="jumlah_diterima" id="jumlahDiterima" class="form-control" 
                                       value="<?php 
                                       // PERBAIKAN: Set default value berdasarkan kalkulasi gabungan
                                       if (!empty($transaksi_detail['total_closing_borrowed']) && $transaksi_detail['total_closing_borrowed'] > 0) {
                                           echo formatRupiah($transaksi_detail['setoran_real'] - $transaksi_detail['total_closing_borrowed']);
                                       } else {
                                           echo formatRupiah($transaksi_detail['setoran_real']);
                                       }
                                       ?>" required oninput="hitungSelisihTransaksi()">
                            </div>
                        </div>
                        <div class="detail-grid" id="selisihRow" style="display: none; margin-bottom: 15px;">
                            <div class="detail-item">
                                <label class="form-label">Selisih:</label>
                                <span id="selisihAmount" style="font-weight: 600; font-size: 16px;"></span>
                            </div>
                        </div>

                        <div class="detail-item">
                            <label class="form-label">Catatan Validasi (opsional):</label>
                            <textarea name="catatan_validasi" class="form-control" rows="3" 
                                      placeholder="<?php echo $transaksi_detail['jenis_transaksi'] == 'DARI CLOSING' ? 'Tambahkan catatan untuk transaksi closing ini...' : 'Tambahkan catatan jika ada selisih atau keterangan khusus...'; ?>"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="?tab=validasi" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Batal
                        </a>
                        <button type="button" 
                                class="btn btn-warning"
                                onclick="showKembalikanKeCSModal('<?php echo $transaksi_detail['kode_transaksi']; ?>', '<?php echo htmlspecialchars($transaksi_detail['nama_karyawan'] ?? 'CS'); ?>', '<?php echo htmlspecialchars($transaksi_detail['nama_cabang'] ?? 'Cabang'); ?>')"
                                title="Kembalikan setoran ini ke CS pengirim tanpa validasi">
                            <i class="fas fa-undo"></i> Kembalikan ke CS
                        </button>
                        <button type="submit" name="validasi_individual" class="btn btn-primary">
                            <i class="fas fa-save"></i> Simpan Validasi
                            <?php if ($transaksi_detail['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                Closing
                            <?php endif; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- PERBAIKAN: Enhanced Edit Selisih Modal for Closing Transactions dengan informasi gabungan -->
    <?php if ($edit_selisih_detail): ?>
    <div class="modal show" style="z-index: 10000;">
        <div class="modal-dialog modal-lg" style="margin: 20px auto; max-width: 800px;">
            <div class="modal-content" style="background: white; border-radius: 16px; position: relative;">
                <div class="modal-header" style="position: relative; z-index: 10001;">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i> Edit Selisih Transaksi
                        <?php if ($edit_selisih_detail['jenis_transaksi'] == 'DARI CLOSING'): ?>
                            <span class="closing-info-badge">CLOSING</span>
                        <?php endif; ?>
                    </h5>
                    <a href="?tab=validasi_selisih" class="btn-close" style="position: relative; z-index: 10002;">&times;</a>
                </div>
                <form action="" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="transaksi_id" value="<?php echo htmlspecialchars($edit_selisih_detail['kode_transaksi']); ?>">
                        
                        <!-- PERBAIKAN: Enhanced info for closing transactions dengan kalkulasi gabungan -->
                        <?php if ($edit_selisih_detail['jenis_transaksi'] == 'DARI CLOSING'): ?>
                        <div class="closing-validation-info">
                            <h6><i class="fas fa-sync-alt"></i> Informasi Transaksi Closing Gabungan</h6>
                            <p style="margin: 0; font-size: 14px;">
                                Transaksi ini adalah hasil closing yang merupakan gabungan dari transaksi closing, transaksi yang dipinjam, dan transaksi yang meminjam untuk cabang <strong><?php echo htmlspecialchars($edit_selisih_detail['nama_cabang']); ?></strong>.
                            </p>
                            
                            <?php if (!empty($edit_selisih_detail['total_closing_borrowed']) && $edit_selisih_detail['total_closing_borrowed'] > 0): ?>
                            <div class="closing-aggregation">
                                <h6 style="font-size: 13px; margin-bottom: 8px; color: var(--closing-color);">Kalkulasi Gabungan:</h6>
                                <div class="closing-summary-item">
                                    <span>Nominal Closing Asli</span>
                                    <span><?php echo formatRupiah($edit_selisih_detail['setoran_real']); ?></span>
                                </div>
                                <div class="closing-summary-item">
                                    <span>Dipinjam/Digunakan</span>
                                    <span class="borrowed-amount">-<?php echo formatRupiah($edit_selisih_detail['total_closing_borrowed']); ?></span>
                                </div>
                                <div class="closing-summary-item total">
                                    <span>Seharusnya Diterima Fisik</span>
                                    <span class="expected-amount"><?php echo formatRupiah($edit_selisih_detail['setoran_real'] - $edit_selisih_detail['total_closing_borrowed']); ?></span>
                                </div>
                                <small style="color: var(--text-muted); font-size: 11px;">
                                    Keterangan: <?php echo htmlspecialchars($edit_selisih_detail['keterangan_closing']); ?>
                                </small>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (isset($edit_selisih_detail['closing_info']) && !empty($edit_selisih_detail['closing_info'])): ?>
                            <div class="closing-aggregation">
                                <h6 style="font-size: 13px; margin-bottom: 8px; color: var(--closing-color);">Rincian Gabungan per Cabang:</h6>
                                <?php foreach ($edit_selisih_detail['closing_info'] as $info): ?>
                                <div class="closing-summary-item">
                                    <span><?php echo $info['jenis']; ?> (<?php echo $info['count']; ?> transaksi)</span>
                                    <span><?php echo formatRupiah($info['total_sistem']); ?></span>
                                </div>
                                <?php endforeach; ?>
                                <div class="closing-summary-item total">
                                    <span>Total Gabungan</span>
                                    <span><?php echo formatRupiah(array_sum(array_column($edit_selisih_detail['closing_info'], 'total_sistem'))); ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="validation-summary">
                            <h6 style="margin-bottom: 15px; color: var(--text-dark);"><i class="fas fa-info-circle"></i> Informasi Transaksi</h6>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Kode Transaksi</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($edit_selisih_detail['kode_transaksi']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Jenis Transaksi</div>
                                    <div class="detail-value">
                                        <?php if ($edit_selisih_detail['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                            <span style="color: var(--closing-color); font-weight: 600;">DARI CLOSING</span>
                                        <?php else: ?>
                                            <span style="color: var(--primary-color); font-weight: 600;">TRANSAKSI BIASA</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Kode Setoran</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($edit_selisih_detail['kode_setoran']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Tanggal Transaksi</div>
                                    <div class="detail-value"><?php echo date('d/m/Y H:i', strtotime($edit_selisih_detail['tanggal_transaksi'])); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Cabang</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($edit_selisih_detail['nama_cabang']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Kasir</div>
                                    <div class="detail-value"><?php echo htmlspecialchars($edit_selisih_detail['nama_karyawan']); ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Nominal Sistem</div>
                                    <div class="detail-value amount"><?php echo formatRupiah($edit_selisih_detail['setoran_real']); ?></div>
                                </div>
                                <?php if (!empty($edit_selisih_detail['total_closing_borrowed']) && $edit_selisih_detail['total_closing_borrowed'] > 0): ?>
                                <div class="detail-item">
                                    <div class="detail-label">Yang Seharusnya Diterima</div>
                                    <div class="detail-value expected-amount"><?php echo formatRupiah($edit_selisih_detail['setoran_real'] - $edit_selisih_detail['total_closing_borrowed']); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <h6 style="margin-bottom: 10px; color: var(--text-dark);"><i class="fas fa-calculator"></i> Edit Jumlah Diterima Fisik</h6>
                        <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 15px;">
                            <?php if ($edit_selisih_detail['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                <?php if (!empty($edit_selisih_detail['total_closing_borrowed']) && $edit_selisih_detail['total_closing_borrowed'] > 0): ?>
                                    Koreksi jumlah uang yang benar-benar diterima secara fisik. Sistem akan otomatis menghitung selisih berdasarkan jumlah yang seharusnya diterima (<?php echo formatRupiah($edit_selisih_detail['setoran_real'] - $edit_selisih_detail['total_closing_borrowed']); ?>):
                                <?php else: ?>
                                    Koreksi jumlah uang gabungan yang benar-benar diterima secara fisik untuk transaksi closing ini:
                                <?php endif; ?>
                            <?php else: ?>
                                Koreksi jumlah uang yang benar-benar diterima secara fisik:
                            <?php endif; ?>
                        </p>
                        <div class="detail-grid" style="margin-bottom: 15px;">
                            <div class="detail-item">
                                <label class="form-label">Jumlah Diterima Fisik (Baru):</label>
                                <input type="text" name="jumlah_diterima_baru" id="jumlahDiterimaBaru" class="form-control" 
                                       value="<?php echo formatRupiah(($validation_columns_exist && isset($edit_selisih_detail['jumlah_diterima_fisik'])) ? $edit_selisih_detail['jumlah_diterima_fisik'] : $edit_selisih_detail['setoran_real']); ?>" required oninput="hitungSelisihEdit()">
                            </div>
                        </div>
                        <div class="detail-grid" id="selisihEditRow" style="margin-bottom: 15px;">
                            <div class="detail-item">
                                <label class="form-label">Selisih:</label>
                                <span id="selisihEditAmount" style="font-weight: 600; font-size: 16px;"></span>
                            </div>
                        </div>

                        <div class="detail-item">
                            <label class="form-label">Catatan Validasi (opsional):</label>
                            <textarea name="catatan_validasi" class="form-control" rows="3" 
                                      placeholder="<?php echo $edit_selisih_detail['jenis_transaksi'] == 'DARI CLOSING' ? 'Tambahkan catatan untuk koreksi transaksi closing ini...' : 'Tambahkan catatan untuk koreksi ini...'; ?>"><?php echo htmlspecialchars(($validation_columns_exist && isset($edit_selisih_detail['catatan_validasi'])) ? $edit_selisih_detail['catatan_validasi'] : ''); ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="?tab=validasi_selisih" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Batal
                        </a>
                        <button type="submit" name="edit_selisih" class="btn btn-primary">
                            <i class="fas fa-save"></i> Simpan Perubahan
                            <?php if ($edit_selisih_detail['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                Closing
                            <?php endif; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Enhanced Bank Detail Modal (Closing Summary) -->
    <?php if (isset($bank_detail_view) && !empty($bank_detail_view)): ?>
    <div class="modal show">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background: white; border-radius: 16px;">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-university"></i> Detail Setoran Bank - <?php echo htmlspecialchars($bank_detail_view['kode_setoran']); ?></h5>
                    <a href="?tab=bank_history" class="btn-close">&times;</a>
                </div>
                <div class="modal-body">
                    <div class="closing-summary">
                        <h4><i class="fas fa-info-circle"></i> Informasi Setoran Bank</h4>
                        <div class="closing-grid">
                            <div class="closing-item">
                                <div class="closing-label">Tanggal Setoran</div>
                                <div class="closing-value"><?php echo date('d/m/Y', strtotime($bank_detail_view['tanggal_setoran'])); ?></div>
                            </div>
                            <div class="closing-item">
                                <div class="closing-label">Rekening Tujuan</div>
                                <div class="closing-value"><?php echo htmlspecialchars($bank_detail_view['rekening_tujuan']); ?></div>
                            </div>
                            <div class="closing-item">
                                <div class="closing-label">Total Setoran</div>
                                <div class="closing-value amount"><?php echo formatRupiah($bank_detail_view['total_setoran']); ?></div>
                            </div>
                            <div class="closing-item">
                                <div class="closing-label">Disetor Oleh</div>
                                <div class="closing-value"><?php echo htmlspecialchars($bank_detail_view['created_by_name']); ?></div>
                            </div>
                        </div>
                    </div>

                    <h6 style="margin-bottom: 15px; color: var(--text-dark);"><i class="fas fa-list"></i> Detail Seluruh Transaksi Setoran (Semua Cabang)</h6>
                    <div class="table-container">
                        <div class="table-wrapper">
                            <div class="table-enhanced" style="overflow-x: auto;">
                                <table class="table" style="min-width: 900px;">
                                    <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Cabang</th>
                                            <th>Kode Setoran</th>
                                            <th>Kode Transaksi</th>
                                            <th>Jenis</th>
                                            <th>Nominal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($all_closing_detail)): ?>
                                            <?php $grand = 0; foreach ($all_closing_detail as $detail): $grand += (float)$detail['setoran_real']; ?>
                                                <tr>
                                                    <td><?php echo date('d/m/Y', strtotime($detail['tanggal_transaksi'] ?: $detail['tanggal_setoran'])); ?></td>
                                                    <td><strong><?php echo htmlspecialchars(strtoupper($detail['nama_cabang'])); ?></strong></td>
                                                    <td><code><?php echo htmlspecialchars($detail['kode_setoran']); ?></code></td>
                                                    <td><code><?php echo htmlspecialchars($detail['kode_transaksi']); ?></code></td>
                                                    <td>
                                                        <?php if ($detail['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                                            <span class="status-badge bg-closing">CLOSING</span>
                                                        <?php else: ?>
                                                            <span class="status-badge bg-primary">BIASA</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align: right; font-weight: 600;"><?php echo formatRupiah($detail['setoran_real']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr class="grand-total-row-fixed" style="background: #28a745; color: #fff; font-weight: bold;">
                                                <td colspan="5" style="text-align: right;">TOTAL KESELURUHAN:</td>
                                                <td style="text-align: right;"><?php echo formatRupiah($grand); ?></td>
                                            </tr>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="no-data">Tidak ada transaksi ditemukan</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="export_excel_setoran.php?type=bank_detail&bank_id=<?php echo $bank_detail_view['id']; ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </a>
                    <a href="?tab=bank_history" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Tutup
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Enhanced Cabang Closing Detail Modal -->
    <?php if (isset($cabang_closing_detail) && !empty($cabang_closing_detail)): ?>
    <div class="modal show">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background: white; border-radius: 16px;">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-building"></i> Detail Setoran Closing - <?php echo htmlspecialchars(strtoupper($_GET['cabang_closing'])); ?></h5>
                    <a href="?tab=bank_history" class="btn-close">&times;</a>
                </div>
                <div class="modal-body">
                    <div class="closing-summary">
                        <h4><i class="fas fa-calendar-alt"></i> Periode: <?php echo date('d/m/Y', strtotime($cabang_closing_detail[0]['tanggal_setoran'])); ?> - <?php echo date('d/m/Y', strtotime(end($cabang_closing_detail)['tanggal_setoran'])); ?></h4>
                        <div class="closing-grid">
                            <div class="closing-item">
                                <div class="closing-label">Total Setoran</div>
                                <div class="closing-value"><?php echo count(array_unique(array_column($cabang_closing_detail, 'kode_setoran'))); ?> setoran</div>
                            </div>
                            <div class="closing-item">
                                <div class="closing-label">Total Transaksi</div>
                                <div class="closing-value"><?php echo count($cabang_closing_detail); ?> transaksi</div>
                            </div>
                            <div class="closing-item">
                                <div class="closing-label">Transaksi Closing</div>
                                <div class="closing-value">
                                    <?php 
                                    $closing_count = count(array_filter($cabang_closing_detail, function($item) {
                                        return $item['jenis_transaksi'] == 'DARI CLOSING';
                                    }));
                                    echo $closing_count;
                                    ?>
                                </div>
                            </div>
                            <div class="closing-item">
                                <div class="closing-label">Total Nominal</div>
                                <div class="closing-value amount"><?php echo formatRupiah(array_sum(array_column($cabang_closing_detail, 'setoran_real'))); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="table-container">
                        <div class="table-wrapper">
                            <div class="table-enhanced" style="overflow-x: auto;">
                                <table class="table" style="min-width: 900px;">
                                    <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Kode Setoran</th>
                                            <th>Kode Transaksi</th>
                                            <th>Jenis</th>
                                            <th>Nominal Closing</th>
                                            <th>Setor</th>
                                            <th>Nominal Setor</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        foreach ($cabang_closing_detail as $detail): 
                                            $row_class = $detail['jenis_transaksi'] == 'DARI CLOSING' ? 'closing-transaction' : '';
                                        ?>
                                            <tr class="<?php echo $row_class; ?>">
                                                <td><?php echo date('d/m/Y', strtotime($detail['tanggal_transaksi'])); ?></td>
                                                <td><code><?php echo htmlspecialchars($detail['kode_setoran']); ?></code></td>
                                                <td>
                                                    <div class="transaction-type-indicator">
                                                        <code><?php echo htmlspecialchars($detail['kode_transaksi']); ?></code>
                                                        <?php if ($detail['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                                            <span class="closing-info-badge">CLOSING</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($detail['jenis_transaksi'] == 'DARI CLOSING'): ?>
                                                        <span class="status-badge bg-closing">DARI CLOSING</span>
                                                    <?php else: ?>
                                                        <span class="status-badge bg-primary">BIASA</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="text-align: right;"><?php echo formatRupiah($detail['setoran_real']); ?></td>
                                                <td style="text-align: center;">√</td>
                                                <td style="text-align: right;"><?php echo formatRupiah($detail['setoran_real']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        
                                        <!-- Grand total -->
                                        <tr id="grandTotalRow" class="grand-total-row-fixed" style="background: #28a745 !important; color: white !important; font-weight: bold !important; font-size: 16px !important; border: 2px solid #007bff !important; position: relative !important; z-index: 999 !important;">
                                            <td colspan="5" style="text-align: right !important; padding: 12px !important; font-size: 16px !important; background: #28a745 !important; color: white !important;">TOTAL KESELURUHAN:</td>
                                            <td style="text-align: right !important; padding: 12px !important; background: #28a745 !important; color: white !important;">√</td>
                                            <td style="text-align: right !important; padding: 12px !important; font-size: 16px !important; background: #28a745 !important; color: white !important;">
                                                <?php 
                                                $total_keseluruhan = 0;
                                                if (!empty($cabang_closing_detail)) {
                                                    foreach ($cabang_closing_detail as $detail) {
                                                        $total_keseluruhan += $detail['setoran_real'];
                                                    }
                                                }
                                                echo formatRupiah($total_keseluruhan); 
                                                ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    // Protect grand total row from disappearing
                    function protectGrandTotalRow() {
                        const grandTotalRow = document.getElementById('grandTotalRow');
                        if (grandTotalRow) {
                            // Force styles to prevent disappearing
                            grandTotalRow.style.cssText = 'background: #28a745 !important; color: white !important; font-weight: bold !important; font-size: 16px !important; border: 2px solid #007bff !important; position: relative !important; z-index: 999 !important; display: table-row !important; visibility: visible !important; opacity: 1 !important;';
                            
                            // Ensure all td elements have correct styling
                            const cells = grandTotalRow.querySelectorAll('td');
                            cells.forEach(cell => {
                                cell.style.cssText = 'background: #28a745 !important; color: white !important; font-weight: bold !important; padding: 12px !important; display: table-cell !important; visibility: visible !important; opacity: 1 !important;';
                            });
                            
                            // Create observer to watch for changes
                            const observer = new MutationObserver(() => {
                                if (grandTotalRow.style.display === 'none' || 
                                    grandTotalRow.style.visibility === 'hidden' ||
                                    grandTotalRow.style.opacity === '0') {
                                    grandTotalRow.style.cssText = 'background: #28a745 !important; color: white !important; font-weight: bold !important; font-size: 16px !important; border: 2px solid #007bff !important; position: relative !important; z-index: 999 !important; display: table-row !important; visibility: visible !important; opacity: 1 !important;';
                                }
                            });
                            
                            observer.observe(grandTotalRow, { 
                                attributes: true, 
                                attributeFilter: ['style', 'class'] 
                            });
                        }
                    }

                    // Run protection immediately and periodically
                    document.addEventListener('DOMContentLoaded', protectGrandTotalRow);
                    setTimeout(protectGrandTotalRow, 1000);
                    setTimeout(protectGrandTotalRow, 3000);
                    setTimeout(protectGrandTotalRow, 5000);
                    
                    // Also protect when window resizes or other events
                    window.addEventListener('resize', protectGrandTotalRow);
                    
                    // Override reinitializeTableScrolling to protect total row
                    const originalReinitialize = window.tableScrolling?.reinitialize;
                    if (originalReinitialize) {
                        window.tableScrolling.reinitialize = function() {
                            originalReinitialize.apply(this, arguments);
                            setTimeout(protectGrandTotalRow, 100);
                        };
                    }
                </script>

                <div class="modal-footer">
                    <a href="export_excel_setoran.php?type=cabang_closing&bank_id=<?php echo $_GET['bank_detail_id']; ?>&cabang=<?php echo urlencode($_GET['cabang_closing']); ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </a>
                    <a href="?tab=bank_history" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <!-- Tab Monitoring - Detail Transaksi Closing -->
    <?php if ($tab == 'monitoring'): ?>
    <div class="content-card">
        <div class="content-header">
            <h3><i class="fas fa-chart-line"></i> Monitoring Transaksi Closing</h3>
            <div class="export-buttons">
                <a href="export_excel_setoran.php?type=monitoring&tab=<?php echo $tab; ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a href="export_csv.php?type=monitoring&tab=<?php echo $tab; ?>" class="btn btn-info btn-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
            </div>
        </div>
        <div class="content-body">
            <div class="workflow-info">
                <h6><i class="fas fa-info-circle"></i> Informasi Monitoring</h6>
                <p>Monitoring detail setiap transaksi closing dengan status tracking real-time. Data diurutkan berdasarkan prioritas status untuk memudahkan workflow keuangan pusat.</p>
            </div>
            
            <?php if ($setoran_list): ?>
                <div class="alert alert-info show" style="margin-bottom: 20px;">
                    <i class="fas fa-info-circle"></i>
                    Total transaksi closing ditemukan: <strong><?php echo count($setoran_list); ?> transaksi</strong>
                </div>

                <div class="table-container">
                    <div class="table-wrapper" style="overflow-x: auto; max-width: 100%;">
                        <div class="table-enhanced" style="min-width: 1800px;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th width="120">Kode Transaksi</th>
                                        <th width="100">Tanggal</th>
                                        <th width="80">Jam Closing</th>
                                        <th width="120">Cabang</th>
                                        <th width="100">Kasir</th>
                                        <th width="90">Jenis</th>
                                        <th width="120">Nominal Setoran</th>
                                        <th width="120">Diterima Fisik</th>
                                        <th width="80">Selisih</th>
                                        <th width="150">Status</th>
                                        <th width="200">Keterangan Status</th>
                                        <th width="120">Validasi</th>
                                        <th width="200">Catatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($setoran_list as $transaksi): ?>
                                        <tr>
                                            <td class="kode-transaksi">
                                                <?php echo htmlspecialchars($transaksi['kode_transaksi']); ?>
                                                <?php if ($transaksi['kode_setoran']): ?>
                                                    <br><small style="color: #666; font-size: 11px;">
                                                        <?php echo htmlspecialchars($transaksi['kode_setoran']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($transaksi['tanggal_transaksi'])); ?></td>
                                            <td><?php echo $transaksi['jam_closing'] ? date('H:i', strtotime($transaksi['jam_closing'])) : '-'; ?></td>
                                            <td><?php echo htmlspecialchars($transaksi['nama_cabang']); ?></td>
                                            <td><?php echo htmlspecialchars($transaksi['nama_karyawan']); ?></td>
                                            <td>
                                                <?php 
                                                switch($transaksi['jenis_transaksi']) {
                                                    case 'CLOSING':
                                                        echo '<span class="jenis-badge jenis-closing">CLOSING</span>';
                                                        break;
                                                    case 'DARI_CLOSING':
                                                        echo '<span class="jenis-badge jenis-gabungan">DARI CLOSING</span>';
                                                        break;
                                                    default:
                                                        echo '<span class="jenis-badge jenis-reguler">REGULER</span>';
                                                }
                                                ?>
                                            </td>
                                            <td class="text-right"><?php echo formatRupiah($transaksi['setoran_real']); ?></td>
                                            <td class="text-right">
                                                <?php 
                                                if ($transaksi['jumlah_diterima_fisik']) {
                                                    echo formatRupiah($transaksi['jumlah_diterima_fisik']);
                                                } else {
                                                    echo '<span style="color: #999;">Belum diterima</span>';
                                                }
                                                ?>
                                            </td>
                                            <td class="text-right">
                                                <?php 
                                                if ($transaksi['selisih_fisik'] != 0) {
                                                    $selisih_class = $transaksi['selisih_fisik'] > 0 ? 'text-success' : 'text-danger';
                                                    echo '<span class="' . $selisih_class . '">' . formatRupiah($transaksi['selisih_fisik']) . '</span>';
                                                } else {
                                                    echo '<span style="color: #999;">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                switch($transaksi['deposit_status']) {
                                                    case 'Sedang Dibawa Kurir':
                                                        echo '<span class="status-badge status-info">Sedang Dibawa Kurir</span>';
                                                        break;
                                                    case 'Diterima Staff Keuangan':
                                                        echo '<span class="status-badge status-warning">Diterima Staff Keuangan</span>';
                                                        break;
                                                    case 'Validasi Keuangan OK':
                                                        echo '<span class="status-badge status-success">Validasi OK</span>';
                                                        break;
                                                    case 'Validasi Keuangan SELISIH':
                                                        echo '<span class="status-badge status-danger">Validasi SELISIH</span>';
                                                        break;
                                                    case 'Dikembalikan ke CS':
                                                        echo '<span class="status-badge status-warning-alt"><i class="fas fa-undo"></i> Dikembalikan ke CS</span>';
                                                        break;
                                                    case 'Sudah Disetor ke Bank':
                                                        echo '<span class="status-badge status-success">Sudah Disetor ke Bank</span>';
                                                        break;
                                                    default:
                                                        echo '<span class="status-badge status-secondary">Belum Disetor</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <small style="color: #666; font-size: 12px; line-height: 1.4;">
                                                <?php
                                                switch($transaksi['deposit_status']) {
                                                    case 'Sedang Dibawa Kurir':
                                                        echo '📦 Setoran sedang dalam perjalanan dari cabang ke keuangan pusat';
                                                        break;
                                                    case 'Diterima Staff Keuangan':
                                                        echo '✅ Sudah diterima, menunggu validasi jumlah uang fisik';
                                                        break;
                                                    case 'Validasi Keuangan OK':
                                                        echo '✔️ Validasi selesai, jumlah uang sesuai dengan catatan';
                                                        break;
                                                    case 'Validasi Keuangan SELISIH':
                                                        echo '⚠️ Ada selisih antara catatan dengan uang fisik yang diterima';
                                                        break;
                                                    case 'Dikembalikan ke CS':
                                                        echo '🔄 Dikembalikan ke CS karena ada masalah yang perlu diperbaiki';
                                                        break;
                                                    case 'Sudah Disetor ke Bank':
                                                        echo '🏦 Proses selesai, uang sudah disetor ke rekening bank';
                                                        break;
                                                    default:
                                                        echo '❓ Status belum ditentukan atau belum memulai proses setoran';
                                                }
                                                ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php 
                                                if ($transaksi['validasi_at']) {
                                                    echo '<small style="color: #666;">' . date('d/m/Y H:i', strtotime($transaksi['validasi_at'])) . '</small>';
                                                } else {
                                                    echo '<span style="color: #999;">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if ($transaksi['catatan_validasi']) {
                                                    // Truncate long notes
                                                    $catatan = strip_tags($transaksi['catatan_validasi']);
                                                    if (strlen($catatan) > 50) {
                                                        echo '<span title="' . htmlspecialchars($catatan) . '">' . htmlspecialchars(substr($catatan, 0, 50)) . '...</span>';
                                                    } else {
                                                        echo htmlspecialchars($catatan);
                                                    }
                                                } else {
                                                    echo '<span style="color: #999;">-</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-search"></i><br>
                    Tidak ada transaksi closing ditemukan dengan kriteria filter yang dipilih
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
// PERBAIKAN: Lanjutan JavaScript dengan dukungan kalkulasi gabungan closing yang lebih baik

// Sidebar toggle functionality
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const sidebarToggle = document.getElementById('sidebarToggle');
    
    sidebar.classList.toggle('hidden');
    mainContent.classList.toggle('fullscreen');
    
    if (sidebar.classList.contains('hidden')) {
        sidebarToggle.innerHTML = '<i class="fas fa-bars"></i>';
    } else {
        sidebarToggle.innerHTML = '<i class="fas fa-times"></i>';
    }
}

// PERBAIKAN: Enhanced table scrolling functionality
function initializeTableScrolling() {
    const tableContainers = document.querySelectorAll('.table-container');
    
    tableContainers.forEach((container, index) => {
        // Add scroll wrapper if not exists
        const existingWrapper = container.querySelector('.table-wrapper');
        if (!existingWrapper) {
            const table = container.querySelector('.table');
            if (table) {
                const wrapper = document.createElement('div');
                wrapper.className = 'table-wrapper';
                table.parentNode.insertBefore(wrapper, table);
                wrapper.appendChild(table);
            }
        }
        
        const wrapper = container.querySelector('.table-wrapper');
        if (wrapper) {
            // Add scroll event listener untuk visual feedback
            wrapper.addEventListener('scroll', function() {
                const isScrolledLeft = this.scrollLeft > 10;
                const isScrolledRight = this.scrollLeft < (this.scrollWidth - this.clientWidth - 10);
                
                // Add/remove classes for styling
                container.classList.toggle('scrolled-left', isScrolledLeft);
                container.classList.toggle('scrolled-right', isScrolledRight);
                
                // Update scroll indicators
                updateScrollIndicators(container, this);
            });
            
            // Add keyboard navigation
            wrapper.addEventListener('keydown', function(e) {
                const scrollAmount = 100;
                
                switch(e.key) {
                    case 'ArrowLeft':
                        e.preventDefault();
                        this.scrollLeft -= scrollAmount;
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        this.scrollLeft += scrollAmount;
                        break;
                    case 'Home':
                        e.preventDefault();
                        this.scrollLeft = 0;
                        break;
                    case 'End':
                        e.preventDefault();
                        this.scrollLeft = this.scrollWidth;
                        break;
                }
            });
            
            // Make wrapper focusable for keyboard navigation
            wrapper.setAttribute('tabindex', '0');
            wrapper.setAttribute('role', 'region');
            wrapper.setAttribute('aria-label', 'Scrollable table');
            
            // Initial check
            wrapper.dispatchEvent(new Event('scroll'));
            
            // Show scroll hint for first table
            if (index === 0 && wrapper.scrollWidth > wrapper.clientWidth) {
                showScrollHint(container);
            }
        }
    });
}

// Function to update scroll indicators
function updateScrollIndicators(container, wrapper) {
    const isAtStart = wrapper.scrollLeft <= 10;
    const isAtEnd = wrapper.scrollLeft >= (wrapper.scrollWidth - wrapper.clientWidth - 10);
    
    // Update container classes
    container.classList.toggle('at-start', isAtStart);
    container.classList.toggle('at-end', isAtEnd);
    
    // Update scroll progress indicator if exists
    const progressIndicator = container.querySelector('.scroll-progress');
    if (progressIndicator) {
        const progress = (wrapper.scrollLeft / (wrapper.scrollWidth - wrapper.clientWidth)) * 100;
        progressIndicator.style.width = Math.min(100, Math.max(0, progress)) + '%';
    }
}

// Function to show scroll hint
function showScrollHint(container) {
    const hint = document.createElement('div');
    hint.className = 'scroll-hint';
    hint.innerHTML = '<i class="fas fa-arrows-alt-h"></i> Scroll untuk melihat kolom lainnya';
    
    container.style.position = 'relative';
    container.appendChild(hint);
    
    // Remove hint after animation
    setTimeout(() => {
        if (hint.parentNode) {
            hint.remove();
        }
    }, 3500);
}

// Function to add scroll progress indicator
function addScrollProgressIndicator() {
    const tableContainers = document.querySelectorAll('.table-container');
    
    tableContainers.forEach(container => {
        const wrapper = container.querySelector('.table-wrapper');
        if (wrapper && wrapper.scrollWidth > wrapper.clientWidth) {
            // Create progress container
            const progressContainer = document.createElement('div');
            progressContainer.className = 'scroll-progress-container';
            
            // Create progress bar
            const progressBar = document.createElement('div');
            progressBar.className = 'scroll-progress';
            
            progressContainer.appendChild(progressBar);
            container.appendChild(progressContainer);
        }
    });
}

// Enhanced smooth scrolling functions
function scrollTableTo(container, direction) {
    const wrapper = container.querySelector('.table-wrapper');
    if (!wrapper) return;
    
    const scrollAmount = wrapper.clientWidth * 0.8; // Scroll 80% of visible width
    const targetScroll = direction === 'left' 
        ? wrapper.scrollLeft - scrollAmount 
        : wrapper.scrollLeft + scrollAmount;
    
    // Smooth scroll
    wrapper.scrollTo({
        left: targetScroll,
        behavior: 'smooth'
    });
}

// Add scroll buttons for better UX
function addScrollButtons() {
    const tableContainers = document.querySelectorAll('.table-container');
    
    tableContainers.forEach(container => {
        const wrapper = container.querySelector('.table-wrapper');
        if (wrapper && wrapper.scrollWidth > wrapper.clientWidth) {
            // Left scroll button
            const leftButton = document.createElement('button');
            leftButton.innerHTML = '<i class="fas fa-chevron-left"></i>';
            leftButton.className = 'table-scroll-btn table-scroll-left';
            
            // Right scroll button
            const rightButton = document.createElement('button');
            rightButton.innerHTML = '<i class="fas fa-chevron-right"></i>';
            rightButton.className = 'table-scroll-btn table-scroll-right';
            rightButton.style.opacity = '1';
            
            // Add event listeners
            leftButton.addEventListener('click', () => scrollTableTo(container, 'left'));
            rightButton.addEventListener('click', () => scrollTableTo(container, 'right'));
            
            // Add hover effects
            [leftButton, rightButton].forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    this.style.background = 'rgba(0,123,255,1)';
                    this.style.transform = 'translateY(-50%) scale(1.1)';
                });
                
                btn.addEventListener('mouseleave', function() {
                    this.style.background = 'rgba(0,123,255,0.9)';
                    this.style.transform = 'translateY(-50%) scale(1)';
                });
            });
            
            container.appendChild(leftButton);
            container.appendChild(rightButton);
            
            // Update button visibility on scroll
            wrapper.addEventListener('scroll', function() {
                const isAtStart = this.scrollLeft <= 10;
                const isAtEnd = this.scrollLeft >= (this.scrollWidth - this.clientWidth - 10);
                
                leftButton.style.opacity = isAtStart ? '0' : '1';
                rightButton.style.opacity = isAtEnd ? '0' : '1';
            });
        }
    });
}

// Touch/swipe support for mobile
function addTouchSupport() {
    const tableWrappers = document.querySelectorAll('.table-wrapper');
    
    tableWrappers.forEach(wrapper => {
        let startX = 0;
        let scrollStart = 0;
        let isDragging = false;
        
        wrapper.addEventListener('touchstart', function(e) {
            startX = e.touches[0].clientX;
            scrollStart = this.scrollLeft;
            isDragging = true;
            this.style.scrollBehavior = 'auto';
        }, { passive: true });
        
        wrapper.addEventListener('touchmove', function(e) {
            if (!isDragging) return;
            
            const currentX = e.touches[0].clientX;
            const diffX = startX - currentX;
            
            // Only prevent default if we're actually scrolling horizontally
            if (Math.abs(diffX) > 2) {
                e.preventDefault();
                this.scrollLeft = scrollStart + diffX;
            }
        }, { passive: false });
        
        wrapper.addEventListener('touchend', function() {
            isDragging = false;
            this.style.scrollBehavior = 'smooth';
        }, { passive: true });
        
        wrapper.addEventListener('touchcancel', function() {
            isDragging = false;
            this.style.scrollBehavior = 'smooth';
        }, { passive: true });
    });
}

// Re-initialize when content changes (for dynamic content)
function reinitializeTableScrolling() {
    // Remove existing elements
    document.querySelectorAll('.scroll-progress-container, .table-scroll-btn, .scroll-hint').forEach(el => el.remove());
    
    // Re-initialize
    setTimeout(() => {
        initializeTableScrolling();
        addScrollProgressIndicator();
        addTouchSupport();
    }, 100);
}

// Auto-hide sidebar on bank_history tab
document.addEventListener('DOMContentLoaded', function() {
    const currentTab = '<?php echo $tab; ?>';
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const sidebarToggle = document.getElementById('sidebarToggle');
    
    if (currentTab === 'bank_history') {
        sidebar.classList.add('hidden');
        mainContent.classList.add('fullscreen');
        sidebarToggle.classList.add('show');
        sidebarToggle.innerHTML = '<i class="fas fa-bars"></i>';
    } else {
        sidebar.classList.remove('hidden');
        mainContent.classList.remove('fullscreen');
        sidebarToggle.classList.remove('show');
    }
    
    // Initialize closing transaction highlighting
    initializeClosingTransactionHighlighting();
    
    // Initialize enhanced tooltips for closing transactions
    initializeClosingTooltips();
    
    // PERBAIKAN: Initialize table scroll untuk tabel yang lebar
    initializeTableScrolling();
    addScrollProgressIndicator();
    addTouchSupport();
});

// Initialize closing transaction highlighting
function initializeClosingTransactionHighlighting() {
    const closingRows = document.querySelectorAll('.closing-transaction');
    closingRows.forEach(row => {
        // Add hover effect
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgba(156,39,176,0.15)';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = 'rgba(156,39,176,0.05)';
        });
        
        // Add pulse animation for new closing transactions
        if (row.querySelector('.closing-info-badge')) {
            row.style.animation = 'pulse 2s infinite';
        }
    });
}

// PERBAIKAN: Initialize enhanced tooltips for closing transactions dengan info gabungan
function initializeClosingTooltips() {
    const closingBadges = document.querySelectorAll('.closing-info-badge');
    closingBadges.forEach(badge => {
        badge.title = 'Transaksi Closing: Gabungan dari transaksi closing, transaksi yang dipinjam, dan transaksi yang meminjam per cabang';
        badge.style.cursor = 'help';
    });
    
    const closingTransactions = document.querySelectorAll('.closing-transaction');
    closingTransactions.forEach(row => {
        row.title = 'Baris ini mengandung transaksi closing gabungan';
        row.style.cursor = 'pointer';
    });
    
    // PERBAIKAN: Add tooltips untuk closing borrowed info
    const closingBorrowedInfos = document.querySelectorAll('.closing-borrowed-info');
    closingBorrowedInfos.forEach(info => {
        info.title = 'Informasi gabungan: menampilkan jumlah yang dipinjam dan yang seharusnya diterima secara fisik';
        info.style.cursor = 'help';
    });
}

// Initialize currency formatting for validation inputs
document.getElementById('jumlahDiterima')?.addEventListener('input', function(e) {
    formatCurrencyInputValue(e);
    const isClosing = checkIfClosingTransaction();
    hitungSelisihTransaksi(isClosing);
});

document.getElementById('jumlahDiterimaBaru')?.addEventListener('input', function(e) {
    formatCurrencyInputValue(e);
    const isClosing = checkIfClosingTransaction();
    hitungSelisihEdit(isClosing);
});

// Helper function to format currency input value
function formatCurrencyInputValue(e) {
    let value = e.target.value.replace(/[^0-9]/g, '');
    if (value) {
        value = parseInt(value).toLocaleString('id-ID');
        e.target.value = 'Rp ' + value;
    } else {
        e.target.value = '';
    }
}

// PERBAIKAN: Enhanced function to check if current transaction is closing dengan context gabungan
function checkIfClosingTransaction() {
    // Check from PHP data or modal indicators
    const closingBadge = document.querySelector('.closing-info-badge');
    const closingInfo = document.querySelector('.closing-validation-info');
    
    // Check juga dari data transaksi yang ada
    const closingBorrowedInfo = document.querySelector('.closing-borrowed-info');
    
    return closingBadge !== null || closingInfo !== null || closingBorrowedInfo !== null;
}

// PERBAIKAN: Function untuk mendapatkan data closing borrowed dari PHP
function getClosingBorrowedAmount() {
    // Ambil dari PHP data yang sudah di-pass ke JavaScript
    const transaksiDetail = <?php echo json_encode($transaksi_detail ?? null); ?>;
    const editSelisihDetail = <?php echo json_encode($edit_selisih_detail ?? null); ?>;
    
    let borrowedAmount = 0;
    
    if (transaksiDetail && transaksiDetail.total_closing_borrowed) {
        borrowedAmount = parseFloat(transaksiDetail.total_closing_borrowed) || 0;
    } else if (editSelisihDetail && editSelisihDetail.total_closing_borrowed) {
        borrowedAmount = parseFloat(editSelisihDetail.total_closing_borrowed) || 0;
    }
    
    return borrowedAmount;
}

// PERBAIKAN: Enhanced calculation function untuk transaksi validation dengan closing support dan kalkulasi gabungan
function hitungSelisihTransaksi(isClosing = false) {
    const sistemAmount = <?php echo isset($transaksi_detail['setoran_real']) ? $transaksi_detail['setoran_real'] : 0; ?>;
    const borrowedAmount = getClosingBorrowedAmount();
    
    let diterima = document.getElementById('jumlahDiterima')?.value.replace(/[^0-9]/g, '') || 0;
    diterima = parseInt(diterima) || 0;

    // PERBAIKAN: Kalkulasi selisih dengan mempertimbangkan gabungan closing
    let expectedAmount = sistemAmount;
    if (isClosing && borrowedAmount > 0) {
        // Untuk transaksi closing dengan pinjaman, yang diharapkan diterima = setoran_real - yang dipinjam
        expectedAmount = sistemAmount - borrowedAmount;
    }

    const selisih = diterima - expectedAmount;
    const selisihRow = document.getElementById('selisihRow');
    const selisihAmount = document.getElementById('selisihAmount');

    if (selisihRow && selisihAmount) {
        if (selisih !== 0) {
            selisihRow.style.display = 'block';
            
            let selisihText = '';
            let selisihColor = '';
            
            if (selisih > 0) {
                selisihText = '<i class="fas fa-arrow-up"></i> Rp ' + selisih.toLocaleString('id-ID');
                selisihColor = 'var(--success-color)';
            } else {
                selisihText = '<i class="fas fa-arrow-down"></i> Rp ' + Math.abs(selisih).toLocaleString('id-ID');
                selisihColor = 'var(--danger-color)';
            }
            
            selisihAmount.style.color = selisihColor;
            selisihAmount.innerHTML = selisihText;
            
            // PERBAIKAN: Add closing transaction indicator dengan info gabungan
            if (isClosing) {
                let closingInfo = ' <span class="closing-info-badge" style="margin-left: 5px;">CLOSING</span>';
                if (borrowedAmount > 0) {
                    closingInfo += '<br><small style="font-size: 10px; color: var(--text-muted);">Dipinjam: Rp ' + borrowedAmount.toLocaleString('id-ID') + '</small>';
                }
                selisihAmount.innerHTML += closingInfo;
            }
        } else {
            selisihRow.style.display = 'none';
        }
    }
    
    // Update validation button text for closing transactions
    updateValidationButtonText(isClosing, selisih, borrowedAmount);
}
// PERBAIKAN: Enhanced calculation function untuk edit selisih dengan closing support dan kalkulasi gabungan
function hitungSelisihEdit(isClosing = false) {
    const sistemAmount = <?php echo isset($edit_selisih_detail['setoran_real']) ? $edit_selisih_detail['setoran_real'] : 0; ?>;
    const borrowedAmount = getClosingBorrowedAmount();
    
    let diterima = document.getElementById('jumlahDiterimaBaru')?.value.replace(/[^0-9]/g, '') || 0;
    diterima = parseInt(diterima) || 0;

    // PERBAIKAN: Kalkulasi selisih dengan mempertimbangkan gabungan closing
    let expectedAmount = sistemAmount;
    if (isClosing && borrowedAmount > 0) {
        // Untuk transaksi closing dengan pinjaman, yang diharapkan diterima = setoran_real - yang dipinjam
        expectedAmount = sistemAmount - borrowedAmount;
    }

    const selisih = diterima - expectedAmount;
    const selisihRow = document.getElementById('selisihEditRow');
    const selisihAmount = document.getElementById('selisihEditAmount');

    if (selisihRow && selisihAmount) {
        let selisihText = '';
        let selisihColor = '';
        
        if (selisih > 0) {
            selisihText = '<i class="fas fa-arrow-up"></i> Rp ' + selisih.toLocaleString('id-ID');
            selisihColor = 'var(--success-color)';
        } else if (selisih < 0) {
            selisihText = '<i class="fas fa-arrow-down"></i> Rp ' + Math.abs(selisih).toLocaleString('id-ID');
            selisihColor = 'var(--danger-color)';
        } else {
            selisihText = '<i class="fas fa-check"></i> Sesuai Sistem';
            selisihColor = 'var(--text-dark)';
        }
        
        selisihAmount.style.color = selisihColor;
        selisihAmount.innerHTML = selisihText;
        
        // PERBAIKAN: Add closing transaction indicator dengan info gabungan
        if (isClosing) {
            let closingInfo = ' <span class="closing-info-badge" style="margin-left: 5px;">CLOSING</span>';
            if (borrowedAmount > 0) {
                closingInfo += '<br><small style="font-size: 10px; color: var(--text-muted);">Dipinjam: Rp ' + borrowedAmount.toLocaleString('id-ID') + '</small>';
            }
            selisihAmount.innerHTML += closingInfo;
        }
    }
    
    // Update edit button text for closing transactions
    updateEditButtonText(isClosing, selisih, borrowedAmount);
}

// PERBAIKAN: Function to update validation button text based on transaction type dengan info gabungan
function updateValidationButtonText(isClosing, selisih, borrowedAmount = 0) {
    const validationButton = document.querySelector('button[name="validasi_individual"]');
    if (validationButton) {
        let buttonText = '<i class="fas fa-save"></i> Simpan Validasi';
        
        if (isClosing) {
            buttonText += ' Closing';
            
            if (borrowedAmount > 0) {
                buttonText += ' (Gabungan)';
            }
            
            if (selisih !== 0) {
                buttonText = '<i class="fas fa-exclamation-triangle"></i> Simpan Validasi Closing (Ada Selisih)';
                validationButton.className = 'btn btn-warning';
            } else {
                buttonText = '<i class="fas fa-check"></i> Simpan Validasi Closing (Sesuai)';
                validationButton.className = 'btn btn-success';
            }
        } else {
            if (selisih !== 0) {
                validationButton.className = 'btn btn-warning';
            } else {
                validationButton.className = 'btn btn-primary';
            }
        }
        
        validationButton.innerHTML = buttonText;
    }
}

// PERBAIKAN: Function to update edit button text based on transaction type dengan info gabungan
function updateEditButtonText(isClosing, selisih, borrowedAmount = 0) {
    const editButton = document.querySelector('button[name="edit_selisih"]');
    if (editButton) {
        let buttonText = '<i class="fas fa-save"></i> Simpan Perubahan';
        
        if (isClosing) {
            buttonText += ' Closing';
            
            if (borrowedAmount > 0) {
                buttonText += ' (Gabungan)';
            }
            
            if (selisih !== 0) {
                buttonText = '<i class="fas fa-exclamation-triangle"></i> Simpan Perubahan Closing (Ada Selisih)';
                editButton.className = 'btn btn-warning';
            } else {
                buttonText = '<i class="fas fa-check"></i> Simpan Perubahan Closing (Sesuai)';
                editButton.className = 'btn btn-success';
            }
        } else {
            if (selisih !== 0) {
                editButton.className = 'btn btn-warning';
            } else {
                editButton.className = 'btn btn-primary';
            }
        }
        
        editButton.innerHTML = buttonText;
    }
}

// Enhanced filter function with closing transaction awareness
function filterByCabang(rekeningId) {
    console.log('Selected rekening ID:', rekeningId);
    
    if (rekeningId === '' || rekeningId === 'all') {
        window.location.href = '?tab=setor_bank&rekening_filter=all';
    } else {
        // Send ALL IDs for proper gabungan filtering
        window.location.href = '?tab=setor_bank&rekening_filter=' + encodeURIComponent(rekeningId);
    }
}

// Enhanced form submission validation for setor bank with closing support
let isFormSubmittingFinal = false; // Flag to allow final submission

document.getElementById('setorBankForm')?.addEventListener('submit', function(e) {
    const rekeningCabang = document.getElementById('rekeningCabang');
    const checkedBoxes = document.querySelectorAll('.bankCheckbox:checked');
    
    if (!rekeningCabang.value || rekeningCabang.value === '') {
        e.preventDefault();
        showNotification('Pilih rekening cabang tujuan terlebih dahulu.', 'warning');
        rekeningCabang.focus();
        return false;
    }
    
    if (checkedBoxes.length === 0) {
        e.preventDefault();
        showNotification('Pilih setoran yang akan disetor ke bank.', 'warning');
        return false;
    }
    
    // Check if any closing transactions are included
    const closingTransactions = document.querySelectorAll('.bankCheckbox:checked').length;
    let hasClosingTransactions = false;
    
    checkedBoxes.forEach(checkbox => {
        const row = checkbox.closest('tr');
        if (row && row.classList.contains('closing-transaction')) {
            hasClosingTransactions = true;
        }
    });
    
    let confirmMessage = 'Yakin ingin setor ke bank? Pastikan semua data sudah benar.';
    if (hasClosingTransactions) {
        confirmMessage = 'Yakin ingin setor ke bank? Termasuk transaksi closing. Pastikan semua data sudah benar.';
    }
    
    if (!confirm(confirmMessage)) {
        e.preventDefault();
        return false;
    }
    
    return true;
});

// PERBAIKAN: Enhanced setoran summary dengan closing transaction details dan kalkulasi gabungan
function showSetoranSummary() {
    const checkedBoxes = document.querySelectorAll('.bankCheckbox:checked');
    if (checkedBoxes.length === 0) {
        showNotification('Pilih setoran yang akan disetor terlebih dahulu.', 'warning');
        return;
    }
    
    let totalAmount = 0;
    let setoranList = [];
    let closingCount = 0;
    let totalClosingAmount = 0;
    
    checkedBoxes.forEach(checkbox => {
        const row = checkbox.closest('tr');
        const kodeSetoran = row.querySelector('code').textContent;
        const cabang = row.cells[3].textContent;
        const nominalText = row.cells[5].textContent;
        const nominal = parseInt(nominalText.replace(/[^0-9]/g, ''));
        const isClosing = row.classList.contains('closing-transaction');
        
        if (isClosing) {
            closingCount++;
            totalClosingAmount += nominal;
        }
        
        totalAmount += nominal;
        setoranList.push({
            kode: kodeSetoran,
            cabang: cabang,
            nominal: nominal,
            isClosing: isClosing
        });
    });
    
    let summaryHTML = `
        <div style="background: white; padding: 20px; border-radius: 12px; border: 1px solid var(--border-color);">
            <h4 style="margin-bottom: 15px; color: var(--text-dark);">
                <i class="fas fa-calculator"></i> Ringkasan Setoran ke Bank
            </h4>
            <div style="margin-bottom: 15px;">
                <strong>Jumlah Setoran Dipilih:</strong> ${setoranList.length} paket<br>
                ${closingCount > 0 ? `<strong>Transaksi Closing Gabungan:</strong> <span style="color: var(--closing-color);">${closingCount} paket (${formatRupiah(totalClosingAmount)})</span><br>` : ''}
                <strong>Total Nominal:</strong> <span style="color: var(--success-color); font-size: 18px; font-weight: bold;">Rp ${totalAmount.toLocaleString('id-ID')}</span>
            </div>
            ${closingCount > 0 ? `
            <div style="background: rgba(156,39,176,0.1); padding: 10px; border-radius: 8px; margin-bottom: 15px; border: 1px solid rgba(156,39,176,0.2);">
                <small style="color: var(--closing-color); font-weight: 600;">
                    <i class="fas fa-info-circle"></i> ${closingCount} setoran mengandung transaksi closing gabungan yang merupakan hasil dari transaksi closing, transaksi yang dipinjam, dan transaksi yang meminjam per cabang. Total nilai closing: ${formatRupiah(totalClosingAmount)}.
                </small>
            </div>
            ` : ''}
            <div style="max-height: 200px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px; padding: 10px;">
                <table style="width: 100%; font-size: 12px;">
                    <thead>
                        <tr style="background: var(--background-light);">
                            <th style="padding: 5px; text-align: left;">Kode Setoran</th>
                            <th style="padding: 5px; text-align: left;">Cabang</th>
                            <th style="padding: 5px; text-align: center;">Jenis</th>
                            <th style="padding: 5px; text-align: right;">Nominal</th>
                        </tr>
                    </thead>
                    <tbody>
    `;
    
    setoranList.forEach(setoran => {
        const jenisLabel = setoran.isClosing ? 
            '<span class="status-badge bg-closing" style="font-size: 9px;">CLOSING</span>' : 
            '<span class="status-badge bg-primary" style="font-size: 9px;">BIASA</span>';
            
        summaryHTML += `
            <tr class="${setoran.isClosing ? 'closing-transaction' : ''}">
                <td style="padding: 3px;">${setoran.kode}</td>
                <td style="padding: 3px;">${setoran.cabang}</td>
                <td style="padding: 3px; text-align: center;">${jenisLabel}</td>
                <td style="padding: 3px; text-align: right;">Rp ${setoran.nominal.toLocaleString('id-ID')}</td>
            </tr>
        `;
    });
    
    summaryHTML += `
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 15px; text-align: right;">
                <button onclick="closeSummary()" class="btn btn-secondary btn-sm" style="margin-right: 10px;">
                    <i class="fas fa-times"></i> Tutup
                </button>
                <button onclick="proceedWithDeposit()" class="btn btn-success btn-sm">
                    <i class="fas fa-university"></i> Lanjut Setor${closingCount > 0 ? ' (Termasuk ' + closingCount + ' Closing Gabungan)' : ''}
                </button>
            </div>
        </div>
    `;
    
    showModal('summaryModal', summaryHTML);
}

// PERBAIKAN: Helper function untuk format rupiah
function formatRupiah(amount) {
    return 'Rp ' + amount.toLocaleString('id-ID');
}

// Enhanced proceed with deposit function
function proceedWithDeposit() {
    closeSummary();
    const form = document.getElementById('setorBankForm');
    const checkedBoxes = document.querySelectorAll('.bankCheckbox:checked');
    
    if (!form) {
        showNotification('Form tidak ditemukan', 'danger');
        return;
    }
    
    let closingCount = 0;
    let totalClosingAmount = 0;
    
    checkedBoxes.forEach(checkbox => {
        const row = checkbox.closest('tr');
        if (row && row.classList.contains('closing-transaction')) {
            closingCount++;
            const nominalText = row.cells[5].textContent;
            const nominal = parseInt(nominalText.replace(/[^0-9]/g, ''));
            totalClosingAmount += nominal;
        }
    });
    
    const confirmMessage = closingCount > 0 ? 
        `Yakin ingin setor ke bank? Termasuk ${closingCount} transaksi closing gabungan dengan total ${formatRupiah(totalClosingAmount)}. Pastikan semua data sudah benar.` :
        'Yakin ingin setor ke bank? Pastikan semua data sudah benar.';
        
    if (confirm(confirmMessage)) {
        // Set flag to allow final submission
        isFormSubmittingFinal = true;
        console.log('Submitting form with flag set');
        form.submit();
    }
}

// Direct submission function that bypasses modal validation
function submitDirectly() {
    const form = document.getElementById('setorBankForm');
    const rekeningCabang = document.getElementById('rekeningCabang');
    const checkedBoxes = document.querySelectorAll('.bankCheckbox:checked');
    
    if (!rekeningCabang || !rekeningCabang.value || rekeningCabang.value === '') {
        showNotification('Pilih rekening cabang tujuan terlebih dahulu.', 'warning');
        if (rekeningCabang) rekeningCabang.focus();
        return false;
    }
    
    if (checkedBoxes.length === 0) {
        showNotification('Pilih setoran yang akan disetor ke bank.', 'warning');
        return false;
    }
    
    if (confirm('Yakin ingin setor ke bank secara langsung? Pastikan semua data sudah benar.')) {
        // Set flag and submit directly
        isFormSubmittingFinal = true;
        console.log('Direct submission initiated');
        form.submit();
    }
}

// Generic modal display function
function showModal(modalId, content) {
    // Remove existing modal
    const existingModal = document.getElementById(modalId);
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create modal overlay
    const modalOverlay = document.createElement('div');
    modalOverlay.id = modalId;
    modalOverlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
    `;
    
    const modalContent = document.createElement('div');
    modalContent.style.cssText = `
        max-width: 600px;
        max-height: 80%;
        overflow-y: auto;
        margin: 20px;
    `;
    modalContent.innerHTML = content;
    
    modalOverlay.appendChild(modalContent);
    document.body.appendChild(modalOverlay);
    
    // Close on overlay click
    modalOverlay.addEventListener('click', function(e) {
        if (e.target === modalOverlay) {
            modalOverlay.remove();
        }
    });
}

// Close summary modal
function closeSummary() {
    const modal = document.getElementById('summaryModal');
    if (modal) {
        modal.remove();
    }
}

// Close receipt card
function closeReceipt() {
    const receiptCard = document.querySelector('.receipt-card');
    if (receiptCard) {
        receiptCard.style.display = 'none';
    }
}

// Select all checkboxes functionality with closing transaction awareness
document.getElementById('selectAllTerima')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.terimaCheckbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    
    // Show info about closing transactions if any
    if (this.checked) {
        const closingCount = document.querySelectorAll('.closing-transaction .terimaCheckbox').length;
        if (closingCount > 0) {
            showNotification(`Dipilih ${checkboxes.length} setoran, termasuk ${closingCount} dengan transaksi closing gabungan.`, 'info');
        }
    }
});

document.getElementById('selectAllBank')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.bankCheckbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    
    updateSummaryButtonVisibility();
    
    // Show info about closing transactions if any
    if (this.checked) {
        const closingCount = document.querySelectorAll('.closing-transaction .bankCheckbox').length;
        if (closingCount > 0) {
            showNotification(`Dipilih ${checkboxes.length} setoran, termasuk ${closingCount} dengan transaksi closing gabungan.`, 'info');
        }
    }
});

// Update summary button visibility and text
document.querySelectorAll('.bankCheckbox').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        updateSummaryButtonVisibility();
        updateSelectAllCheckbox();
        
        // PERBAIKAN: Update button text based on closing transactions dengan info gabungan
        const checkedBoxes = document.querySelectorAll('.bankCheckbox:checked');
        let closingCount = 0;
        let totalClosingAmount = 0;
        
        checkedBoxes.forEach(cb => {
            const row = cb.closest('tr');
            if (row && row.classList.contains('closing-transaction')) {
                closingCount++;
                const nominalText = row.cells[5].textContent;
                const nominal = parseInt(nominalText.replace(/[^0-9]/g, ''));
                totalClosingAmount += nominal;
            }
        });
        
        const summaryButton = document.querySelector('button[onclick="showSetoranSummary()"]');
        if (summaryButton && checkedBoxes.length > 0) {
            let buttonText = '<i class="fas fa-calculator"></i> Lihat Ringkasan';
            if (closingCount > 0) {
                buttonText += ` (${closingCount} Closing: ${formatRupiah(totalClosingAmount)})`;
            }
            summaryButton.innerHTML = buttonText;
        }
    });
});

function updateSummaryButtonVisibility() {
    const checkedCount = document.querySelectorAll('.bankCheckbox:checked').length;
    const summaryButton = document.querySelector('button[onclick="showSetoranSummary()"]');
    if (summaryButton) {
        summaryButton.style.display = checkedCount > 0 ? 'inline-flex' : 'none';
    }
}

function updateSelectAllCheckbox() {
    const checkedCount = document.querySelectorAll('.bankCheckbox:checked').length;
    const totalCheckboxes = document.querySelectorAll('.bankCheckbox').length;
    const selectAllBank = document.getElementById('selectAllBank');
    
    if (selectAllBank) {
        selectAllBank.checked = checkedCount === totalCheckboxes;
        selectAllBank.indeterminate = checkedCount > 0 && checkedCount < totalCheckboxes;
    }
}

// Enhanced notification system with closing transaction support
function showNotification(message, type = 'info', duration = 5000) {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} show`;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 1050;
        min-width: 300px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        animation: slideIn 0.3s ease-out;
    `;
    
    const iconMap = {
        'success': 'check-circle',
        'danger': 'exclamation-circle',
        'warning': 'exclamation-triangle',
        'info': 'info-circle'
    };
    
    notification.innerHTML = `
        <i class="fas fa-${iconMap[type] || 'info-circle'}"></i>
        ${message}
        <button type="button" class="btn-close" onclick="this.parentElement.remove()" style="margin-left: auto;">&times;</button>
    `;
    
    document.body.appendChild(notification);
    
    // Auto remove
    setTimeout(() => {
        if (notification.parentNode) {
            notification.style.animation = 'slideOut 0.3s ease-in';
            setTimeout(() => notification.remove(), 300);
        }
    }, duration);
}

// Close modals when clicking outside with closing transaction context
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            const currentTab = '<?php echo $tab; ?>';
            const isClosingModal = this.querySelector('.closing-validation-info') !== null;
            
            if (isClosingModal) {
                const confirmMessage = 'Menutup modal validasi transaksi closing. Data yang dimasukkan akan hilang. Lanjutkan?';
                if (!confirm(confirmMessage)) {
                    return;
                }
            }
            
            // Redirect based on current tab
            if (currentTab === 'validasi') {
                window.location.href = '?tab=validasi';
            } else if (currentTab === 'validasi_selisih') {
                window.location.href = '?tab=validasi_selisih';
            } else if (currentTab === 'bank_history') {
                window.location.href = '?tab=bank_history';
            } else {
                window.location.href = '?tab=' + currentTab;
            }
        }
    });
});

// Auto hide alerts with enhanced timing for closing transactions
document.querySelectorAll('.alert.show').forEach(alert => {
    const isClosingAlert = alert.textContent.includes('CLOSING') || alert.textContent.includes('closing');
    const duration = isClosingAlert ? 7000 : 5000; // Longer display for closing alerts
    
    setTimeout(() => {
        alert.style.animation = 'fadeOut 0.5s ease-out';
        setTimeout(() => alert.classList.remove('show'), 500);
    }, duration);
});

// Enhanced table interactions with closing transaction awareness
document.querySelectorAll('.table tbody tr').forEach(row => {
    const isClosing = row.classList.contains('closing-transaction');
    
    row.addEventListener('mouseenter', function() {
        if (isClosing) {
            this.style.backgroundColor = 'rgba(156,39,176,0.15)';
            this.style.borderLeft = '4px solid var(--closing-color)';
        } else {
            this.style.backgroundColor = 'rgba(0,123,255,0.05)';
        }
    });
    
    row.addEventListener('mouseleave', function() {
        if (isClosing) {
            this.style.backgroundColor = 'rgba(156,39,176,0.05)';
            this.style.borderLeft = '4px solid var(--closing-color)';
        } else {
            this.style.backgroundColor = '';
            this.style.borderLeft = '';
        }
    });
});

// Keyboard shortcuts with closing transaction support
document.addEventListener('keydown', function(e) {
    // Ctrl + P for print (when modal is open)
    if (e.ctrlKey && e.key === 'p') {
        const modal = document.querySelector('.modal.show');
        if (modal) {
            e.preventDefault();
            window.print();
        }
    }
    
    // Escape to close modals with closing transaction confirmation
    if (e.key === 'Escape') {
        const modal = document.querySelector('.modal.show');
        if (modal) {
            const isClosingModal = modal.querySelector('.closing-validation-info') !== null;
            
            if (isClosingModal) {
                const confirmMessage = 'Menutup modal transaksi closing. Data yang dimasukkan akan hilang. Lanjutkan?';
                if (!confirm(confirmMessage)) {
                    return;
                }
            }
            
            const closeButton = modal.querySelector('.btn-close');
            if (closeButton) {
                closeButton.click();
            }
        }
        
        // Close summary modal
        const summaryModal = document.getElementById('summaryModal');
        if (summaryModal) {
            closeSummary();
        }
        
        // Close sidebar on mobile
        if (window.innerWidth <= 768) {
            const sidebar = document.getElementById('sidebar');
            if (sidebar && !sidebar.classList.contains('hidden')) {
                toggleSidebar();
            }
        }
    }
    
    // Ctrl + B to toggle sidebar
    if (e.ctrlKey && e.key === 'b') {
        e.preventDefault();
        toggleSidebar();
    }
    
    // Ctrl + C to show closing transaction info (when viewing tables)
    if (e.ctrlKey && e.key === 'c' && !e.target.matches('input, textarea')) {
        e.preventDefault();
        showClosingTransactionSummary();
    }
});
// Function to show closing transaction summary
function showClosingTransactionSummary() {
    const closingRows = document.querySelectorAll('.closing-transaction');
    if (closingRows.length === 0) {
        showNotification('Tidak ada transaksi closing dalam tampilan saat ini.', 'info');
        return;
    }
    
    let summaryHTML = `
        <div style="background: white; padding: 20px; border-radius: 12px; border: 1px solid var(--border-color);">
            <h4 style="margin-bottom: 15px; color: var(--closing-color);">
                <i class="fas fa-sync-alt"></i> Ringkasan Transaksi Closing
            </h4>
            <div style="margin-bottom: 15px;">
                <p><strong>Total Transaksi Closing:</strong> ${closingRows.length}</p>
                <p style="font-size: 14px; color: var(--text-muted);">
                    Transaksi closing adalah gabungan dari transaksi closing, transaksi yang dipinjam, dan transaksi yang meminjam per cabang.
                </p>
            </div>
            <div style="text-align: right;">
                <button onclick="closeClosingSummary()" class="btn btn-secondary btn-sm">
                    <i class="fas fa-times"></i> Tutup
                </button>
            </div>
        </div>
    `;
    
    showModal('closingSummaryModal', summaryHTML);
}

function closeClosingSummary() {
    const modal = document.getElementById('closingSummaryModal');
    if (modal) {
        modal.remove();
    }
}

// Mobile touch handlers with closing transaction support
let touchStartX = 0;
let touchEndX = 0;

document.addEventListener('touchstart', function(e) {
    touchStartX = e.changedTouches[0].screenX;
}, { passive: true });

document.addEventListener('touchend', function(e) {
    touchEndX = e.changedTouches[0].screenX;
    handleGesture();
}, { passive: true });

function handleGesture() {
    const swipeThreshold = 100;
    const swipeDistance = touchEndX - touchStartX;
    
    if (window.innerWidth <= 768) {
        // Swipe right to open sidebar
        if (swipeDistance > swipeThreshold) {
            const sidebar = document.getElementById('sidebar');
            if (sidebar && sidebar.classList.contains('hidden')) {
                toggleSidebar();
            }
        }
        
        // Swipe left to close sidebar
        if (swipeDistance < -swipeThreshold) {
            const sidebar = document.getElementById('sidebar');
            if (sidebar && !sidebar.classList.contains('hidden')) {
                toggleSidebar();
            }
        }
    }
}

// Responsive adjustments with closing transaction considerations
window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const currentTab = '<?php echo $tab; ?>';
    
    if (window.innerWidth > 768) {
        // Desktop: restore normal layout except for bank_history
        if (currentTab !== 'bank_history') {
            sidebar.classList.remove('hidden');
            mainContent.classList.remove('fullscreen');
            sidebarToggle.classList.remove('show');
        }
    } else {
        // Mobile: always show toggle button
        sidebarToggle.classList.add('show');
    }
    
    // Adjust closing transaction highlighting for mobile
    const closingTransactions = document.querySelectorAll('.closing-transaction');
    closingTransactions.forEach(row => {
        if (window.innerWidth <= 768) {
            row.style.borderLeft = '2px solid var(--closing-color)';
        } else {
            row.style.borderLeft = '4px solid var(--closing-color)';
        }
    });
    
    // Re-initialize table scrolling after resize
    setTimeout(() => {
        reinitializeTableScrolling();
    }, 300);
});

// Initialize page with closing transaction support
document.addEventListener('DOMContentLoaded', function() {
    // Check if we're on mobile
    if (window.innerWidth <= 768) {
        const sidebarToggle = document.getElementById('sidebarToggle');
        sidebarToggle.classList.add('show');
    }
    
    // Auto-focus on first input in modals
    const modal = document.querySelector('.modal.show');
    if (modal) {
        const firstInput = modal.querySelector('input[type="text"], input[type="number"], textarea');
        if (firstInput) {
            setTimeout(() => {
                firstInput.focus();
                // Special handling for closing transaction modals
                const isClosingModal = modal.querySelector('.closing-validation-info') !== null;
                if (isClosingModal) {
                    showNotification('Modal transaksi closing dibuka. Perhatikan informasi gabungan transaksi.', 'info', 3000);
                }
            }, 300);
        }
    }
    
    // Initialize summary button visibility
    updateSummaryButtonVisibility();
    
    // Initialize closing transaction calculations
    const isClosing = checkIfClosingTransaction();
    if (document.getElementById('jumlahDiterima')) {
        hitungSelisihTransaksi(isClosing);
    }
    if (document.getElementById('jumlahDiterimaBaru')) {
        hitungSelisihEdit(isClosing);
    }
});

// Export functionality helpers with closing transaction info
function exportToExcel(type, additionalParams = '') {
    const currentTab = '<?php echo $tab; ?>';
    let url = `export_excel_setoran.php?type=${type}&tab=${currentTab}`;
    
    // Add additional parameters if provided
    if (additionalParams) {
        url += '&' + additionalParams;
    }
    
    // Add current filter parameters
    const urlParams = new URLSearchParams(window.location.search);
    const relevantParams = ['tanggal_awal', 'tanggal_akhir', 'cabang', 'rekening_filter'];
    
    relevantParams.forEach(param => {
        if (urlParams.has(param)) {
            url += `&${param}=${urlParams.get(param)}`;
        }
    });
    
    // Add closing transaction flag
    const hasClosingTransactions = document.querySelectorAll('.closing-transaction').length > 0;
    if (hasClosingTransactions) {
        url += '&has_closing=true';
    }
    
    window.open(url, '_blank');
}

function exportToCSV(type, additionalParams = '') {
    const currentTab = '<?php echo $tab; ?>';
    let url = `export_csv.php?type=${type}&tab=${currentTab}`;
    
    // Add additional parameters if provided
    if (additionalParams) {
        url += '&' + additionalParams;
    }
    
    // Add current filter parameters
    const urlParams = new URLSearchParams(window.location.search);
    const relevantParams = ['tanggal_awal', 'tanggal_akhir', 'cabang', 'rekening_filter'];
    
    relevantParams.forEach(param => {
        if (urlParams.has(param)) {
            url += `&${param}=${urlParams.get(param)}`;
        }
    });
    
    // Add closing transaction flag
    const hasClosingTransactions = document.querySelectorAll('.closing-transaction').length > 0;
    if (hasClosingTransactions) {
        url += '&has_closing=true';
    }
    
    window.open(url, '_blank');
}

// Performance optimization: Debounce search with closing transaction awareness
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Apply debounce to search functions with closing highlighting
const debouncedSearch = debounce(function(searchTerm, rows) {
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const isVisible = text.includes(searchTerm);
        row.style.display = isVisible ? '' : 'none';
        
        // Maintain closing transaction highlighting for visible rows
        if (isVisible && row.classList.contains('closing-transaction')) {
            row.style.backgroundColor = 'rgba(156,39,176,0.05)';
            row.style.borderLeft = '4px solid var(--closing-color)';
        }
    });
}, 300);

// Export functions for external use
window.tableScrolling = {
    initialize: initializeTableScrolling,
    reinitialize: reinitializeTableScrolling,
    scrollTo: scrollTableTo,
    addButtons: addScrollButtons
};

// Service worker registration for offline capability (optional)
// Temporarily disabled to fix 404 error
/*
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js')
            .then(function(registration) {
                console.log('ServiceWorker registration successful');
            })
            .catch(function(err) {
                console.log('ServiceWorker registration failed');
            });
    });
}
*/

// Final initialization
console.log('Setoran Keuangan system initialized with enhanced table scrolling and closing transaction support');

// Fungsi untuk menampilkan modal kembalikan ke CS
function showKembalikanKeCSModal(kodeTransaksi, namaKaryawan, namaCabang) {
    const modal = document.getElementById('kembalikanKeCSModal');
    const form = document.getElementById('formKembalikanKeCS');
    
    // Set data ke form
    document.getElementById('kembalikanTransaksiId').value = kodeTransaksi;
    document.getElementById('kembalikanInfoText').innerHTML = `
        <strong>Kode Transaksi:</strong> ${kodeTransaksi}<br>
        <strong>CS Pengirim:</strong> ${namaKaryawan}<br>
        <strong>Cabang:</strong> ${namaCabang}
    `;
    
    // Reset form
    document.getElementById('alasanKembalikan').value = '';
    
    // Show modal
    modal.style.display = 'flex';
    modal.classList.add('show');
}

function closeKembalikanKeCSModal() {
    const modal = document.getElementById('kembalikanKeCSModal');
    modal.style.display = 'none';
    modal.classList.remove('show');
}

// Event listener untuk modal
window.onclick = function(event) {
    const modal = document.getElementById('kembalikanKeCSModal');
    if (event.target === modal) {
        closeKembalikanKeCSModal();
    }
}
</script>

<!-- Modal Kembalikan ke CS -->
<div id="kembalikanKeCSModal" class="modal" style="display: none; z-index: 15000;">
    <div class="modal-dialog modal-lg" style="margin: 20px auto; max-width: 600px;">
        <div class="modal-content" style="background: white; border-radius: 16px; position: relative;">
            <div class="modal-header" style="position: relative; z-index: 15001;">
                <h5 class="modal-title"><i class="fas fa-undo"></i> Kembalikan Setoran ke CS Pengirim</h5>
                <button type="button" class="btn-close" onclick="closeKembalikanKeCSModal()" style="position: relative; z-index: 15002;">&times;</button>
            </div>
        
        <form id="formKembalikanKeCS" method="POST" action="">
            <div class="modal-body">
                <div class="alert alert-warning" style="margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Perhatian:</strong> Transaksi akan dikembalikan ke CS pengirim untuk diperbaiki. 
                    Pastikan sudah berkomunikasi dengan CS yang bersangkutan.
                </div>
                
                <input type="hidden" id="kembalikanTransaksiId" name="transaksi_id" value="">
                
                <div class="detail-section">
                    <h6>Detail Transaksi:</h6>
                    <div id="kembalikanInfoText" class="info-text">
                        <!-- Info akan diisi oleh JavaScript -->
                    </div>
                </div>
                
                <div class="form-group" style="margin-top: 20px;">
                    <label for="alasanKembalikan" class="form-label">
                        <i class="fas fa-comment"></i> Alasan Kembalikan ke CS: <span style="color: red;">*</span>
                    </label>
                    <textarea id="alasanKembalikan" name="alasan_kembalikan" class="form-control" rows="4" 
                              placeholder="Jelaskan alasan mengapa transaksi ini dikembalikan ke CS (contoh: Selisih terlalu besar, uang tidak sesuai catatan, dll.)" 
                              required style="resize: vertical;"></textarea>
                    <small class="form-text text-muted">
                        Alasan ini akan dicatat dalam sistem dan dapat dilihat oleh CS pengirim
                    </small>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeKembalikanKeCSModal()">
                    <i class="fas fa-times"></i> Batal
                </button>
                <button type="submit" name="kembalikan_ke_cs" class="btn btn-warning"
                        onclick="return confirm('Yakin ingin mengembalikan transaksi ini ke CS pengirim?')">
                    <i class="fas fa-undo"></i> Kembalikan ke CS
                </button>
            </div>
        </form>
        </div>
    </div>
</div>

<style>
.btn-group-vertical {
    display: flex;
    flex-direction: column;
}

.info-text {
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    border-left: 4px solid var(--primary-color);
    font-size: 14px;
    line-height: 1.5;
}

.form-group {
    margin-bottom: 15px;
}

.form-label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}

.form-text {
    font-size: 12px;
    margin-top: 5px;
}

/* Horizontal scroll styling for monitoring table */
.table-wrapper {
    scrollbar-width: thin;
    scrollbar-color: #888 #f1f1f1;
}

.table-wrapper::-webkit-scrollbar {
    height: 12px;
}

.table-wrapper::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 6px;
}

.table-wrapper::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 6px;
}

.table-wrapper::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Table styling for better horizontal scroll experience */
.table-enhanced table {
    table-layout: fixed;
    white-space: nowrap;
}

.table-enhanced th, 
.table-enhanced td {
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 8px 6px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .table-enhanced {
        min-width: 1600px;
    }
}
</style>
</body>
</html>