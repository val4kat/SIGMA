<?php
// Database configuration
$servername = "10.db.sigmanet.lv:3306"; // Replace with your database server if different
$username = "stonekat"; // Replace with your MariaDB username
$password = "2Q52K9LkYdcm"; // Replace with your MariaDB password
$dbname = "c_stonekat"; // Replace with your database name

// Create a connection to the database
$conn = new mysqli($servername, $username, $password, $dbname);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get the submitted password and redirect URL
$submittedPassword = $_POST['password'] ?? '';
$redirectUrl = $_POST['redirect_url'] ?? '/';

// Query the database to check if the password is correct
// Assuming you have a table named `passwords` with a column `password`
$sql = "SELECT * FROM passwords WHERE password = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $submittedPassword);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Password is correct, redirect to the protected page
    header("Location: " . $redirectUrl);
    exit();
} else {
    // Password is incorrect, redirect back with an error
    header("Location: /?error=invalid_password&attempt=1");
    exit();
}

// Close the connection
$conn->close();
?>
