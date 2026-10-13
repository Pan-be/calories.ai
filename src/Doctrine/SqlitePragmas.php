<?php

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * SQLite settings on every connection: foreign keys on (so deleting a user or meal
 * cascades), WAL so reads don't block the write of a new meal, and a busy timeout
 * instead of an immediate "database is locked".
 */
#[AsMiddleware]
final class SqlitePragmas implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);
                if (in_array($params['driver'] ?? '', ['pdo_sqlite', 'sqlite3'], true)) {
                    $connection->exec('PRAGMA foreign_keys = ON');
                    $connection->exec('PRAGMA busy_timeout = 5000');
                    $connection->exec('PRAGMA journal_mode = WAL');
                }
                return $connection;
            }
        };
    }
}
