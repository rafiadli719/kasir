<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$mysqli = new mysqli('localhost', 'fitmotor_LOGIN', 'Sayalupa12', 'fitmotor_maintance-beta');
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

session_start();

// Menghapus cache
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$error_message = "";
$login_successful = false; // Tambahkan ini sebagai default untuk variabel login
if (isset($_POST['login'])) {
    // Mendapatkan data input login
    $password = isset($_POST['password']) ? strtolower(mysqli_real_escape_string($mysqli, $_POST['password'])) : '';
    $cabang = isset($_POST['cabang']) ? strtolower(mysqli_real_escape_string($mysqli, $_POST['cabang'])) : '';
    $karyawan = isset($_POST['karyawan']) ? strtolower(mysqli_real_escape_string($mysqli, $_POST['karyawan'])) : '';

    // Memeriksa apakah data karyawan ada di tabel users
    $sql_check_user = "SELECT * FROM users WHERE kode_karyawan = ?";
    $stmt_check_user = mysqli_prepare($mysqli, $sql_check_user);
    mysqli_stmt_bind_param($stmt_check_user, "s", $karyawan);
    mysqli_stmt_execute($stmt_check_user);

    // Ambil hasil query
    $result_check_user = mysqli_stmt_get_result($stmt_check_user);

    if (mysqli_num_rows($result_check_user) > 0) {
        // Ambil data dari tabel users
        $row_user = mysqli_fetch_assoc($result_check_user);
        $nama_karyawan = $row_user['nama_karyawan'];
        $password_db = $row_user['password'];

        // Jika password kosong, gunakan kode karyawan sebagai password
        if (empty($password_db)) {
            $password_db = $karyawan;
        }

        // Memastikan password cocok (gunakan password_verify jika hash)
        if ($password === $password_db) { // Ganti ini dengan password_verify jika perlu
            // Set login sukses menjadi true jika password cocok
            $_SESSION['nama_karyawan'] = $nama_karyawan;
            $_SESSION['kode_karyawan'] = $karyawan;
            $_SESSION['cabang'] = $cabang;
            $_SESSION['role'] = $row_user['role']; // Simpan role pengguna

            // Jika user adalah admin atau super admin, arahkan ke dashboard admin
            if ($row_user['role'] === 'admin' || $row_user['role'] === 'super_admin') {
                header('Location: admin_dashboard.php');
                exit(); // Hentikan eksekusi setelah redirect
            }

            // Jika user bukan admin, arahkan ke halaman kasir
            header('Location: index_kasir.php');
            exit();  // Hentikan eksekusi setelah redirect
        } else {
            $error_message = "Password yang Anda masukkan salah.";
        }
    } else {
        $error_message = "Data karyawan tidak ditemukan.";
    }
}

// Sekarang variabel $login_successful pasti ada dan memiliki nilai default false
if ($login_successful) {
    // Login berhasil, Anda dapat melakukan tindakan tambahan di sini jika diperlukan
} else {
    // Login gagal, tampilkan pesan kesalahan atau arahkan ke halaman login lagi
}

// Mengambil data cabang dari tabel masterkeys
$cabang_options = "";
$sql = "SELECT DISTINCT nama_cabang FROM masterkeys";
$result = mysqli_query($mysqli, $sql);
if (mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $cabang_options .= '<option value="' . htmlspecialchars($row['nama_cabang']) . '">' . htmlspecialchars($row['nama_cabang']) . '</option>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Page</title>
    <style>
        /* Tambahan CSS untuk memperbesar tampilan */
        body {
            font-family: 'Poppins', sans-serif;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg, #71b7e6, #9b59b6); /* Background gradient */
            overflow: hidden;
        }

        .login-box {
            width: 100%;
            max-width: 500px; /* Lebar lebih besar */
            padding: 40px;
            background: #ffffff;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
            border-radius: 12px;
            text-align: center;
            font-size: 16px;
            position: relative;
        }

        .login-box:hover {
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
        }

        .login-box h2 {
            margin-bottom: 20px;
            color: #333;
            font-size: 24px;
        }

        .input-box {
            position: relative;
            margin-bottom: 20px;
        }

        .input-box input,
        .input-box select {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            color: #333;
            border: 2px solid #ddd;
            border-radius: 8px;
            background: #f9f9f9;
        }

        .input-box input:focus,
        .input-box select:focus {
            border-color: #9b59b6;
            box-shadow: 0 0 5px rgba(155, 89, 182, 0.5);
            background: #ffffff;
        }

        .login-btn {
            background: #9b59b6;
            border: none;
            color: #fff;
            padding: 15px;
            cursor: pointer;
            width: 100%;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
        }

        .login-btn:hover {
            background: #71b7e6;
        }

        .toggle-password {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: #9b59b6;
            cursor: pointer;
        }/* General Reset and Body Styles */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Poppins', sans-serif;
    height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    background: linear-gradient(135deg, #71b7e6, #9b59b6); /* Background gradient */
    overflow: hidden;
}

/* Login Box Styles */
.login-box {
    width: 100%;
    max-width: 500px; /* Lebar ditingkatkan untuk tampilan yang lebih luas */
    padding: 40px;
    background: #ffffff; /* White background for the login box */
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15); /* Light shadow for depth */
    border-radius: 12px;
    text-align: center;
    font-size: 16px;
    position: relative;
    transition: box-shadow 0.3s ease;
}
.login-box:hover {
    box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3); /* Darker shadow on hover */
}

/* Heading Style */
.login-box h2 {
    margin-bottom: 20px;
    color: #333;
    font-size: 24px;
}

/* Input and Select Styles */
.input-box {
    position: relative;
    margin-bottom: 20px;
}
.input-box input,
.input-box select {
    width: 100%;
    padding: 15px; /* Ukuran padding untuk input yang lebih besar */
    font-size: 16px;
    color: #333;
    border: 2px solid #ddd;
    border-radius: 8px;
    outline: none;
    transition: border-color 0.3s, box-shadow 0.3s;
    background: #f9f9f9; /* Light grey background for input and select */
}
.input-box input:focus,
.input-box select:focus {
    border-color: #9b59b6;
    box-shadow: 0 0 5px rgba(155, 89, 182, 0.5);
    background: #ffffff; /* White background when focused */
}
.input-box label {
    position: absolute;
    top: 10px;
    left: 15px;
    font-size: 16px;
    color: #333;
    transition: 0.3s;
    pointer-events: none;
}
.input-box input:focus ~ label,
.input-box input:valid ~ label,
.input-box select:focus ~ label {
    top: -15px;
    left: 15px;
    color: #9b59b6;
    font-size: 12px;
}

/* Button Style */
.login-btn {
    background: #9b59b6;
    border: none;
    color: #fff;
    padding: 15px;
    cursor: pointer;
    width: 100%;
    border-radius: 8px;
    transition: background-color 0.3s, transform 0.2s;
    font-size: 16px;
    font-weight: bold;
}
.login-btn:hover {
    background: #71b7e6;
    transform: scale(1.05);
}
.login-btn:active {
    transform: scale(1);
}

/* Error Message Style */
.error-message {
    color: red;
    margin-bottom: 20px;
    font-size: 14px;
}

/* Toggle Password Visibility */
.toggle-password {
    cursor: pointer;
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 14px;
    color: #9b59b6;
    background-color: transparent;
    border: none;
    outline: none;
}

/* Loading Animation */
.loading {
    display: flex;
    justify-content: center;
    align-items: center;
    position: fixed;
    top: 0;
    left: 0;
    height: 100vh;
    width: 100vw;
    background-color: rgba(255, 255, 255, 0.9);
    z-index: 999;
}
.loading h1 {
    color: #0d0d0d;
    font-size: 36px;
}

/* Ensure Cabang Select Box is Visible */
.cabang-container {
    display: block !important; /* Make cabang-container always visible */
}

/* Hide Employee Select Box Initially */
.employee-container {
    display: none; /* Will be shown when cabang is selected */
}

/* Smaller Screen Adjustments */
@media (max-width: 480px) {
    .login-box {
        padding: 20px;
        width: 95%; /* Perlebar hingga hampir penuh */
    }
    .login-box h2 {
        font-size: 20px;
    }
    .input-box input,
    .input-box select {
        font-size: 14px;
    }
    .login-btn {
        padding: 12px;
        font-size: 14px;
    }
}

    </style>
</head>
<body>
    <div class="login-box">
        <h2>Login</h2>
        <?php if ($error_message): ?>
            <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        <form action="login.php" method="POST">
            <div class="input-box">
                <select name="cabang" id="cabang" onchange="loadEmployees()">
                    <option value="">Pilih Cabang</option>
                    <?php echo $cabang_options; ?>
                </select>
            </div>
            <div class="input-box">
                <select name="karyawan" id="karyawan">
                    <option value="">Pilih Karyawan</option>
                </select>
            </div>
            <div class="input-box">
                <input type="password" id="password" name="password" required>
                <span class="toggle-password" onclick="togglePassword()">Show</span>
            </div>
            <input type="submit" name="login" value="Login" class="login-btn">
        </form>
    </div>
    <div id="loading" class="loading" style="display:none;">
        <h1>Loading...</h1>
    </div>

    <script>
        function togglePassword() {
            var passwordInput = document.getElementById('password');
            var toggleButton = document.querySelector('.toggle-password');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleButton.textContent = 'Hide';
            } else {
                passwordInput.type = 'password';
                toggleButton.textContent = 'Show';
            }
        }

        function loadEmployees() {
            var cabang = document.getElementById('cabang').value;
            var employeeContainer = document.getElementById('karyawan');

            if (cabang) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'get_employees.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function () {
                    if (this.status === 200) {
                        employeeContainer.innerHTML = this.responseText;
                    }
                };
                xhr.send('cabang=' + encodeURIComponent(cabang));
            } else {
                employeeContainer.innerHTML = '<option value="">Pilih Karyawan</option>';
            }
        }
    </script>
</body>
</html>
