<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DailyChallengeRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM daily_challenge_attempts WHERE attempt_token = :token LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findLatestForUserDay(int $userId, string $dayKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM daily_challenge_attempts
             WHERE user_id = :user_id AND day_key = :day_key
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'day_key' => $dayKey]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array> */
    public function listForUserDay(int $userId, string $dayKey): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM daily_challenge_attempts
             WHERE user_id = :user_id AND day_key = :day_key
             ORDER BY id ASC'
        );
        $stmt->execute(['user_id' => $userId, 'day_key' => $dayKey]);
        return $stmt->fetchAll() ?: [];
    }

    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO daily_challenge_attempts (
                user_id, day_key, status, attempt_token, question_id, method_id, lesson_id,
                correct_answer, correct_option_ids, is_voice, time_limit_seconds, max_lesson_id,
                pick_seed, started_at_ms, committed_at_ms, submitted_at_ms, answer_raw,
                is_correct, coins_awarded, points_awarded, fail_reason, created_at, updated_at
            ) VALUES (
                :user_id, :day_key, :status, :attempt_token, :question_id, :method_id, :lesson_id,
                :correct_answer, :correct_option_ids, :is_voice, :time_limit_seconds, :max_lesson_id,
                :pick_seed, :started_at_ms, :committed_at_ms, :submitted_at_ms, :answer_raw,
                :is_correct, :coins_awarded, :points_awarded, :fail_reason, :created_at, :updated_at
            )'
        );

        $stmt->execute([
            'user_id' => (int) $data['user_id'],
            'day_key' => $data['day_key'],
            'status' => $data['status'],
            'attempt_token' => $data['attempt_token'],
            'question_id' => $data['question_id'] ?? null,
            'method_id' => $data['method_id'] ?? null,
            'lesson_id' => $data['lesson_id'] ?? null,
            'correct_answer' => $data['correct_answer'] ?? null,
            'correct_option_ids' => $data['correct_option_ids'] ?? null,
            'is_voice' => !empty($data['is_voice']) ? 1 : 0,
            'time_limit_seconds' => $data['time_limit_seconds'] ?? null,
            'max_lesson_id' => (int) ($data['max_lesson_id'] ?? 1),
            'pick_seed' => (int) ($data['pick_seed'] ?? 0),
            'started_at_ms' => (int) $data['started_at_ms'],
            'committed_at_ms' => $data['committed_at_ms'] ?? null,
            'submitted_at_ms' => $data['submitted_at_ms'] ?? null,
            'answer_raw' => $data['answer_raw'] ?? null,
            'is_correct' => $data['is_correct'] ?? null,
            'coins_awarded' => (int) ($data['coins_awarded'] ?? 0),
            'points_awarded' => (int) ($data['points_awarded'] ?? 0),
            'fail_reason' => $data['fail_reason'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->db->lastInsertId();
        return $this->findById($id) ?? [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM daily_challenge_attempts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function update(int $id, array $fields): array
    {
        $allowed = [
            'status', 'question_id', 'method_id', 'lesson_id', 'correct_answer', 'correct_option_ids',
            'is_voice', 'time_limit_seconds', 'max_lesson_id', 'pick_seed', 'committed_at_ms',
            'submitted_at_ms', 'answer_raw', 'is_correct', 'coins_awarded', 'points_awarded', 'fail_reason',
        ];

        $sets = [];
        $params = ['id' => $id, 'updated_at' => date('Y-m-d H:i:s')];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $sets[] = "{$key} = :{$key}";
            $params[$key] = $fields[$key];
        }
        $sets[] = 'updated_at = :updated_at';

        if (count($sets) === 1) {
            return $this->findById($id) ?? [];
        }

        $sql = 'UPDATE daily_challenge_attempts SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $this->findById($id) ?? [];
    }
}
