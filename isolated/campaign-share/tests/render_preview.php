<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Qenda\CampaignShare\View\SharePage;

$target = $argv[1] ?? sys_get_temp_dir();
if (!is_dir($target)) {
    fwrite(STDERR, "missing directory\n");
    exit(2);
}
$page = new SharePage();
$base = [
    'invite_valid' => true,
    'invite' => 'inv_' . str_repeat('a', 20),
    'period_closed' => false,
    'csrf_token' => str_repeat('b', 64),
];
file_put_contents($target . '/share.html', $page->render($base + ['dialog_open' => false]));
file_put_contents($target . '/share-dialog.html', $page->render($base + ['dialog_open' => true]));
file_put_contents($target . '/share-closed.html', $page->render([
    'invite_valid' => true,
    'invite' => 'inv_' . str_repeat('c', 20),
    'period_closed' => true,
    'csrf_token' => str_repeat('d', 64),
    'dialog_open' => false,
]));
echo "wrote previews\n";
