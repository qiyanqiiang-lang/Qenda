<?php

declare(strict_types=1);

ini_set('display_errors', '0');

require dirname(__DIR__) . '/src/bootstrap.php';

use Qenda\CampaignShare\Domain\ClaimService;
use Qenda\CampaignShare\Domain\NullExistingKeyDirectory;
use Qenda\CampaignShare\Security\SessionStore;
use Qenda\CampaignShare\Sqlite;

try {
    $pdo = Sqlite::open('sqlite:' . ($argv[1] ?? ''));
    $clock = static fn (): int => time();
    $sessions = new SessionStore($pdo, $clock, 1800);
    $claims = new ClaimService($pdo, $sessions, new NullExistingKeyDirectory(), $clock);
    $outcome = $claims->claimForSession((string) ($argv[2] ?? ''));
    echo json_encode([
        'type' => $outcome->type,
        'message' => $outcome->message,
        'secret' => $outcome->secret,
        'public_id' => $outcome->publicId,
        'denial' => $outcome->denialCode,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
