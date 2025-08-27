# Template Sidebar

## Deskripsi
Template sidebar yang terpisah untuk semua halaman admin dashboard. Template ini memisahkan kode sidebar dari file PHP utama untuk memudahkan maintenance dan konsistensi.

## File yang Tersedia

### 1. `sidebar.php`
File template utama yang berisi struktur HTML sidebar dengan semua menu navigasi.

**Fitur:**
- Menu yang dinamis berdasarkan role user (admin/super_admin)
- Highlight menu aktif berdasarkan halaman yang sedang dibuka
- Semua menu sesuai dengan struktur yang diminta

**Menu yang Tersedia:**
- Dashboard
- Master Akun
- Master Nama Transaksi
- Master Nominal
- Detail Pemasukan
- Detail Pengeluaran
- Detail Omset
- Dashboard Kasir (admin & super admin)
- Master User (super admin only)
- Master Karyawan (super admin only)
- Master Cabang (super admin only)
- Master Rekening (super admin only)
- Manajemen Setoran (super admin only)
- Keuangan Pusat (super admin only)
- Rekap Setoran Bank (super admin only)
- Konfirmasi Buka Transaksi (super admin only)
- Logout

### 2. `sidebar.css`
File CSS terpisah untuk styling sidebar.

**Fitur:**
- Desain modern dan responsive
- Warna dan layout yang konsisten
- Transisi dan hover effects
- Responsive design untuk mobile

## Cara Penggunaan

### 1. Include Template Sidebar
Ganti semua kode sidebar HTML dengan:
```php
<?php include 'includes/sidebar.php'; ?>
```

### 2. Include CSS Sidebar
Tambahkan link CSS setelah Font Awesome:
```html
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link href="includes/sidebar.css" rel="stylesheet">
```

### 3. Hapus CSS Sidebar Lama
Hapus semua CSS yang berhubungan dengan sidebar dari file PHP utama:
- `.sidebar`
- `.sidebar h2`
- `.sidebar a`
- `.sidebar a:hover`
- `.sidebar a.active`
- `.sidebar a i`
- `.main-content` (yang memiliki margin-left)

## File yang Sudah Diupdate
- ✅ admin_dashboard.php
- ✅ master_akun.php
- ✅ master_nama_transaksi.php
- ✅ keping.php
- ✅ detail_pemasukan.php
- ✅ detail_pengeluaran.php
- ✅ detail_omset.php
- ✅ users.php
- ✅ masterkey.php
- ✅ cabang.php
- ✅ master_rekening_cabang.php
- ✅ setoran_keuangan.php
- ✅ keuangan_pusat.php
- ✅ setoran_bank_rekap.php
- ✅ konfirmasi_buka_transaksi.php

## Keuntungan
1. **Maintenance Mudah**: Perubahan sidebar cukup dilakukan di satu file
2. **Konsistensi**: Semua halaman menggunakan sidebar yang sama
3. **Kode Bersih**: File PHP utama lebih fokus pada logika bisnis
4. **Reusable**: Template dapat digunakan di halaman lain
5. **Responsive**: Desain yang konsisten di semua device

## Catatan
- Pastikan session sudah dimulai sebelum include sidebar
- Pastikan variabel `$pdo` tersedia untuk koneksi database
- Template akan otomatis mendeteksi role user dan menampilkan menu yang sesuai
- Menu aktif akan otomatis di-highlight berdasarkan halaman yang sedang dibuka
