<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class OtpRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(string $phone, string $codeHash, string $expiresAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO otp_codes (phone, code_hash, expires_at, attempts, created_at)
             VALUES (:phone, :code_hash, :expires_at, 0, :created_at)'
        );
        $stmt->execute([
            'phone' => $phone,
            'code_hash' => $codeHash,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function latestActive(string $phone): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM otp_codes
             WHERE phone = :phone
               AND consumed_at IS NULL
               AND expires_at > :now
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'phone' => $phone,
            'now' => date('Y-m-d H:i:s'),
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function lastCreatedAt(string $phone): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT created_at FROM otp_codes WHERE phone = :phone ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['phone' => $phone]);
        $row = $stmt->fetch();
        return $row['created_at'] ?? null;
    }

    public function incrementAttempts(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function consume(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE otp_codes SET consumed_at = :now WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'now' => date('Y-m-d H:i:s'),
        ]);
    }
}
