<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class BaseModel extends Model
{
    public function getTable()
    {
        $table = parent::getTable();
        
        // If table already has schema prefix, return as-is
        if (str_contains($table, '.')) {
            return $table;
        }
        
        $schema = $this->getSchemaName();
        return $schema . '.' . $table;
    }
    
    protected function getSchemaName(): string
    {
        $config = config('database.connections.pgsql');
        return $config['search_path'] ?? $config['schema'] ?? 'public';
    }
    
    /**
     * Helper to get schema-qualified table name
     */
    protected function qualifyTable(string $table): string
    {
        if (str_contains($table, '.')) {
            return $table;
        }
        
        return $this->getSchemaName() . '.' . $table;
    }
}