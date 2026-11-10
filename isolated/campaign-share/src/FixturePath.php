<?php

declare(strict_types=1);

namespace Qenda\CampaignShare;

final class FixturePath
{
    public static function assertSafeSqliteFile(string $path, string $packageRoot): void
    {
        $lower = strtolower($path);
        foreach (['1panel', 'qenda.cn', 'mysql:', 'host=', 'dbname=', 'password=', 'ssh', 'aliyun'] as $blocked) {
            if (str_contains($lower, $blocked)) {
                throw new \RuntimeException('refusing unsafe database path');
            }
        }
        if (!str_ends_with($lower, '.sqlite') && !str_ends_with($lower, '.sqlite3')) {
            throw new \RuntimeException('fixture database must be a sqlite file');
        }
        $parent = dirname($path);
        $realParent = realpath($parent);
        if ($realParent === false) {
            throw new \RuntimeException('fixture database directory does not exist');
        }
        $tmp = realpath(sys_get_temp_dir());
        $var = $packageRoot . '/var';
        if (!is_dir($var)) {
            mkdir($var, 0700, true);
        }
        $realVar = realpath($var);
        $allowed = false;
        if ($tmp !== false && str_starts_with($realParent, $tmp)) {
            $allowed = true;
        }
        if ($realVar !== false && str_starts_with($realParent, $realVar)) {
            $allowed = true;
        }
        if (!$allowed) {
            throw new \RuntimeException('fixture database must live under temp or package var');
        }
    }
}
