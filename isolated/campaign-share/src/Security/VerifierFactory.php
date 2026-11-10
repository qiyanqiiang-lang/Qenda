<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Security;

use Qenda\CampaignShare\Config;

final class VerifierFactory
{
    public function make(Config $config): PhonePossessionVerifier
    {
        $name = strtolower($config->verifier);
        if ($name !== 'fail_closed') {
            throw new \RuntimeException('refusing verifier that is not fail-closed');
        }

        return new FailClosedPhonePossessionVerifier();
    }
}
