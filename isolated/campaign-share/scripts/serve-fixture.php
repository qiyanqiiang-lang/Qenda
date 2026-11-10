<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Qenda\CampaignShare\App;
use Qenda\CampaignShare\Config;
use Qenda\CampaignShare\FixturePath;
use Qenda\CampaignShare\Http\Request;
use Qenda\CampaignShare\Security\VerifierFactory;
use Qenda\CampaignShare\Sqlite;

$checkMode = PHP_SAPI === 'cli' && empty($_SERVER['REQUEST_METHOD']);
if ($checkMode) {
    $path = $argv[2] ?? '';
    if (($argv[1] ?? '') !== '--check' || $path === '') {
        fwrite(STDERR, "usage: php scripts/serve-fixture.php --check /tmp/example.sqlite\n");
        exit(2);
    }
    try {
        FixturePath::assertSafeSqliteFile($path, dirname(__DIR__));
        echo "ok\n";
        exit(0);
    } catch (\Throwable) {
        fwrite(STDERR, "refused\n");
        exit(2);
    }
}

$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if ($remote !== '127.0.0.1' && $remote !== '::1') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "forbidden\n";
    return;
}

$db = (string) getenv('CAMPAIGN_SHARE_FIXTURE_DB');
try {
    FixturePath::assertSafeSqliteFile($db, dirname(__DIR__));
    $pdo = Sqlite::open('sqlite:' . $db);
    $config = Config::fromArray([
        'enabled' => true,
        'environment' => 'local-fixture',
        'verifier' => 'fail_closed',
        'allow_test_verifier' => false,
        'allowed_origin' => 'https://qenda.cn',
        'public_base' => 'https://qenda.cn',
        'cookie_secure' => true,
    ]);
    $app = App::create($pdo, $config, (new VerifierFactory())->make($config));
    $app->handle(Request::fromGlobals())->emit();
} catch (\Throwable) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    echo '{"ok":false,"message":"not_found"}';
}
