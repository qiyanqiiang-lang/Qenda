<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Security;

final class FailClosedPhonePossessionVerifier implements PhonePossessionVerifier
{
    public function verifyPossession(string $phone, string $proof): VerificationResult
    {
        return VerificationResult::fail('verifier_unavailable');
    }
}
