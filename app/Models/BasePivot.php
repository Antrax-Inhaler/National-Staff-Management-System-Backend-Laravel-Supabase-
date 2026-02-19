<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

abstract class BasePivot extends Pivot
{
    public function getTable()
    {
        $table = parent::getTable();
        
        // If table already has schema prefix, return as-is
        if (str_contains($table, '.')) {
            return $table;
        }
        
        $config = config('database.connections.pgsql');
        $schema = $config['search_path'] ?? $config['schema'] ?? 'public';
        
        return $schema . '.' . $table;
    }
}