<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Domain;

use PDO;
use Qenda\CampaignShare\Copy;
use Qenda\CampaignShare\PhoneRules;

final class PromoterReadModel
{
    private const LIST_SQL = 'SELECT c.visitor_label, k.public_id AS key_public_id, c.status, c.claimed_at, c.used_at, c.source_code
        FROM campaign_share_claims c
        INNER JOIN campaign_share_keys k ON k.id = c.key_id
        WHERE c.promoter_id = ?
        ORDER BY c.id ASC';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, string|null>> */
    public function listForPromoter(int $promoterId): array
    {
        $stmt = $this->pdo->prepare(self::LIST_SQL);
        $stmt->execute([$promoterId]);

        return array_map($this->present(...), $stmt->fetchAll() ?: []);
    }

    /** @return array{ok: bool, message?: string, items?: list<array<string, string|null>>} */
    public function search(int $promoterId, string $query): array
    {
        $query = trim($query);
        if (strlen($query) > 80 || PhoneRules::looksLikePhoneQuery($query)) {
            return ['ok' => false, 'message' => Copy::SEARCH_REJECTED];
        }
        if ($query === '') {
            return ['ok' => true, 'items' => $this->listForPromoter($promoterId)];
        }
        $status = $this->statusCode($query);
        if ($status !== null) {
            $sql = self::LIST_SQL;
            $sql = str_replace('ORDER BY c.id ASC', 'AND c.status = ? ORDER BY c.id ASC', $sql);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$promoterId, $status]);

            return ['ok' => true, 'items' => array_map($this->present(...), $stmt->fetchAll() ?: [])];
        }
        if (preg_match('/^U\d+$/', $query) === 1) {
            $sql = str_replace('ORDER BY c.id ASC', 'AND c.visitor_label = ? ORDER BY c.id ASC', self::LIST_SQL);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$promoterId, $query]);

            return ['ok' => true, 'items' => array_map($this->present(...), $stmt->fetchAll() ?: [])];
        }
        if (preg_match('/^kid_[a-f0-9]+$/', $query) === 1) {
            $sql = str_replace('ORDER BY c.id ASC', 'AND k.public_id = ? ORDER BY c.id ASC', self::LIST_SQL);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$promoterId, $query]);

            return ['ok' => true, 'items' => array_map($this->present(...), $stmt->fetchAll() ?: [])];
        }

        return ['ok' => true, 'items' => []];
    }

    /** @return array<string, string|null>|null */
    public function detail(int $promoterId, string $id): ?array
    {
        if (PhoneRules::looksLikePhoneQuery($id)) {
            return null;
        }
        $column = null;
        if (preg_match('/^U\d+$/', $id) === 1) {
            $column = 'c.visitor_label';
        } elseif (preg_match('/^kid_[a-f0-9]+$/', $id) === 1) {
            $column = 'k.public_id';
        }
        if ($column === null) {
            return null;
        }
        $sql = str_replace('ORDER BY c.id ASC', 'AND ' . $column . ' = ? ORDER BY c.id ASC', self::LIST_SQL);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$promoterId, $id]);
        $row = $stmt->fetch();

        return $row ? $this->present($row) : null;
    }

    public function exportCsv(int $promoterId): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('export failed');
        }
        fputcsv($handle, ['用户序号', '密钥标识', '状态', '领取时间', '使用时间', '来源推广码']);
        foreach ($this->listForPromoter($promoterId) as $row) {
            fputcsv($handle, [
                $this->csvSafe((string) $row['visitor_label']),
                $this->csvSafe((string) $row['key_public_id']),
                $this->csvSafe((string) $row['status_label']),
                $this->csvSafe((string) $row['claimed_at']),
                $this->csvSafe((string) ($row['used_at'] ?? '')),
                $this->csvSafe((string) $row['source_code']),
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF" . ($csv === false ? '' : $csv);
    }

    /** @param array<string, mixed> $row
     *  @return array<string, string|null>
     */
    private function present(array $row): array
    {
        return [
            'visitor_label' => (string) $row['visitor_label'],
            'key_public_id' => (string) $row['key_public_id'],
            'status' => (string) $row['status'],
            'status_label' => (string) $row['status'] === 'used' ? Copy::STATUS_USED : Copy::STATUS_BOUND,
            'claimed_at' => gmdate('c', (int) $row['claimed_at']),
            'used_at' => $row['used_at'] === null ? null : gmdate('c', (int) $row['used_at']),
            'source_code' => (string) $row['source_code'],
        ];
    }

    private function statusCode(string $query): ?string
    {
        return match ($query) {
            'claimed_bound', Copy::STATUS_BOUND => 'claimed_bound',
            'used', Copy::STATUS_USED => 'used',
            default => null,
        };
    }

    private function csvSafe(string $value): string
    {
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }
}
