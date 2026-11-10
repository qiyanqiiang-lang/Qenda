<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "hash guard is a local cli script\n");
    exit(2);
}

$repo = dirname(__DIR__, 3);
$readme = $repo . '/README.md';
$expectedReadme = '64b9ebb9b196612b9ad93859faa24385';
$actualReadme = is_file($readme) ? md5_file($readme) : '';
$failed = false;
echo "README.md md5={$actualReadme} expected={$expectedReadme}\n";
if (!hash_equals($expectedReadme, (string) $actualReadme)) {
    echo "README hash mismatch\n";
    $failed = true;
}

$anchors = [
    'Caishen/api/verify.php' => 'fcda5b23af14045083e4b6fa57d6d792',
    'Caishen/api/codes.php' => '064bab4092bb9d294c763e67e52b9a7c',
];
foreach ($anchors as $path => $anchor) {
    $full = $repo . '/' . $path;
    if (is_file($full)) {
        echo "{$path} PRESENT md5=" . md5_file($full) . " live_anchor={$anchor}\n";
        $failed = true;
        continue;
    }
    echo "{$path} ABSENT live_anchor={$anchor} not_recomputed production_not_contacted\n";
}

$forbiddenNames = ['verify.php', 'codes.php'];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    if (in_array($file->getFilename(), $forbiddenNames, true)) {
        echo "forbidden filename present: {$path}\n";
        $failed = true;
    }
}

echo $failed ? "hash guard failed\n" : "hash guard ok\n";
exit($failed ? 1 : 0);
