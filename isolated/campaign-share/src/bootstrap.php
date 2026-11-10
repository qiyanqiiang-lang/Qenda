<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Qenda\\CampaignShare\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/' . $relative;
    if (is_file($file)) {
        require $file;
    }
});
