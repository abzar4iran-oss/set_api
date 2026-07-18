<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class WordPlayRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM word_play_attempts WHERE attempt_token = :token LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findOpenForUserStage(int $userId, int $stageIndex): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM word_play_attempts
             WHERE user_id = :user_id AND stage_index = :stage_index AND status = 'open'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'stage_index' => $stageIndex,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO word_play_attempts (
                user_id, status, attempt_token, stage_index, content_version,
                started_at_ms, hints_used, created_at, updated_at
            ) VALUES (
                :user_id, :status, :attempt_token, :stage_index, :content_version,
                :started_at_ms, :hints_used, :created_at, :updated_at
            )'
        );
        $stmt->execute([
            'user_id' => (int) $data['user_id'],
            'status' => (string) $data['status'],
            'attempt_token' => (string) $data['attempt_token'],
            'stage_index' => (int) $data['stage_index'],
            'content_version' => (int) $data['content_version'],
            'started_at_ms' => (int) $data['started_at_ms'],
            'hints_used' => (int) ($data['hints_used'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByToken((string) $data['attempt_token']) ?? [];
    }

    /** @param array<string, mixed> $fields */
    public function update(int $id, array $fields): ?array
    {
        $sets = [];
        $params = ['id' => $id];
        foreach ($fields as $key => $value) {
            $sets[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }
        $sets[] = 'updated_at = :updated_at';
        $params['updated_at'] = date('Y-m-d H:i:s');

        $sql = 'UPDATE word_play_attempts SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $stmt2 = $this->db->prepare('SELECT * FROM word_play_attempts WHERE id = :id LIMIT 1');
        $stmt2->execute(['id' => $id]);
        $row = $stmt2->fetch();
        return $row ?: null;
    }
}
