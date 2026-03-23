<?php
define('DB_HOST', 'sql104.infinityfree.com');
define('DB_USER', 'if0_41404707');
define('DB_PASS', 'Tristanjay19');
define('DB_NAME', 'if0_41404707_tristan_jay_alejandro');

function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}
?>