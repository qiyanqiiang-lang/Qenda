<?php

declare(strict_types=1);

namespace Qenda\CampaignShare;

use PDO;

final class Sqlite
{
    public static function open(string $dsn): PDO
    {
        if ($dsn !== 'sqlite::memory:' && !str_starts_with($dsn, 'sqlite:')) {
            throw new \InvalidArgumentException('only sqlite fixtures are allowed');
        }
        if ($dsn !== 'sqlite::memory:') {
            FixturePath::assertSafeSqliteFile(substr($dsn, strlen('sqlite:')), dirname(__DIR__));
        }
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 8000');
        if ($dsn !== 'sqlite::memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return $pdo;
    }

    public static function execFile(PDO $pdo, string $file): void
    {
        $sql = (string) file_get_contents($file);
        $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }
}
