<?php
declare(strict_types=1);

/**
 * db.php - PDO connection for the Student Registration System.
 *
 * Usage in other files:
 *     require_once __DIR__ . '/db.php';
 *     $stmt = $pdo->prepare('SELECT * FROM students WHERE id = :id');
 *     $stmt->execute([':id' => $id]);
 *
 * Credentials are read from environment variables when set, so they never
 * have to be committed to source control. The fallbacks below are for local
 * development only (e.g. XAMPP/MAMP) - override them in production.
 */

$dbHost    = getenv('DB_HOST') ?: 'localhost';
$dbPort    = getenv('DB_PORT') ?: '3306';
$dbName    = getenv('DB_NAME') ?: 'student_registration_system';
$dbUser    = getenv('DB_USER') ?: 'root';
$dbPass    = getenv('DB_PASS') ?: '';
$dbCharset = 'utf8mb4';

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset={$dbCharset}";

$options = [
    // Throw exceptions on errors instead of failing silently.
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    // Return associative arrays by default.
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // Use real prepared statements (protects against SQL injection).
    PDO::ATTR_EMULATE_PREPARES   => false,
    // Keep native MySQL types (ints stay ints).
    PDO::ATTR_STRINGIFY_FETCHES  => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    // Log the real reason on the server, but never show it to the visitor:
    // error details can leak hostnames, usernames and schema information.
    error_log('Database connection failed: ' . $e->getMessage());

    http_response_code(500);
    exit('A database error occurred. Please try again later.');
}
