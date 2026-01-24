<?php

namespace App;

use App\DataSource\DataSourceInterface;
use App\DataSource\RemoteDataSource;
use App\DataSource\LocalDataSource;
use PDO;

class ConnectionManager
{
    private const LOCAL_DB_PATH = __DIR__ . '/../data/local.db';

    private ?RemoteDataSource $remoteDataSource = null;
    private ?LocalDataSource $localDataSource = null;
    private bool $isOnline = false;

    public function __construct()
    {
        $this->checkConnection();
    }

    private function checkConnection(): void
    {
        try {
            $pdo = DatabaseConnectionFactory::create();
            $pdo->query('SELECT 1');
            $this->remoteDataSource = new RemoteDataSource($pdo);
            $this->isOnline = true;
        } catch (\Exception $e) {
            $this->isOnline = false;
        }
    }

    public function getDataSource(): DataSourceInterface
    {
        if ($this->isOnline && $this->remoteDataSource !== null) {
            return $this->remoteDataSource;
        }

        if ($this->localDataSource === null) {
            $this->localDataSource = new LocalDataSource(self::LOCAL_DB_PATH);
        }

        return $this->localDataSource;
    }

    public function getLocalDataSource(): LocalDataSource
    {
        if ($this->localDataSource === null) {
            $this->localDataSource = new LocalDataSource(self::LOCAL_DB_PATH);
        }

        return $this->localDataSource;
    }

    public function getRemoteDataSource(): ?RemoteDataSource
    {
        return $this->remoteDataSource;
    }

    public function isOnline(): bool
    {
        return $this->isOnline;
    }

    public function getConnectionStatus(): string
    {
        return $this->isOnline ? 'online' : 'offline';
    }
}
