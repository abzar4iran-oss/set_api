<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class CompeteMatchRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM compete_matches WHERE match_token = :token LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findActiveForUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM compete_matches
             WHERE user_id = :user_id AND status IN ('open', 'committed')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array> */
    public function listForUser(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->db->prepare(
            "SELECT * FROM compete_matches
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO compete_matches (
                user_id, match_token, status, seed, max_lesson_id, round_count, round_seconds,
                entry_fee, opponent_json, rounds_json, results_json, player_score, opponent_score,
                outcome, coins_spent, coins_awarded, points_awarded, started_at_ms,
                committed_at_ms, finished_at_ms, created_at, updated_at
            ) VALUES (
                :user_id, :match_token, :status, :seed, :max_lesson_id, :round_count, :round_seconds,
                :entry_fee, :opponent_json, :rounds_json, :results_json, :player_score, :opponent_score,
                :outcome, :coins_spent, :coins_awarded, :points_awarded, :started_at_ms,
                :committed_at_ms, :finished_at_ms, :created_at, :updated_at
            )'
        );

        $stmt->execute([
            'user_id' => (int) $data['user_id'],
            'match_token' => $data['match_token'],
            'status' => $data['status'],
            'seed' => (int) $data['seed'],
            'max_lesson_id' => (int) $data['max_lesson_id'],
            'round_count' => (int) $data['round_count'],
            'round_seconds' => (int) $data['round_seconds'],
            'entry_fee' => (int) $data['entry_fee'],
            'opponent_json' => $data['opponent_json'],
            'rounds_json' => $data['rounds_json'] ?? null,
            'results_json' => $data['results_json'] ?? null,
            'player_score' => (int) ($data['player_score'] ?? 0),
            'opponent_score' => (int) ($data['opponent_score'] ?? 0),
            'outcome' => $data['outcome'] ?? null,
            'coins_spent' => (int) ($data['coins_spent'] ?? 0),
            'coins_awarded' => (int) ($data['coins_awarded'] ?? 0),
            'points_awarded' => (int) ($data['points_awarded'] ?? 0),
            'started_at_ms' => (int) $data['started_at_ms'],
            'committed_at_ms' => $data['committed_at_ms'] ?? null,
            'finished_at_ms' => $data['finished_at_ms'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByToken((string) $data['match_token']) ?? [];
    }

    public function update(int $id, array $fields): array
    {
        $allowed = [
            'status', 'rounds_json', 'results_json', 'player_score', 'opponent_score',
            'outcome', 'coins_spent', 'coins_awarded', 'points_awarded',
            'committed_at_ms', 'finished_at_ms',
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

        $sql = 'UPDATE compete_matches SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->db->prepare($sql)->execute($params);

        $stmt = $this->db->prepare('SELECT * FROM compete_matches WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: [];
    }

    /** @return list<array{user_id:int,compete_points:int,first_name:string,last_name:string,avatar_url:?string,city:string}> */
    public function leaderboard(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $sql = "SELECT u.id AS user_id, u.first_name, u.last_name, u.avatar_url, u.city,
                       COALESCE(p.compete_points, 0) AS compete_points,
                       COALESCE(p.matches_played, 0) AS matches_played
                FROM users u
                LEFT JOIN user_progress p ON p.user_id = u.id
                ORDER BY compete_points DESC, matches_played DESC, u.id ASC
                LIMIT {$limit}";
        return $this->db->query($sql)->fetchAll() ?: [];
    }
}
