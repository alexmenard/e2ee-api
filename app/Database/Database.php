<?php

namespace App\Database;

use App\Support\Env;
use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            Env::get('DB_HOST'),
            Env::get('DB_NAME'),
            Env::get('DB_CHARSET', 'utf8mb4')
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                Env::get('DB_USER'),
                Env::get('DB_PASS'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Database connection failed',
                'detail' => $e->getMessage()
            ]);
            exit;
        }        

        return self::$pdo;
    }
}
