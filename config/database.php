<?php
// ============================================================
// Database Configuration
// Sistem Bebas Pustaka - PDO MySQL Connection
// ============================================================

define('DB_HOST', '127.0.0.1'); // Ubah 'localhost' menjadi '127.0.0.1'
define('DB_PORT', '3307');      // Port default MySQL Laragon
define('DB_NAME', 'bebas_pustaka');
define('DB_USER', 'root');
define('DB_PASS', '');          // Default Laragon biasanya kosong
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // Disini ditambahkan ;port=3306
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    return $pdo;
}
