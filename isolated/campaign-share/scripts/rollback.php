<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !empty($_SERVER['REQUEST_METHOD'])) {
    fwrite(STDERR, "rollback is a local cli script\n");
    exit(2);
}

$apply = false;
$root = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if ($arg === '--policy') {
        echo "Rollback writes config/force-off.flag and deletes config/local-enable.php if that file exists.\n";
        echo "It does not open a database, does not run sql/down, and does not contact a network host.\n";
        echo "sql/down drops isolated tables and would delete keys already issued. Do not use it as production rollback.\n";
        echo "Already issued isolated keys stay in place when the feature is forced off.\n";
        exit(0);
    }
    if ($arg === '--database' || $arg === '--dsn' || str_starts_with($arg, '--database=') || str_starts_with($arg, '--dsn=')) {
        fwrite(STDERR, "refusing database argument; rollback does not connect\n");
        exit(2);
    }
    if (str_starts_with($arg, '--root=')) {
        $root = substr($arg, 7);
    }
}

if ($root === null) {
    fwrite(STDERR, "missing --root=PATH\n");
    exit(2);
}

$lower = strtolower($root);
foreach (['1panel', 'qenda.cn', 'mysql:', 'host=', 'password=', 'ssh', 'aliyun'] as $blocked) {
    if (str_contains($lower, $blocked)) {
        fwrite(STDERR, "refusing unsafe root\n");
        exit(2);
    }
}

$configDir = rtrim($root, '/') . '/config';
if (!is_dir($configDir)) {
    fwrite(STDERR, "config directory is missing\n");
    exit(2);
}

$flag = $configDir . '/force-off.flag';
$local = $configDir . '/local-enable.php';
if (!$apply) {
    echo "dry-run: would write {$flag}\n";
    if (is_file($local)) {
        echo "dry-run: would delete {$local}\n";
    }
    echo "dry-run: sql down is not executed\n";
    exit(0);
}

file_put_contents($flag, "off\n");
if (is_file($local)) {
    unlink($local);
}
echo "applied force-off\n";
echo "sql down was not executed\n";
