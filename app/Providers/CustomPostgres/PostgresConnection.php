<?php

declare(strict_types=1);

namespace App\Providers\CustomPostgres;

use App\Providers\CustomPostgres\Helpers\PostgresTextSanitizer;
use DateTimeInterface;
use Illuminate\Database\PostgresConnection as BasePostgresConnection;
use Illuminate\Support\Traits\Macroable;
use PDO;

class PostgresConnection extends BasePostgresConnection
{
    use Macroable;

    public $name;
    private static $extensions = [];

    /**
     * Create a new database connection instance.
     */
    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);

        // Set the schema search path immediately after connection
        if (isset($config['search_path'])) {
            $schema = $config['search_path'];
            $this->statement("SET search_path TO {$schema}");
        }
    }

    public function bindValues($statement, $bindings)
    {
        if ($this->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES)) {
            foreach ($bindings as $key => $value) {
                $parameter = is_string($key) ? $key : $key + 1;
                switch (true) {
                    case is_bool($value):
                        $dataType = PDO::PARAM_BOOL;
                        break;
                    case $value === null:
                        $dataType = PDO::PARAM_NULL;
                        break;
                    default:
                        $dataType = PDO::PARAM_STR;
                }
                $statement->bindValue($parameter, $value, $dataType);
            }
        } else {
            parent::bindValues($statement, $bindings);
        }
    }

    public function prepareBindings(array $bindings)
    {
        if ($this->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES)) {
            $grammar = $this->getQueryGrammar();
            foreach ($bindings as $key => $value) {
                if ($value instanceof DateTimeInterface) {
                    $bindings[$key] = $value->format($grammar->getDateFormat());
                }
                if (is_string($value)) {
                    $bindings[$key] = PostgresTextSanitizer::sanitize($value);
                }
            }
            return $bindings;
        }
        return parent::prepareBindings($bindings);
    }
}
