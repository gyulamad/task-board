<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Support;

use PDO;
use RuntimeException;

/**
 * Builds test databases from the project's schema.sql.
 *
 * SQLite is used as the default zero-setup mode for local development and
 * the fast CI job. The schema is translated with the following strictly
 * mechanical MySQL -> SQLite keyword conversions:
 *   - "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"  -> removed
 *   - ENUM('a','b')                            -> TEXT
 *   - TINYINT(1)                               -> INTEGER
 *   - INT UNSIGNED                             -> INTEGER
 *   - INT UNSIGNED AUTO_INCREMENT PRIMARY KEY  -> INTEGER PRIMARY KEY AUTOINCREMENT
 *
 * MySQL mode imports schema.sql verbatim, statement by statement.
 */
final class Schema
{
    public static function toSqlite(string $schemaSql): string
    {
        $sql = $schemaSql;
        $sql = preg_replace('/\s*ENGINE=InnoDB\s+DEFAULT\s+CHARSET=utf8mb4\s*;/i', ';', $sql);
        $sql = preg_replace('/ENUM\s*\([^)]*\)/i', 'TEXT', $sql);
        $sql = str_ireplace('TINYINT(1)', 'INTEGER', $sql);
        $sql = str_ireplace(' ON UPDATE CURRENT_TIMESTAMP', '', $sql);
        $sql = str_ireplace('INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = str_ireplace('INT UNSIGNED', 'INTEGER', $sql);
        if (str_contains($sql, 'ENGINE=') || str_contains($sql, 'ENUM(') || str_contains($sql, 'AUTO_INCREMENT')) {
            throw new RuntimeException('schema.sql could not be fully translated to SQLite.');
        }
        return $sql;
    }

    public static function createSqlite(string $dbFile): void
    {
        if (is_file($dbFile)) {
            unlink($dbFile);
        }
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $translated = self::toSqlite((string)file_get_contents(self::schemaPath()));
        $pdo->exec($translated);
    }

    public static function importMysql(string $host, string $name, string $user, string $pass): void
    {
        $server = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec("DROP DATABASE IF EXISTS `{$name}`");
        $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (self::mysqlStatements() as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }

    /** @return string[] */
    public static function mysqlStatements(): array
    {
        $sql = (string)file_get_contents(self::schemaPath());
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        return array_map('trim', $parts);
    }

    public static function schemaPath(): string
    {
        $path = dirname(__DIR__, 2) . '/schema.sql';
        if (!is_file($path)) {
            throw new RuntimeException('schema.sql not found at ' . $path);
        }
        return $path;
    }
}
