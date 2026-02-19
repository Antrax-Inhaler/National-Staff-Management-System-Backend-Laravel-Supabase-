<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

abstract class BaseAuthenticatable extends Authenticatable
{
    public function getTable()
    {
        $table = parent::getTable();
        
        if (str_contains($table, '.')) {
            return $table;
        }
        
        $config = config('database.connections.pgsql');
        $schema = $config['search_path'] ?? $config['schema'] ?? 'public';
        
        return $schema . '.' . $table;
    }
    
    protected function getSchemaName(): string
    {
        $config = config('database.connections.pgsql');
        return $config['search_path'] ?? $config['schema'] ?? 'public';
    }
    
    protected function qualifyTable(string $table): string
    {
        if (str_contains($table, '.')) {
            return $table;
        }
        
        return $this->getSchemaName() . '.' . $table;
    }
}