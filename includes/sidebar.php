<?php
// Pastikan session sudah dimulai
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and has the correct role
if (!isset($_SESSION['kode_karyawan']) || 
    ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super_admin')) {
    header('Location: ../../login_dashboard/login.php');
    exit();
}

// Determine user role
$is_super_admin = false;
$is_admin = false;
$kode_karyawan = $_SESSION['kode_karyawan'];

$query = "SELECT role FROM users WHERE kode_karyawan = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$kode_karyawan]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    if ($user['role'] === 'super_admin') {
        $is_super_admin = true;
    } elseif ($user['role'] === 'admin') {
        $is_admin = true;
    }
}

// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<div class="sidebar" id="sidebar">
    <h2><i class="fas fa-user-shield"></i> Dashboard Admin</h2>
    
    <a href="admin_dashboard.php" class="<?php echo ($current_page == 'admin_dashboard.php') ? 'active' : ''; ?>">
        <i class="fas fa-tachometer-alt"></i> Dashboard
    </a>
    
    <a href="master_akun.php" class="<?php echo ($current_page == 'master_akun.php') ? 'active' : ''; ?>">
        <i class="fas fa-users-cog"></i> Master Akun
    </a>
    
    <a href="master_nama_transaksi.php" class="<?php echo ($current_page == 'master_nama_transaksi.php') ? 'active' : ''; ?>">
        <i class="fas fa-file-signature"></i> Master Nama Transaksi
    </a>
    
    <a href="keping.php" class="<?php echo ($current_page == 'keping.php') ? 'active' : ''; ?>">
        <i class="fas fa-coins"></i> Master Nominal
    </a>
    
    <a href="detail_pemasukan.php" class="<?php echo ($current_page == 'detail_pemasukan.php') ? 'active' : ''; ?>">
        <i class="fas fa-file-invoice-dollar"></i> Detail Pemasukan
    </a>
    
    <a href="detail_pengeluaran.php" class="<?php echo ($current_page == 'detail_pengeluaran.php') ? 'active' : ''; ?>">
        <i class="fas fa-file-invoice-dollar"></i> Detail Pengeluaran
    </a>
    
    <a href="detail_omset.php" class="<?php echo ($current_page == 'detail_omset.php') ? 'active' : ''; ?>">
        <i class="fas fa-chart-line"></i> Detail Omset
    </a>
    
    <?php if ($is_admin || $is_super_admin): ?>
        <a href="index_kasir.php" class="<?php echo ($current_page == 'index_kasir.php') ? 'active' : ''; ?>">
            <i class="fas fa-cash-register"></i> Dashboard Kasir
        </a>
    <?php endif; ?>
    
    <?php if ($is_super_admin): ?>
        <a href="users.php" class="<?php echo ($current_page == 'users.php') ? 'active' : ''; ?>">
            <i class="fas fa-user-friends"></i> Master User
        </a>
        
        <a href="masterkey.php" class="<?php echo ($current_page == 'masterkey.php') ? 'active' : ''; ?>">
            <i class="fas fa-id-card"></i> Master Karyawan
        </a>
        
        <a href="cabang.php" class="<?php echo ($current_page == 'cabang.php') ? 'active' : ''; ?>">
            <i class="fas fa-building"></i> Master Cabang
        </a>
        
        <a href="master_rekening_cabang.php" class="<?php echo ($current_page == 'master_rekening_cabang.php') ? 'active' : ''; ?>">
            <i class="fas fa-university"></i> Master Rekening
        </a>
        
        <a href="setoran_keuangan.php" class="<?php echo ($current_page == 'setoran_keuangan.php') ? 'active' : ''; ?>">
            <i class="fas fa-hand-holding-usd"></i> Manajemen Setoran
        </a>
        
        <a href="keuangan_pusat.php" class="<?php echo ($current_page == 'keuangan_pusat.php') ? 'active' : ''; ?>">
            <i class="fas fa-wallet"></i> Keuangan Pusat
        </a>
        
        <a href="setoran_bank_rekap.php" class="<?php echo ($current_page == 'setoran_bank_rekap.php') ? 'active' : ''; ?>">
            <i class="fas fa-university"></i> Rekap Setoran Bank
        </a>
        
        <a href="konfirmasi_buka_transaksi.php" class="<?php echo ($current_page == 'konfirmasi_buka_transaksi.php') ? 'active' : ''; ?>">
            <i class="fas fa-unlock-alt"></i> Konfirmasi Buka Transaksi
        </a>
    <?php endif; ?>
    
    <a href="logout.php">
        <i class="fas fa-sign-out-alt"></i> Kembali ke Dashboard
    </a>
</div>
