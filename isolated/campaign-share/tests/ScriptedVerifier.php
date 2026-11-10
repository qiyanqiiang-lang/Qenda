<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Tests;

use Qenda\CampaignShare\Security\PhonePossessionVerifier;
use Qenda\CampaignShare\Security\VerificationResult;

final class ScriptedVerifier implements PhonePossessionVerifier
{
    public int $calls = 0;

    public function __construct(private readonly string $proof = 'test-proof-ok')
    {
    }

    public function verifyPossession(string $phone, string $proof): VerificationResult
    {
        $this->calls++;
        if (hash_equals($this->proof, $proof)) {
            return VerificationResult::pass('test_double');
        }

        return VerificationResult::fail('bad_proof');
    }
}
