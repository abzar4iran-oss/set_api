<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function findByPhone(string $phone): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE phone = :phone LIMIT 1');
        $stmt->execute(['phone' => $phone]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO users (
                phone, first_name, last_name, age, school_grade, province, city,
                quran_reading_level, has_previous_class_experience, previous_class_details,
                profile_complete, phone_verified_at, created_at, updated_at
            ) VALUES (
                :phone, :first_name, :last_name, :age, :school_grade, :province, :city,
                :quran_reading_level, :has_previous_class_experience, :previous_class_details,
                :profile_complete, :phone_verified_at, :created_at, :updated_at
            )'
        );

        $stmt->execute([
            'phone' => $data['phone'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'age' => (int) $data['age'],
            'school_grade' => $data['school_grade'],
            'province' => $data['province'],
            'city' => $data['city'],
            'quran_reading_level' => $data['quran_reading_level'],
            'has_previous_class_experience' => !empty($data['has_previous_class_experience']) ? 1 : 0,
            'previous_class_details' => $data['previous_class_details'] ?? '',
            'profile_complete' => !empty($data['profile_complete']) ? 1 : 0,
            'phone_verified_at' => $data['phone_verified_at'] ?? $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->db->lastInsertId();
        return $this->findById($id) ?? [];
    }

    public function updateProfile(int $userId, array $data): array
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET
                first_name = :first_name,
                last_name = :last_name,
                age = :age,
                school_grade = :school_grade,
                province = :province,
                city = :city,
                quran_reading_level = :quran_reading_level,
                has_previous_class_experience = :has_previous_class_experience,
                previous_class_details = :previous_class_details,
                profile_complete = :profile_complete,
                updated_at = :updated_at
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $userId,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'age' => (int) $data['age'],
            'school_grade' => $data['school_grade'],
            'province' => $data['province'],
            'city' => $data['city'],
            'quran_reading_level' => $data['quran_reading_level'],
            'has_previous_class_experience' => !empty($data['has_previous_class_experience']) ? 1 : 0,
            'previous_class_details' => $data['previous_class_details'] ?? '',
            'profile_complete' => !empty($data['profile_complete']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->findById($userId) ?? [];
    }

    public function updateAvatarUrl(int $userId, ?string $avatarUrl): array
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET avatar_url = :avatar_url, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'id' => $userId,
            'avatar_url' => $avatarUrl,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->findById($userId) ?? [];
    }

    public function updateSettingsJson(int $userId, string $settingsJson): array
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET settings_json = :settings_json, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'id' => $userId,
            'settings_json' => $settingsJson,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->findById($userId) ?? [];
    }
}
