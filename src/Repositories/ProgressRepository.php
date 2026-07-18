<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ProgressRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM user_progress WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(int $userId, array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO user_progress (
                user_id, revision, coins, hearts, compete_points, is_premium, premium_expires_at,
                last_heart_regen_at, current_lesson, pending_surah_after_lesson,
                takeoff_platform_completed, matches_played,
                daily_challenge_locked_until, daily_challenge_won_day_key,
                daily_challenge_played_day_key, daily_challenge_played_question_ids,
                word_play_level_index, word_play_content_version,
                created_at, updated_at
            ) VALUES (
                :user_id, :revision, :coins, :hearts, :compete_points, :is_premium, :premium_expires_at,
                :last_heart_regen_at, :current_lesson, :pending_surah_after_lesson,
                :takeoff_platform_completed, :matches_played,
                :daily_challenge_locked_until, :daily_challenge_won_day_key,
                :daily_challenge_played_day_key, :daily_challenge_played_question_ids,
                :word_play_level_index, :word_play_content_version,
                :created_at, :updated_at
            )'
        );

        $stmt->execute([
            'user_id' => $userId,
            'revision' => (int) ($data['revision'] ?? 0),
            'coins' => (int) $data['coins'],
            'hearts' => (int) $data['hearts'],
            'compete_points' => (int) $data['compete_points'],
            'is_premium' => !empty($data['is_premium']) ? 1 : 0,
            'premium_expires_at' => $data['premium_expires_at'] ?? null,
            'last_heart_regen_at' => $data['last_heart_regen_at'],
            'current_lesson' => (int) $data['current_lesson'],
            'pending_surah_after_lesson' => $data['pending_surah_after_lesson'],
            'takeoff_platform_completed' => !empty($data['takeoff_platform_completed']) ? 1 : 0,
            'matches_played' => (int) ($data['matches_played'] ?? 0),
            'daily_challenge_locked_until' => $data['daily_challenge_locked_until'],
            'daily_challenge_won_day_key' => $data['daily_challenge_won_day_key'],
            'daily_challenge_played_day_key' => $data['daily_challenge_played_day_key'],
            'daily_challenge_played_question_ids' => $data['daily_challenge_played_question_ids'] ?? '[]',
            'word_play_level_index' => (int) ($data['word_play_level_index'] ?? 0),
            'word_play_content_version' => (int) ($data['word_play_content_version'] ?? 9),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByUserId($userId) ?? [];
    }

    public function save(int $userId, array $data): array
    {
        $stmt = $this->db->prepare(
            'UPDATE user_progress SET
                revision = :revision,
                coins = :coins,
                hearts = :hearts,
                compete_points = :compete_points,
                is_premium = :is_premium,
                premium_expires_at = :premium_expires_at,
                last_heart_regen_at = :last_heart_regen_at,
                current_lesson = :current_lesson,
                pending_surah_after_lesson = :pending_surah_after_lesson,
                takeoff_platform_completed = :takeoff_platform_completed,
                matches_played = :matches_played,
                daily_challenge_locked_until = :daily_challenge_locked_until,
                daily_challenge_won_day_key = :daily_challenge_won_day_key,
                daily_challenge_played_day_key = :daily_challenge_played_day_key,
                daily_challenge_played_question_ids = :daily_challenge_played_question_ids,
                word_play_level_index = :word_play_level_index,
                word_play_content_version = :word_play_content_version,
                updated_at = :updated_at
             WHERE user_id = :user_id'
        );

        $stmt->execute([
            'user_id' => $userId,
            'revision' => (int) $data['revision'],
            'coins' => (int) $data['coins'],
            'hearts' => (int) $data['hearts'],
            'compete_points' => (int) $data['compete_points'],
            'is_premium' => !empty($data['is_premium']) ? 1 : 0,
            'premium_expires_at' => $data['premium_expires_at'] ?? null,
            'last_heart_regen_at' => $data['last_heart_regen_at'],
            'current_lesson' => (int) $data['current_lesson'],
            'pending_surah_after_lesson' => $data['pending_surah_after_lesson'],
            'takeoff_platform_completed' => !empty($data['takeoff_platform_completed']) ? 1 : 0,
            'matches_played' => (int) $data['matches_played'],
            'daily_challenge_locked_until' => $data['daily_challenge_locked_until'],
            'daily_challenge_won_day_key' => $data['daily_challenge_won_day_key'],
            'daily_challenge_played_day_key' => $data['daily_challenge_played_day_key'],
            'daily_challenge_played_question_ids' => $data['daily_challenge_played_question_ids'],
            'word_play_level_index' => (int) ($data['word_play_level_index'] ?? 0),
            'word_play_content_version' => (int) ($data['word_play_content_version'] ?? 9),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->findByUserId($userId) ?? [];
    }

    public function addEvent(
        int $userId,
        string $action,
        array $payload,
        int $coinsDelta,
        int $heartsDelta,
        int $pointsDelta,
        int $revisionAfter
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO progress_events (
                user_id, action, payload_json, coins_delta, hearts_delta, points_delta,
                revision_after, created_at
            ) VALUES (
                :user_id, :action, :payload_json, :coins_delta, :hearts_delta, :points_delta,
                :revision_after, :created_at
            )'
        );

        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'coins_delta' => $coinsDelta,
            'hearts_delta' => $heartsDelta,
            'points_delta' => $pointsDelta,
            'revision_after' => $revisionAfter,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
