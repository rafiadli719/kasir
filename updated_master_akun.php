<?php
// Koneksi ke database fitmotor_maintance-beta
$host = "localhost";
$db_user = "fitmotor_LOGIN";
$db_password = "Sayalupa12";
$db_name = "fitmotor_maintance-beta";
$conn = mysqli_connect($host, $db_user, $db_password, $db_name);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Fungsi untuk mengecek apakah kode_akun sudah ada di database
function is_duplicate_kode_akun($conn, $kode_akun, $id = null) {
    $sql = "SELECT * FROM master_akun WHERE kode_akun = '$kode_akun'";
    
    // Jika sedang melakukan update, jangan cek kode_akun dari data yang sedang diupdate
    if ($id) {
        $sql .= " AND id != $id"; // Exclude current record when updating
    }
    
    $result = mysqli_query($conn, $sql);
    return mysqli_num_rows($result) > 0;
}

// CREATE (Menambah data akun baru)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create'])) {
    $kode_akun = $_POST['kode_akun'];
    $arti = $_POST['arti'];
    $keterangan = $_POST['keterangan'];

    // Cek apakah kode_akun sudah ada
    if (is_duplicate_kode_akun($conn, $kode_akun)) {
        echo "Kode akun sudah ada!";
    } else {
        // Jika tidak ada duplikat, masukkan ke database
        $sql = "INSERT INTO master_akun (kode_akun, arti, keterangan) VALUES ('$kode_akun', '$arti', '$keterangan')";
        
        if (mysqli_query($conn, $sql)) {
            header("Location: master_akun.php");
        } else {
            echo "Error: " . $sql . "<br>" . mysqli_error($conn);
        }
    }
}

// UPDATE (Mengupdate data akun yang sudah ada)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update'])) {
    $id = $_POST['id'];
    $kode_akun = $_POST['kode_akun'];
    $arti = $_POST['arti'];
    $keterangan = $_POST['keterangan'];

    // Cek apakah kode_akun sudah ada, kecuali untuk record yang sedang diupdate
    if (is_duplicate_kode_akun($conn, $kode_akun, $id)) {
        echo "Kode akun sudah ada!";
    } else {
        // Jika tidak ada duplikat, update datanya
        $sql = "UPDATE master_akun SET kode_akun = '$kode_akun', arti = '$arti', keterangan = '$keterangan' WHERE id = $id";
        
        if (mysqli_query($conn, $sql)) {
            header("Location: master_akun.php");
        } else {
            echo "Error: " . $sql . "<br>" . mysqli_error($conn);
        }
    }
}

// DELETE (Menghapus data akun)
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $sql = "DELETE FROM master_akun WHERE id = $id";

    if (mysqli_query($conn, $sql)) {
        header("Location: master_akun.php");
    } else {
        echo "Error: " . $sql . "<br>" . mysqli_error($conn);
    }
}

// FETCH ALL DATA (Mengambil semua data dari tabel master_akun)
$sql = "SELECT * FROM master_akun";
$result = mysqli_query($conn, $sql);

// FETCH ONE DATA FOR EDIT (Mengambil satu data untuk proses edit)
$edit = false;
if (isset($_GET['edit'])) {
    $edit = true;
    $id = $_GET['edit'];
    $sql = "SELECT * FROM master_akun WHERE id = $id";
    $edit_result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($edit_result);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Akun</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            padding: 20px;
        }

        .wrapper {
            margin-top: 20px;
        }

        .table {
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container wrapper">
        <h1>Data Master Akun</h1>

        <!-- Form Tambah/Edit Akun -->
        <form action="" method="POST" class="mb-4">
            <input type="hidden" name="id" value="<?php echo $edit ? $row['id'] : ''; ?>">
            
            <div class="mb-3">
                <label for="kode_akun" class="form-label">Kode Akun:</label>
                <input type="text" name="kode_akun" class="form-control" value="<?php echo $edit ? $row['kode_akun'] : ''; ?>" required>
            </div>

            <div class="mb-3">
                <label for="arti" class="form-label">Arti:</label>
                <input type="text" name="arti" class="form-control" value="<?php echo $edit ? $row['arti'] : ''; ?>" required>
            </div>

            <div class="mb-3">
                <label for="keterangan" class="form-label">Keterangan:</label>
                <textarea name="keterangan" class="form-control" rows="3" required><?php echo $edit ? $row['keterangan'] : ''; ?></textarea>
            </div>

            <?php if ($edit): ?>
                <button type="submit" name="update" class="btn btn-primary">Update Akun</button>
            <?php else: ?>
                <button type="submit" name="create" class="btn btn-success">Tambah Akun</button>
            <?php endif; ?>
        </form>

        <hr>

        <!-- Tabel Data Akun -->
        <table class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Kode Akun</th>
                    <th>Arti</th>
                    <th>Keterangan</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = mysqli_fetch_assoc($result)): ?>
                <tr>
                    <td><?php echo $row['kode_akun']; ?></td>
                    <td><?php echo $row['arti']; ?></td>
                    <td><?php echo $row['keterangan']; ?></td>
                    <td>
                        <a href="master_akun.php?edit=<?php echo $row['id']; ?>" class="btn btn-warning">Edit</a> 
                        <a href="master_akun.php?delete=<?php echo $row['id']; ?>" class="btn btn-danger" onclick="return confirm('Yakin ingin menghapus?');">Hapus</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
