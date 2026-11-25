<?php
declare(strict_types=1);

require_once __DIR__ . '/../Backend/database.php';
require_once __DIR__ . '/../Backend/Auth.php';
require_once __DIR__ . '/Seller_Notificationservices.php';

use App\SellerNotifications\NotificationException;
use App\SellerNotifications\SellerNotificationService;

function sellerNotificationsRespond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sellerNotificationsInput(): array
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 16384) {
        throw new NotificationException(413, 'Request is too large.');
    }
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType === 'application/json') {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($input)) {
            throw new NotificationException(400, 'Malformed JSON request.');
        }

        return $input;
    }

    if ($contentType === 'application/x-www-form-urlencoded') {
        return $_POST;
    }

    throw new NotificationException(415, 'Send a JSON request.');
}

function sellerNotificationsRequireCsrf(array $input): void
{
    $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (
        !is_string($submitted)
        || !is_string($expected)
        || $expected === ''
        || !hash_equals($expected, $submitted)
    ) {
        throw new NotificationException(403, 'Invalid or missing CSRF token.');
    }
}

function handleSellerNotifications(): never
{
    try {
        $sessions = new SessionManager();
        $sessions->start();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'GET' && $method !== 'POST') {
            header('Allow: GET, POST');
            throw new NotificationException(405, 'Method not allowed.');
        }
        $input = $method === 'POST' ? sellerNotificationsInput() : $_GET;
        $action = $input['action'] ?? ($method === 'GET' ? 'list' : '');
        if (!is_string($action)) {
            throw new NotificationException(400, 'Invalid notification action.');
        }

        if ($method === 'GET' && $action === 'csrf') {
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            sellerNotificationsRespond(200, [
                'success' => true,
                'data' => ['csrf_token' => $_SESSION['csrf_token']],
                'error' => null,
            ]);
        }

        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId <= 0) {
            throw new NotificationException(401, 'Please log in first.');
        }

        $database = Database::connect();
        $sellerQuery = $database->prepare("
            SELECT s.seller_ID
            FROM USERS u
            INNER JOIN SELLERS s ON s.user_ID = u.user_ID
            WHERE u.user_ID = :user_id
              AND LOWER(TRIM(u.user_Role)) = 'seller'
              AND LOWER(TRIM(u.user_Status)) = 'active'
            LIMIT 1
        ");
        $sellerQuery->execute(['user_id' => $userId]);
        $sellerId = $sellerQuery->fetchColumn();
        if ($sellerId === false) {
            throw new NotificationException(403, 'An active seller account is required.');
        }

        $seller = ['id' => (int) $userId, 'seller_id' => (int) $sellerId];
        $service = new SellerNotificationService($database);
        if ($method === 'GET' && $action === 'list') {
            $type = $input['type'] ?? null;
            if ($type !== null && !is_string($type)) {
                throw new NotificationException(422, 'Invalid notification type filter.');
            }
            $readStatus = null;
            if (array_key_exists('readStatus', $input)) {
                $value = $input['readStatus'];
                if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
                    $readStatus = true;
                } elseif ($value === false || $value === 0 || $value === '0' || $value === 'false') {
                    $readStatus = false;
                } else {
                    throw new NotificationException(422, 'readStatus must be true or false.');
                }
            }
            $result = $service->getSellerNotifications(
                $seller,
                ['type' => $type, 'readStatus' => $readStatus],
                [
                    'pageSize' => $input['pageSize'] ?? 10,
                    'pageNumber' => $input['pageNumber'] ?? 1,
                ]
            );
            sellerNotificationsRespond(200, [
                'success' => true,
                'data' => $result['data'],
                'pagination' => $result['pagination'],
                'error' => null,
            ]);
        }

        if ($method === 'GET' && $action === 'unread-count') {
            $result = $service->getUnreadNotificationCount($seller);
            sellerNotificationsRespond(200, [
                'success' => true,
                'data' => $result['data'],
                'error' => null,
            ]);
        }

        if ($method === 'POST' && in_array($action, ['mark-read', 'mark-all-read'], true)) {
            sellerNotificationsRequireCsrf($input);
            if ($action === 'mark-all-read') {
                $result = $service->markAllNotificationsAsRead($seller);
            } else {
                $notificationId = $input['notificationId'] ?? null;
                if (!is_string($notificationId)) {
                    throw new NotificationException(422, 'A notificationId is required.');
                }
                $result = $service->markNotificationAsRead($seller, $notificationId);
            }
            sellerNotificationsRespond(200, [
                'success' => true,
                'data' => $result,
                'error' => null,
            ]);
        }

        throw new NotificationException(404, 'Unknown notification action.');
    } catch (NotificationException $error) {
        sellerNotificationsRespond($error->httpStatus, [
            'success' => false,
            'data' => null,
            'error' => ['message' => $error->getMessage()],
        ]);
    } catch (PDOException $error) {
        error_log('Seller notifications database error: ' . $error->getMessage());
        sellerNotificationsRespond(503, [
            'success' => false,
            'data' => null,
            'error' => ['message' => 'Notifications are temporarily unavailable.'],
        ]);
    } catch (Throwable $error) {
        error_log('Seller notifications API error: ' . $error->getMessage());
        sellerNotificationsRespond(500, [
            'success' => false,
            'data' => null,
            'error' => ['message' => 'Something went wrong. Please try again.'],
        ]);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    handleSellerNotifications();
}
