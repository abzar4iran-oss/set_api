<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SupportTicketRepository;
use RuntimeException;

final class SupportService
{
    private const CATEGORIES = ['ticket', 'quick_message', 'callback_request'];
    private const STATUSES = ['open', 'answered', 'closed'];
    private const PRIORITIES = ['low', 'normal', 'high'];

    public function __construct(
        private array $appConfig,
        private SupportTicketRepository $tickets,
        private RemoteConfigService $remoteConfig,
        private ?NotificationService $notifications = null
    ) {
    }

    /** اطلاعات تماس عمومی — بدون نیاز به auth */
    public function contactInfo(): array
    {
        $this->assertEnabled();
        $cfg = $this->appConfig['support'] ?? [];
        $rc = $this->remoteConfig->getPublicConfig()['support'] ?? [];

        $email = (string) ($rc['email'] ?? $cfg['email'] ?? 'support@alnajmothagheb.com');
        $phone = (string) ($rc['phone'] ?? $cfg['phone'] ?? '');
        $telegram = (string) ($rc['telegram'] ?? $cfg['telegram'] ?? '');
        $whatsapp = (string) ($rc['whatsapp'] ?? $cfg['whatsapp'] ?? '');
        $hours = (string) ($rc['hours'] ?? $cfg['hours'] ?? 'شنبه تا پنج‌شنبه، ۹ تا ۱۷');
        $message = (string) ($rc['message'] ?? $cfg['message'] ?? 'تیم پشتیبانی پاسخگوی شماست.');

        return [
            'enabled' => true,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'telegram' => $telegram !== '' ? $telegram : null,
            'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
            'hours' => $hours,
            'message' => $message,
            'channels' => array_values(array_filter([
                [
                    'id' => 'email',
                    'label' => 'ایمیل',
                    'value' => $email,
                    'action' => 'mailto:' . $email,
                ],
                $phone !== '' ? [
                    'id' => 'phone',
                    'label' => 'تماس تلفنی',
                    'value' => $phone,
                    'action' => 'tel:' . preg_replace('/\s+/', '', $phone),
                ] : null,
                $telegram !== '' ? [
                    'id' => 'telegram',
                    'label' => 'تلگرام',
                    'value' => $telegram,
                    'action' => str_starts_with($telegram, 'http')
                        ? $telegram
                        : 'https://t.me/' . ltrim($telegram, '@'),
                ] : null,
                $whatsapp !== '' ? [
                    'id' => 'whatsapp',
                    'label' => 'واتساپ',
                    'value' => $whatsapp,
                    'action' => 'https://wa.me/' . preg_replace('/\D+/', '', $whatsapp),
                ] : null,
            ])),
        ];
    }

    public function list(int $userId, int $limit = 50): array
    {
        $this->assertEnabled();
        $rows = $this->tickets->listForUser($userId, $limit);
        return [
            'tickets' => array_map(fn (array $row) => $this->mapTicket($row, true), $rows),
            'unread_count' => $this->tickets->unreadCountForUser($userId),
        ];
    }

    public function unreadCount(int $userId): array
    {
        $this->assertEnabled();
        return [
            'unread_count' => $this->tickets->unreadCountForUser($userId),
        ];
    }

    public function get(int $userId, string $publicId): array
    {
        $this->assertEnabled();
        $row = $this->tickets->findForUser($userId, $publicId);
        if (!$row) {
            throw new RuntimeException('تیکیت یافت نشد.');
        }
        return ['ticket' => $this->mapTicket($row, true)];
    }

    public function create(int $userId, array $payload): array
    {
        $this->assertEnabled();
        $subject = trim((string) ($payload['subject'] ?? ''));
        $message = trim((string) ($payload['message'] ?? $payload['body'] ?? ''));
        $category = strtolower(trim((string) ($payload['category'] ?? 'ticket')));
        $priority = strtolower(trim((string) ($payload['priority'] ?? 'normal')));

        if (!in_array($category, self::CATEGORIES, true)) {
            throw new RuntimeException('دسته تیکیت نامعتبر است.');
        }
        if (!in_array($priority, self::PRIORITIES, true)) {
            $priority = 'normal';
        }
        if ($category === 'callback_request' && $subject === '') {
            $subject = 'درخواست تماس';
        }
        if (mb_strlen($subject) < 3) {
            throw new RuntimeException('موضوع تیکیت خیلی کوتاه است.');
        }
        if (mb_strlen($message) < 5) {
            throw new RuntimeException('متن پیام خیلی کوتاه است.');
        }
        if (mb_strlen($subject) > 200 || mb_strlen($message) > 5000) {
            throw new RuntimeException('طول موضوع یا پیام بیش از حد مجاز است.');
        }

        $publicId = 'ticket-' . bin2hex(random_bytes(8));
        $ticket = $this->tickets->createTicket([
            'user_id' => $userId,
            'public_id' => $publicId,
            'subject' => $subject,
            'category' => $category,
            'status' => 'open',
            'priority' => $priority,
        ]);

        $this->tickets->addMessage([
            'ticket_id' => (int) $ticket['id'],
            'public_id' => 'msg-' . bin2hex(random_bytes(6)),
            'author' => 'user',
            'body' => $message,
        ]);

        $mapped = $this->mapTicket($this->tickets->findById((int) $ticket['id']) ?? $ticket, true);
        $this->notifyStaffNewTicket($userId, $mapped);

        return ['ticket' => $mapped];
    }

    public function addUserMessage(int $userId, string $publicId, array $payload): array
    {
        $this->assertEnabled();
        $row = $this->tickets->findForUser($userId, $publicId);
        if (!$row) {
            throw new RuntimeException('تیکیت یافت نشد.');
        }
        if ((string) $row['status'] === 'closed') {
            throw new RuntimeException('این تیکیت بسته شده و امکان پاسخ نیست.');
        }

        $body = trim((string) ($payload['message'] ?? $payload['body'] ?? ''));
        if (mb_strlen($body) < 1) {
            throw new RuntimeException('متن پیام خالی است.');
        }
        if (mb_strlen($body) > 5000) {
            throw new RuntimeException('متن پیام بیش از حد طولانی است.');
        }

        $this->tickets->addMessage([
            'ticket_id' => (int) $row['id'],
            'public_id' => 'msg-' . bin2hex(random_bytes(6)),
            'author' => 'user',
            'body' => $body,
        ]);

        if ((string) $row['status'] === 'answered') {
            $this->tickets->updateStatus((int) $row['id'], 'open');
        }
        $this->tickets->markSeen((int) $row['id']);

        return ['ticket' => $this->mapTicket($this->tickets->findById((int) $row['id']) ?? $row, true)];
    }

    public function closeByUser(int $userId, string $publicId): array
    {
        $this->assertEnabled();
        $row = $this->tickets->findForUser($userId, $publicId);
        if (!$row) {
            throw new RuntimeException('تیکیت یافت نشد.');
        }
        if ((string) $row['status'] === 'closed') {
            return ['ticket' => $this->mapTicket($row, true)];
        }
        $updated = $this->tickets->updateStatus((int) $row['id'], 'closed');
        $this->tickets->markSeen((int) $row['id']);
        return ['ticket' => $this->mapTicket($updated, true)];
    }

    public function markSeen(int $userId, string $publicId): array
    {
        $this->assertEnabled();
        $row = $this->tickets->findForUser($userId, $publicId);
        if (!$row) {
            throw new RuntimeException('تیکیت یافت نشد.');
        }
        $updated = $this->tickets->markSeen((int) $row['id']);
        return [
            'ticket' => $this->mapTicket($updated, true),
            'unread_count' => $this->tickets->unreadCountForUser($userId),
        ];
    }

    public function adminList(int $limit = 100, ?string $status = null): array
    {
        $rows = $this->tickets->listAll($limit, $status);
        return [
            'tickets' => array_map(function (array $row) {
                $mapped = $this->mapTicket($row, true);
                $mapped['user'] = [
                    'id' => (int) $row['user_id'],
                    'phone' => (string) ($row['phone'] ?? ''),
                    'firstName' => (string) ($row['first_name'] ?? ''),
                    'lastName' => (string) ($row['last_name'] ?? ''),
                ];
                return $mapped;
            }, $rows),
        ];
    }

    public function adminGet(string $publicId): array
    {
        $row = $this->tickets->findByPublicIdWithUser($publicId);
        if (!$row) {
            throw new RuntimeException('تیکیت یافت نشد.');
        }
        $mapped = $this->mapTicket($row, true);
        $mapped['user'] = [
            'id' => (int) $row['user_id'],
            'phone' => (string) ($row['phone'] ?? ''),
            'firstName' => (string) ($row['first_name'] ?? ''),
            'lastName' => (string) ($row['last_name'] ?? ''),
        ];
        return ['ticket' => $mapped];
    }

    public function adminReply(string $publicId, array $payload): array
    {
        $row = $this->tickets->findByPublicId($publicId);
        if (!$row) {
            throw new RuntimeException('تیکیت یافت نشد.');
        }

        $body = trim((string) ($payload['message'] ?? $payload['body'] ?? ''));
        $status = strtolower(trim((string) ($payload['status'] ?? 'answered')));
        if ($body === '') {
            throw new RuntimeException('متن پاسخ خالی است.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('وضعیت نامعتبر است.');
        }

        $this->tickets->addMessage([
            'ticket_id' => (int) $row['id'],
            'public_id' => 'msg-' . bin2hex(random_bytes(6)),
            'author' => 'support',
            'body' => $body,
        ]);
        $this->tickets->clearSeen((int) $row['id']);
        $updated = $this->tickets->updateStatus((int) $row['id'], $status);

        $this->notifications?->notifyEvent(
            (int) $row['user_id'],
            'support-' . $publicId . '-' . time(),
            'lesson',
            'پاسخ پشتیبانی',
            'به تیکیت «' . $row['subject'] . '» پاسخ داده شد.',
            '/support/ticket/' . $publicId,
            ['ticket_id' => $publicId]
        );

        return ['ticket' => $this->mapTicket($updated, true)];
    }

    public function adminSetStatus(string $publicId, string $status): array
    {
        $row = $this->tickets->findByPublicId($publicId);
        if (!$row) {
            throw new RuntimeException('تیکیت یافت نشد.');
        }
        $status = strtolower(trim($status));
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('وضعیت نامعتبر است.');
        }
        $updated = $this->tickets->updateStatus((int) $row['id'], $status);
        return ['ticket' => $this->mapTicket($updated, true)];
    }

    private function mapTicket(array $row, bool $withMessages): array
    {
        $messages = [];
        if ($withMessages) {
            foreach ($this->tickets->listMessages((int) $row['id']) as $msg) {
                $messages[] = [
                    'id' => (string) $msg['public_id'],
                    'body' => (string) $msg['body'],
                    'author' => (string) $msg['author'],
                    'createdAt' => date('c', strtotime((string) $msg['created_at']) ?: time()),
                ];
            }
        }

        $last = $this->tickets->lastMessage((int) $row['id']);
        $seenAt = $row['user_seen_at'] ?? null;
        $hasUnread = false;
        if ($last && (string) $last['author'] === 'support' && (string) ($row['status'] ?? '') !== 'closed') {
            if ($seenAt === null || $seenAt === '') {
                $hasUnread = true;
            } else {
                $hasUnread = strtotime((string) $last['created_at']) > strtotime((string) $seenAt);
            }
        }

        return [
            'id' => (string) $row['public_id'],
            'subject' => (string) $row['subject'],
            'category' => (string) $row['category'],
            'status' => (string) $row['status'],
            'priority' => (string) ($row['priority'] ?? 'normal'),
            'unread' => $hasUnread,
            'createdAt' => date('c', strtotime((string) $row['created_at']) ?: time()),
            'updatedAt' => date('c', strtotime((string) $row['updated_at']) ?: time()),
            'messages' => $messages,
            'messageCount' => count($messages),
            'lastMessagePreview' => $last ? (string) $last['body'] : null,
            'lastMessageAuthor' => $last ? (string) $last['author'] : null,
        ];
    }

    private function notifyStaffNewTicket(int $userId, array $ticket): void
    {
        $to = (string) (($this->appConfig['support']['notify_email'] ?? '') ?: '');
        if ($to === '' || !function_exists('mail')) {
            $this->logStaff('new_ticket', [
                'user_id' => $userId,
                'ticket_id' => $ticket['id'] ?? null,
                'subject' => $ticket['subject'] ?? null,
            ]);
            return;
        }

        $subject = '[النحم ثاقب] تیکیت جدید: ' . ($ticket['subject'] ?? '');
        $body = "تیکیت جدید ثبت شد.\n"
            . 'شناسه: ' . ($ticket['id'] ?? '') . "\n"
            . 'کاربر: #' . $userId . "\n"
            . 'دسته: ' . ($ticket['category'] ?? '') . "\n\n"
            . ($ticket['lastMessagePreview'] ?? '');
        @mail($to, $subject, $body, 'Content-Type: text/plain; charset=UTF-8');
        $this->logStaff('new_ticket_mailed', ['to' => $to, 'ticket_id' => $ticket['id'] ?? null]);
    }

    private function logStaff(string $event, array $context): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = date('c') . ' ' . $event . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        @file_put_contents($dir . '/support.log', $line, FILE_APPEND);
    }

    private function assertEnabled(): void
    {
        $cfg = $this->appConfig['support'] ?? [];
        if (isset($cfg['enabled']) && empty($cfg['enabled'])) {
            throw new RuntimeException('پشتیبانی فعلاً غیرفعال است.');
        }
        $features = $this->remoteConfig->getPublicConfig()['features'] ?? [];
        if (isset($features['support_enabled']) && empty($features['support_enabled'])) {
            throw new RuntimeException('پشتیبانی فعلاً غیرفعال است.');
        }
    }
}
