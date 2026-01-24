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
    private ?int $lastConnectionCheck = null;
    private const CONNECTION_CHECK_INTERVAL = 5; // seconds

    public function __construct()
    {
        $this->checkConnection();
    }

    private function checkConnection(): bool
    {
        try {
            $pdo = DatabaseConnectionFactory::create();
            $pdo->query('SELECT 1');
            
            if (!$this->remoteDataSource) {
                $this->remoteDataSource = new RemoteDataSource($pdo);
            }
            
            $this->isOnline = true;
            $this->lastConnectionCheck = time();
            return true;
        } catch (\Exception $e) {
            $this->isOnline = false;
            $this->lastConnectionCheck = time();
            return false;
        }
    }

    /**
     * Recheck connection if enough time has passed since last check
     */
    private function recheckIfNeeded(): void
    {
        if ($this->lastConnectionCheck === null || 
            (time() - $this->lastConnectionCheck) >= self::CONNECTION_CHECK_INTERVAL) {
            $this->checkConnection();
        }
    }

    public function getDataSource(): DataSourceInterface
    {
        $this->recheckIfNeeded();
        
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
        $this->recheckIfNeeded();
        return $this->isOnline;
    }

    public function getConnectionStatus(): string
    {
        return $this->isOnline ? 'online' : 'offline';
    }
}
