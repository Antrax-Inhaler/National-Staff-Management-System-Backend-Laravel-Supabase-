<?php

declare(strict_types=1);

namespace App\Providers\CustomPostgres;

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory as ConnectionFactoryBase;

class ConnectionFactory extends ConnectionFactoryBase
{
    protected function createConnection($driver, $connection, $database, $prefix = '', array $config = [])
    {
        $resolver = Connection::getResolver($driver);
        if ($resolver) {
            return $resolver($connection, $database, $prefix, $config);
        }

        if ($driver === 'pgsql') {
            // Just return the connection - BaseModel handles schema qualification
            return new PostgresConnection($connection, $database, $prefix, $config);
        }

        return parent::createConnection($driver, $connection, $database, $prefix, $config);
    }
}