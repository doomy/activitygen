<?php

namespace App;

use PDO;

class DatabaseConnectionFactory
{
    private const DEFAULT_PORT = 3306;

    public static function create(): PDO
    {
        $port = getenv('DB_PORT');
        $port = ($port !== false && $port !== '') ? (int) $port : self::DEFAULT_PORT;

        $dsn = sprintf(
            "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
            getenv('DB_HOST'),
            $port,
            getenv('DB_DATABASE')
        );

        return new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
