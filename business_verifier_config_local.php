<?php
// config.php

// Database connection details
define('DB_HOST', '192.168.10.247');
define('DB_USER', 'tmcviewer');
define('DB_PASS', 'raindrops');
define('DB_NAME', 'irgsdb2011');

// Create a connection
$CN = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check if the connection was successful
if (!$CN) {
    die("Database connection failed: " . mysqli_connect_error());
}
?>
