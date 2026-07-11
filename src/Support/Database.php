<?php
declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(array $config): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = $config['db']['driver'] ?? 'sqlite';

        try {
            if ($driver === 'mysql') {
                self::$pdo = self::mysql($config['db']);
            } else {
                self::$pdo = self::sqlite($config['db']);
            }
            self::migrate(self::$pdo, $driver === 'mysql');
        } catch (PDOException $e) {
            throw new RuntimeException('اتصال به پایگاه‌داده برقرار نشد: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    private static function mysql(array $db): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            (int) $db['port'],
            $db['name'],
            $db['charset'] ?? 'utf8mb4'
        );

        return new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private static function sqlite(array $db): PDO
    {
        $path = $db['sqlite_path'] ?? (dirname(__DIR__, 2) . '/storage/database.sqlite');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    private static function migrate(PDO $pdo, bool $mysql): void
    {
        $id = $mysql
            ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $fkUser = $mysql
            ? 'BIGINT UNSIGNED NOT NULL'
            : 'INTEGER NOT NULL';
        $bool = $mysql ? 'TINYINT(1) NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
        $text = $mysql ? 'VARCHAR(255)' : 'TEXT';
        $longText = $mysql ? 'TEXT' : 'TEXT';
        $dt = $mysql ? 'DATETIME' : 'TEXT';

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS users (
                id {$id},
                phone {$text} NOT NULL UNIQUE,
                first_name {$text} NOT NULL DEFAULT '',
                last_name {$text} NOT NULL DEFAULT '',
                age INTEGER NOT NULL DEFAULT 0,
                school_grade {$text} NOT NULL DEFAULT '',
                province {$text} NOT NULL DEFAULT '',
                city {$text} NOT NULL DEFAULT '',
                quran_reading_level {$text} NOT NULL DEFAULT '',
                has_previous_class_experience {$bool},
                previous_class_details {$longText} NULL,
                profile_complete {$bool},
                phone_verified_at {$dt} NULL,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS otp_codes (
                id {$id},
                phone {$text} NOT NULL,
                code_hash {$text} NOT NULL,
                expires_at {$dt} NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                consumed_at {$dt} NULL,
                created_at {$dt} NOT NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS auth_tokens (
                id {$id},
                user_id {$fkUser},
                token_hash {$text} NOT NULL UNIQUE,
                expires_at {$dt} NOT NULL,
                revoked_at {$dt} NULL,
                created_at {$dt} NOT NULL,
                last_used_at {$dt} NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS registration_tokens (
                id {$id},
                phone {$text} NOT NULL,
                token_hash {$text} NOT NULL UNIQUE,
                expires_at {$dt} NOT NULL,
                consumed_at {$dt} NULL,
                created_at {$dt} NOT NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_progress (
                user_id " . ($mysql ? 'BIGINT UNSIGNED NOT NULL PRIMARY KEY' : 'INTEGER PRIMARY KEY') . ",
                revision INTEGER NOT NULL DEFAULT 0,
                coins INTEGER NOT NULL DEFAULT 100,
                hearts INTEGER NOT NULL DEFAULT 5,
                compete_points INTEGER NOT NULL DEFAULT 0,
                is_premium {$bool},
                last_heart_regen_at INTEGER NULL,
                current_lesson INTEGER NOT NULL DEFAULT 1,
                pending_surah_after_lesson INTEGER NULL,
                takeoff_platform_completed {$bool},
                matches_played INTEGER NOT NULL DEFAULT 0,
                daily_challenge_locked_until INTEGER NULL,
                daily_challenge_won_day_key {$text} NULL,
                daily_challenge_played_day_key {$text} NULL,
                daily_challenge_played_question_ids {$longText} NOT NULL,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS progress_events (
                id {$id},
                user_id {$fkUser},
                action {$text} NOT NULL,
                payload_json {$longText} NOT NULL,
                coins_delta INTEGER NOT NULL DEFAULT 0,
                hearts_delta INTEGER NOT NULL DEFAULT 0,
                points_delta INTEGER NOT NULL DEFAULT 0,
                revision_after INTEGER NOT NULL DEFAULT 0,
                created_at {$dt} NOT NULL
            )"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS daily_challenge_attempts (
                id {$id},
                user_id {$fkUser},
                day_key {$text} NOT NULL,
                status {$text} NOT NULL,
                attempt_token {$text} NOT NULL UNIQUE,
                question_id {$text} NULL,
                method_id {$text} NULL,
                lesson_id INTEGER NULL,
                correct_answer {$longText} NULL,
                correct_option_ids {$longText} NULL,
                is_voice " . ($mysql ? 'TINYINT(1) NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0') . ",
                time_limit_seconds INTEGER NULL,
                max_lesson_id INTEGER NOT NULL DEFAULT 1,
                pick_seed INTEGER NOT NULL DEFAULT 0,
                started_at_ms INTEGER NOT NULL,
                committed_at_ms INTEGER NULL,
                submitted_at_ms INTEGER NULL,
                answer_raw {$longText} NULL,
                is_correct " . ($mysql ? 'TINYINT(1) NULL' : 'INTEGER NULL') . ",
                coins_awarded INTEGER NOT NULL DEFAULT 0,
                points_awarded INTEGER NOT NULL DEFAULT 0,
                fail_reason {$text} NULL,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            )"
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_daily_challenge_user_day
             ON daily_challenge_attempts (user_id, day_key)'
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS compete_matches (
                id {$id},
                user_id {$fkUser},
                match_token {$text} NOT NULL UNIQUE,
                status {$text} NOT NULL,
                seed INTEGER NOT NULL,
                max_lesson_id INTEGER NOT NULL DEFAULT 1,
                round_count INTEGER NOT NULL DEFAULT 5,
                round_seconds INTEGER NOT NULL DEFAULT 15,
                entry_fee INTEGER NOT NULL DEFAULT 0,
                opponent_json {$longText} NOT NULL,
                rounds_json {$longText} NULL,
                results_json {$longText} NULL,
                player_score INTEGER NOT NULL DEFAULT 0,
                opponent_score INTEGER NOT NULL DEFAULT 0,
                outcome {$text} NULL,
                coins_spent INTEGER NOT NULL DEFAULT 0,
                coins_awarded INTEGER NOT NULL DEFAULT 0,
                points_awarded INTEGER NOT NULL DEFAULT 0,
                started_at_ms INTEGER NOT NULL,
                committed_at_ms INTEGER NULL,
                finished_at_ms INTEGER NULL,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            )"
        );

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_compete_matches_user
             ON compete_matches (user_id, id DESC)'
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS shop_orders (
                id {$id},
                user_id {$fkUser},
                order_token {$text} NOT NULL UNIQUE,
                client_request_id {$text} NULL,
                product_type {$text} NOT NULL,
                product_id {$text} NOT NULL,
                product_title {$text} NOT NULL,
                amount_irr INTEGER NOT NULL,
                currency {$text} NOT NULL DEFAULT 'IRR',
                coins_grant INTEGER NOT NULL DEFAULT 0,
                vip_days INTEGER NOT NULL DEFAULT 0,
                status {$text} NOT NULL,
                provider {$text} NOT NULL,
                provider_authority {$text} NULL,
                provider_ref_id {$text} NULL,
                payment_url {$longText} NULL,
                meta_json {$longText} NULL,
                paid_at {$dt} NULL,
                fulfilled_at {$dt} NULL,
                expires_at {$dt} NULL,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            )"
        );

        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_shop_orders_client_req
             ON shop_orders (user_id, client_request_id)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_shop_orders_user
             ON shop_orders (user_id, id DESC)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_shop_orders_authority
             ON shop_orders (provider_authority)'
        );

        self::ensureColumn($pdo, 'user_progress', 'premium_expires_at', $mysql ? 'DATETIME NULL' : 'TEXT NULL');
        self::ensureColumn($pdo, 'users', 'avatar_url', $mysql ? 'VARCHAR(512) NULL' : 'TEXT NULL');
        self::ensureColumn($pdo, 'users', 'settings_json', $mysql ? 'LONGTEXT NULL' : 'TEXT NULL');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS support_tickets (
                id {$id},
                user_id {$fkUser},
                public_id {$text} NOT NULL UNIQUE,
                subject {$text} NOT NULL,
                category {$text} NOT NULL,
                status {$text} NOT NULL,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            )"
        );
        self::ensureColumn($pdo, 'support_tickets', 'user_seen_at', $mysql ? 'DATETIME NULL' : 'TEXT NULL');
        self::ensureColumn($pdo, 'support_tickets', 'priority', $mysql ? "VARCHAR(32) NOT NULL DEFAULT 'normal'" : "TEXT NOT NULL DEFAULT 'normal'");
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_support_tickets_user
             ON support_tickets (user_id, id DESC)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_support_tickets_status
             ON support_tickets (status, updated_at DESC)'
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS support_ticket_messages (
                id {$id},
                ticket_id {$fkUser},
                public_id {$text} NOT NULL UNIQUE,
                author {$text} NOT NULL,
                body {$longText} NOT NULL,
                created_at {$dt} NOT NULL
            )"
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_support_messages_ticket
             ON support_ticket_messages (ticket_id, id ASC)'
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_notifications (
                id {$id},
                user_id {$fkUser},
                public_id {$text} NOT NULL,
                title {$text} NOT NULL,
                body {$longText} NOT NULL,
                kind {$text} NOT NULL,
                source {$text} NOT NULL DEFAULT 'system',
                deep_link {$text} NULL,
                meta_json {$longText} NULL,
                read_at {$dt} NULL,
                dismissed_at {$dt} NULL,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            )"
        );
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_user_notifications_public
             ON user_notifications (user_id, public_id)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_user_notifications_user
             ON user_notifications (user_id, id DESC)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_user_notifications_unread
             ON user_notifications (user_id, read_at)'
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_push_tokens (
                id {$id},
                user_id {$fkUser},
                token {$text} NOT NULL,
                platform {$text} NULL,
                device_id {$text} NULL,
                app_version {$text} NULL,
                last_seen_at {$dt} NOT NULL,
                created_at {$dt} NOT NULL,
                updated_at {$dt} NOT NULL
            )"
        );
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_user_push_tokens_token
             ON user_push_tokens (token)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_user_push_tokens_user
             ON user_push_tokens (user_id)'
        );
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll();
            foreach ($cols as $col) {
                if (($col['name'] ?? '') === $column) {
                    return;
                }
            }
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            return;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}
