<?php
$servername = "LOCALHOST";
$username = "YOUR_DB_USER";
$password = "YOUR_DB_PASSWORD";
$dbname = "vigilo_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
