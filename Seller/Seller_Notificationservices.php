<?php
declare(strict_types=1);

namespace App\SellerNotifications;

use PDO;
use PDOException;
use RuntimeException;

final class NotificationException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        string $message
    ) {
        parent::__construct($message);
    }
}

final class SellerNotificationService
{
    public const MAX_PAGE_SIZE = 100;
    public const MAX_CONTENT_LENGTH = 5000;

    public function __construct(private PDO $database)
    {
    }

    public function createNotification(
        int $recipientId,
        string $type,
        string $content,
        string $eventReference
    ): array {
        $type = trim($type);
        $content = trim($content);
        $eventReference = trim($eventReference);
        if (
            $recipientId <= 0
            || $type === ''
            || strlen($type) > 64
            || $content === ''
            || mb_strlen($content) > self::MAX_CONTENT_LENGTH
            || $eventReference === ''
            || strlen($eventReference) > 190
        ) {
            throw new NotificationException(400, 'Invalid notification parameters.');
        }

        $notificationId = $this->uuid();
        try {
            $insert = $this->database->prepare("
                INSERT INTO SELLER_NOTIFICATIONS
                    (notification_ID, recipient_ID, type, content, event_reference, is_read)
                VALUES
                    (:notification_id, :recipient_id, :type, :content, :event_reference, 0)
            ");
            $insert->execute([
                'notification_id' => $notificationId,
                'recipient_id' => $recipientId,
                'type' => $type,
                'content' => $content,
                'event_reference' => $eventReference,
            ]);
        } catch (PDOException $error) {
            if ((string) ($error->errorInfo[1] ?? '') === '1062') {
                return ['status' => 200, 'message' => 'Duplicate notification prevented'];
            }
            throw $error;
        }

        return [
            'status' => 201,
            'message' => 'Notification created successfully',
            'notificationId' => $notificationId,
        ];
    }

    public function getSellerNotifications(
        array $seller,
        array $filterCriteria = [],
        array $paginationParams = []
    ): array {
        $recipientId = $this->recipientId($seller);
        $pageSize = $this->positiveInteger($paginationParams['pageSize'] ?? 10);
        $pageNumber = $this->positiveInteger($paginationParams['pageNumber'] ?? 1);
        if (
            $pageSize === null
            || $pageNumber === null
            || $pageSize < 1
            || $pageSize > self::MAX_PAGE_SIZE
            || $pageNumber < 1
            || ($pageNumber - 1) > intdiv(PHP_INT_MAX - $pageSize, $pageSize)
        ) {
            throw new NotificationException(422, 'Invalid pagination parameters.');
        }

        $where = ['recipient_ID = :recipient_id'];
        $parameters = ['recipient_id' => $recipientId];
        if (array_key_exists('type', $filterCriteria) && $filterCriteria['type'] !== null) {
            if (!is_string($filterCriteria['type']) || trim($filterCriteria['type']) === '') {
                throw new NotificationException(422, 'Invalid notification type filter.');
            }
            $where[] = 'type = :type';
            $parameters['type'] = trim($filterCriteria['type']);
        }
        if (array_key_exists('readStatus', $filterCriteria) && $filterCriteria['readStatus'] !== null) {
            if (!is_bool($filterCriteria['readStatus'])) {
                throw new NotificationException(422, 'Read status must be boolean.');
            }
            $where[] = 'is_read = :is_read';
            $parameters['is_read'] = $filterCriteria['readStatus'] ? 1 : 0;
        }

        $query = $this->database->prepare("
            SELECT notification_ID AS id,
                   type,
                   content,
                   event_reference,
                   is_read,
                   created_at,
                   updated_at
            FROM SELLER_NOTIFICATIONS
            WHERE " . implode(' AND ', $where) . "
            ORDER BY created_at DESC, notification_ID DESC
            LIMIT :page_size OFFSET :offset
        ");
        foreach ($parameters as $key => $value) {
            $query->bindValue(
                ':' . $key,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $query->bindValue(':page_size', $pageSize + 1, PDO::PARAM_INT);
        $query->bindValue(':offset', ($pageNumber - 1) * $pageSize, PDO::PARAM_INT);
        $query->execute();

        $notifications = $query->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($notifications) > $pageSize;
        if ($hasMore) {
            array_pop($notifications);
        }
        foreach ($notifications as &$notification) {
            $notification['is_read'] = (bool) $notification['is_read'];
        }
        unset($notification);

        return [
            'status' => 200,
            'data' => $notifications,
            'pagination' => [
                'pageNumber' => $pageNumber,
                'pageSize' => $pageSize,
                'hasMore' => $hasMore,
            ],
        ];
    }

    public function getUnreadNotificationCount(array $seller): array
    {
        $recipientId = $this->recipientId($seller);
        $query = $this->database->prepare("
            SELECT COUNT(*)
            FROM SELLER_NOTIFICATIONS
            WHERE recipient_ID = :recipient_id AND is_read = 0
        ");
        $query->execute(['recipient_id' => $recipientId]);

        return [
            'status' => 200,
            'data' => ['unreadCount' => (int) $query->fetchColumn()],
        ];
    }

    public function markNotificationAsRead(array $seller, string $notificationId): array
    {
        $recipientId = $this->recipientId($seller);
        if (!$this->validUuid($notificationId)) {
            throw new NotificationException(422, 'Invalid notification ID.');
        }

        $update = $this->database->prepare("
            UPDATE SELLER_NOTIFICATIONS
            SET is_read = 1, updated_at = NOW()
            WHERE notification_ID = :notification_id
              AND recipient_ID = :recipient_id
              AND is_read = 0
        ");
        $update->execute([
            'notification_id' => $notificationId,
            'recipient_id' => $recipientId,
        ]);
        if ($update->rowCount() > 0) {
            return ['status' => 200, 'message' => 'Notification marked as read'];
        }

        $check = $this->database->prepare("
            SELECT 1 FROM SELLER_NOTIFICATIONS
            WHERE notification_ID = :notification_id AND recipient_ID = :recipient_id
            LIMIT 1
        ");
        $check->execute([
            'notification_id' => $notificationId,
            'recipient_id' => $recipientId,
        ]);
        if ($check->fetchColumn() === false) {
            throw new NotificationException(404, 'Notification not found.');
        }

        return ['status' => 200, 'message' => 'Notification marked as read'];
    }

    public function markAllNotificationsAsRead(array $seller): array
    {
        $recipientId = $this->recipientId($seller);
        $update = $this->database->prepare("
            UPDATE SELLER_NOTIFICATIONS
            SET is_read = 1, updated_at = NOW()
            WHERE recipient_ID = :recipient_id AND is_read = 0
        ");
        $update->execute(['recipient_id' => $recipientId]);

        return [
            'status' => 200,
            'message' => 'All notifications marked as read',
            'updatedCount' => $update->rowCount(),
        ];
    }

    private function recipientId(array $seller): int
    {
        $recipientId = $this->positiveInteger($seller['id'] ?? null);
        if ($recipientId === null) {
            throw new NotificationException(401, 'Unauthorized seller access.');
        }

        return $recipientId;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false || $integer <= 0 ? null : $integer;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function validUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
