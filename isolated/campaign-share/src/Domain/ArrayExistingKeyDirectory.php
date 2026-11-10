<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Domain;

final class ArrayExistingKeyDirectory implements ExistingKeyDirectory
{
    /** @param list<array{phone: string, secret: string, public_id: string, attribution: ?string, source: string}> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function findByPhone(string $phone): ?LegacyBinding
    {
        foreach ($this->rows as $row) {
            if ($row['phone'] === $phone) {
                return new LegacyBinding($row['secret'], $row['public_id'], $row['attribution'], $row['source']);
            }
        }

        return null;
    }

    /** @return list<array{phone: string, secret: string, public_id: string, attribution: ?string, source: string}> */
    public function snapshot(): array
    {
        return $this->rows;
    }
}
