<?php

declare(strict_types=1);

function env_value(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

const LOCAL_DB_HOST = '127.0.0.1';
const LOCAL_DB_NAME = 'simrs_permata_asri';
const LOCAL_DB_USER = 'root';
const LOCAL_DB_PASS = '';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env_value('DB_HOST', env_value('MYSQLHOST', LOCAL_DB_HOST));
    $port = env_value('DB_PORT', env_value('MYSQLPORT', '3306'));
    $name = env_value('DB_NAME', env_value('MYSQLDATABASE', LOCAL_DB_NAME));
    $user = env_value('DB_USER', env_value('MYSQLUSER', LOCAL_DB_USER));
    $pass = env_value('DB_PASS', env_value('MYSQLPASSWORD', LOCAL_DB_PASS));

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
