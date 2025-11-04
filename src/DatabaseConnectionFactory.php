<?php

namespace App;

use PDO;

class DatabaseConnectionFactory
{
    public static function create(): PDO
    {
        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=utf8mb4",
            getenv('DB_HOST'),
            getenv('DB_DATABASE')
        );

        return new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
