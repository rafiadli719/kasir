<?php
// Database connection settings
$host = 'localhost';
$dbname = 'fitmotor_maintance-beta';
$username = 'fitmotor_LOGIN';
$password = 'Sayalupa12';

$conn = mysqli_connect("localhost", "fitmotor_LOGIN", "Sayalupa12", "fitmotor_maintance-beta");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
} else {
}

try {
    // Creating a PDO instance to connect to the database
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Could not connect to the database: " . $e->getMessage());
}
?>
