# Panduan Deployment Sistem Closing Transaction

## Langkah-langkah Deployment

### 1. Backup Database
```sql
-- Backup database sebelum update
mysqldump -u fitmotor_LOGIN -p fitmotor_kasir > backup_before_closing_update.sql
```

### 2. Deploy Database Updates
```bash
# Jalankan script database updates
mysql -u fitmotor_LOGIN -p fitmotor_kasir < database_updates.sql
```

### 3. Deploy File PHP
Pastikan file-file berikut sudah di-upload ke server:
- ✅ `process_closing_transaction.php` (baru)
- ✅ `pemasukan_kasir.php` (updated)
- ✅ `setoran_keuangan_cs_enhanced.php` (baru)
- ✅ `test_closing_system.php` (untuk testing)

### 4. Validasi Deployment
1. Akses `test_closing_system.php` melalui browser
2. Pastikan semua test menunjukkan ✅
3. Test manual input transaksi "DARI CLOSING"

### 5. Jenis Closing yang Didukung

| Jenis | Kode | Deskripsi |
|-------|------|-----------|
| Closing Normal | `closing` | Transaksi penutupan kasir normal |
| Closing Dipinjam | `dipinjam` | Uang yang dipinjam dari kasir lain saat closing |
| Closing Meminjam | `meminjam` | Uang yang dipinjamkan ke kasir lain saat closing |

### 6. Workflow Sistem

#### A. Input Transaksi "DARI CLOSING"
1. Kasir pilih nama transaksi "DARI CLOSING"
2. Pilih nomor transaksi closing yang tersedia
3. Pilih jenis closing (normal/dipinjam/meminjam)
4. Input jumlah (tidak boleh melebihi setoran closing)
5. Submit → Status kasir_transactions berubah ke 'end proses'

#### B. Setoran Keuangan CS
1. Akses `setoran_keuangan_cs_enhanced.php`
2. Pilih transaksi regular dan/atau closing groups
3. Review summary total
4. Submit setoran

#### C. Monitoring
- Gunakan view `v_closing_transaction_summary` untuk monitoring
- Cek status closing groups di tabel `closing_transaction_groups`

### 7. Troubleshooting

#### Error: "Table doesn't exist"
```sql
-- Cek apakah semua tabel sudah dibuat
SHOW TABLES LIKE '%closing%';
```

#### Error: "Column doesn't exist"
```sql
-- Cek struktur tabel kasir_transactions
DESCRIBE kasir_transactions;
```

#### Error: "Function not found"
- Pastikan file `process_closing_transaction.php` sudah di-upload
- Cek permission file (harus readable)

### 8. Rollback Plan
Jika terjadi masalah:
```sql
-- Restore dari backup
mysql -u fitmotor_LOGIN -p fitmotor_kasir < backup_before_closing_update.sql
```

### 9. Post-Deployment Checklist
- [ ] Database updates berhasil
- [ ] File PHP ter-upload
- [ ] Test system menunjukkan ✅ semua
- [ ] Test manual input closing berhasil
- [ ] Test setoran keuangan berhasil
- [ ] User training completed

### 10. Monitoring Queries

```sql
-- Cek transaksi closing hari ini
SELECT * FROM kasir_transactions 
WHERE nama_transaksi LIKE '%DARI CLOSING%' 
AND DATE(tanggal) = CURDATE();

-- Cek closing groups aktif
SELECT * FROM closing_transaction_groups 
WHERE status = 'active' 
ORDER BY created_at DESC;

-- Summary closing per jenis
SELECT jenis_closing, COUNT(*) as total, SUM(total_amount) as total_amount
FROM closing_transaction_groups 
GROUP BY jenis_closing;
```

## Kontak Support
Jika ada masalah deployment, hubungi tim development dengan informasi:
1. Error message lengkap
2. Screenshot jika ada
3. Langkah yang sudah dicoba
4. Hasil dari `test_closing_system.php`
