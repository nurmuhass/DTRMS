<?php
declare(strict_types=1);

namespace Dtrms\Core;

use InvalidArgumentException;
use PDO;
use Throwable;

final class Database
{
    public static function connect(array $config): PDO
    {
        $port = filter_var($config['port'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if (
            $port === false || !preg_match('/\A[A-Za-z0-9_.:-]+\z/', $config['host'])
            || !preg_match('/\A[A-Za-z0-9_]+\z/', $config['name'])
            || $config['user'] === ''
        ) {
            throw new InvalidArgumentException('Invalid database connection configuration.');
        }

        $driverClassAvailable = class_exists(\Pdo\Mysql::class);
        $multiStatements = $driverClassAvailable ? \Pdo\Mysql::ATTR_MULTI_STATEMENTS : PDO::MYSQL_ATTR_MULTI_STATEMENTS;
        $initCommand = $driverClassAvailable ? \Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND;

        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $port, $config['name']),
            $config['user'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                $multiStatements => false,
                $initCommand => "SET time_zone = '+00:00'",
            ]
        );
    }

    public static function transaction(PDO $database, callable $operation): mixed
    {
        $database->beginTransaction();
        try {
            $result = $operation();
            $database->commit();
            return $result;
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
    }
}
