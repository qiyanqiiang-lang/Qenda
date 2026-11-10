<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Security;

/**
 * Possession of a phone number. A format check is not an implementation of this.
 * The shipped adapter fails closed. Tests inject their own double only when
 * the application environment is test.
 */
interface PhonePossessionVerifier
{
    public function verifyPossession(string $phone, string $proof): VerificationResult;
}
