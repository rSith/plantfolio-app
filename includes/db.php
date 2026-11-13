<?php
// One shared PDO connection to the PlantFolio database, used by every query in the site (CORE-03).
// Usage: $stmt = db()->prepare('SELECT … WHERE id = :id'); $stmt->execute(['id' => $plant_id]);

require_once __DIR__ . '/helpers.php';   // config(), e(), and PHP's time zone

/**
 * The database connection. Made on the first call, then the same object is returned for the rest of the request,
 * so a page that runs ten queries still opens only one connection.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . config('db_host') . ';dbname=' . config('db_name') . ';charset=utf8mb4';

        try {
            $pdo = new PDO($dsn, config('db_user'), config('db_pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // a failed query throws: it can't be ignored
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // rows as ['column' => value], like the mock data
                PDO::ATTR_EMULATE_PREPARES   => false,                    // the database prepares statements, not PHP
            ]);
        } catch (PDOException $error) {
            // The stack trace of this exception would include the arguments of new PDO(), password included,
            // so it is never shown. The reason goes to the PHP error log; the visitor gets a plain message.
            error_log('PlantFolio: database connection failed: ' . $error->getMessage());
            http_response_code(500);
            exit(config('app_env') === 'development'
                ? 'Database connection failed: ' . e($error->getMessage()) . '. Is MySQL running in XAMPP, and does config/config.php match it?'
                : 'Sorry, PlantFolio is having trouble right now. Please try again in a few minutes.');
        }

        // XAMPP's MariaDB is not strict by default: a wrong value ('dying' in an ENUM, a 61-character nickname) is
        // stored as '' or cut short with only a warning. Strict mode turns those into errors, on any machine,
        // without editing my.ini.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        // MySQL's CURDATE() and NOW() must agree with PHP's date() (Asia/Colombo, set in helpers.php), or "due today"
        // and "2 days ago" could differ between a query and the page around midnight. Sri Lanka has no daylight
        // saving, so the offset (+05:30) is enough; named zones need time-zone tables that XAMPP does not load.
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }

    return $pdo;
}
