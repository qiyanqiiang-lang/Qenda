<?php

declare(strict_types=1);

/**
 * Shipped default. The web entry also forces this package off.
 * This file is not a production switch for qenda.cn.
 */
return [
    'enabled' => false,
    'environment' => 'production',
    'verifier' => 'fail_closed',
    'allow_test_verifier' => false,
    'allowed_origin' => 'https://qenda.cn',
    'public_base' => 'https://qenda.cn',
    'session_ttl' => 1800,
    'challenge_ttl' => 600,
    'rate_max' => 5,
    'rate_window' => 600,
    'cookie_secure' => true,
];
