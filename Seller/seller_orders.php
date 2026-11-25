<?php
declare(strict_types=1);

namespace App\SellerOrders;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../Backend/database.php';
require_once __DIR__ . '/../Backend/Auth.php';
require_once __DIR__ . '/inventory_Service.php';

final class SellerOrderException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        string $message
    ) {
        parent::__construct($message);
    }
}

final class SellerOrderManager
{
    private const PAGE_SIZE_MAX = 50;
    private const STATUSES = [
        'PENDING',
        'CONFIRMED',
        'PROCESSING',
        'SHIPPED',
        'DELIVERED',
        'CANCELLED',
        'REFUNDED',
    ];
    private const ALLOWED_TRANSITIONS = [
        'PENDING' => ['CONFIRMED', 'CANCELLED'],
        'CONFIRMED' => ['PROCESSING', 'CANCELLED'],
        'PROCESSING' => ['SHIPPED', 'CANCELLED'],
        'SHIPPED' => ['DELIVERED', 'REFUNDED'],
        'DELIVERED' => ['REFUNDED'],
        'CANCELLED' => [],
        'REFUNDED' => [],
    ];

    public function __construct(private PDO $database)
    {
    }

    public function getOrders(
        int $sellerId,
        ?string $status = null,
        int $page = 1,
        int $limit = 10,
        string $sort = 'DESC'
    ): array {
        if ($page < 1 || $limit < 1 || $limit > self::PAGE_SIZE_MAX) {
            throw new SellerOrderException(422, 'Page must be positive and limit must be from 1 to 50.');
        }
        if ($status !== null) {
            $status = strtoupper(trim($status));
            if (!in_array($status, self::STATUSES, true)) {
                throw new SellerOrderException(422, 'Invalid order status filter.');
            }
        }
        $sort = strtoupper($sort) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "
            SELECT o.order_ID AS id,
                   o.order_Status AS status,
                   o.total_Amount AS total_amount,
                   o.order_Date AS created_at
            FROM ORDERS o
            WHERE o.seller_ID = :seller_id";
        $parameters = ['seller_id' => $sellerId];
        if ($status !== null) {
            $sql .= ' AND UPPER(o.order_Status) = :status';
            $parameters['status'] = $status;
        }
        $sql .= " ORDER BY o.order_Date {$sort}, o.order_ID {$sort} LIMIT :limit OFFSET :offset";

        $query = $this->database->prepare($sql);
        $query->bindValue(':seller_id', $sellerId, PDO::PARAM_INT);
        if ($status !== null) {
            $query->bindValue(':status', $status, PDO::PARAM_STR);
        }
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $query->execute();

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getOrderDetails(int $sellerId, int $orderId): array
    {
        $query = $this->database->prepare("
            SELECT o.order_ID AS id,
                   o.seller_ID AS seller_id,
                   o.order_Status AS status,
                   o.total_Amount AS total_amount,
                   o.order_Date AS created_at,
                   s.shipping_Address AS shipping_address,
                   s.shipping_City AS shipping_city,
                   s.shipping_Province AS shipping_province,
                   s.shipping_PostalCode AS shipping_postal_code,
                   s.shipping_Status AS shipping_status
            FROM ORDERS o
            LEFT JOIN SHIPPING s ON s.order_ID = o.order_ID
            WHERE o.order_ID = :order_id AND o.seller_ID = :seller_id
            LIMIT 1
        ");
        $query->execute(['order_id' => $orderId, 'seller_id' => $sellerId]);
        $order = $query->fetch(PDO::FETCH_ASSOC);
        if ($order === false) {
            throw new SellerOrderException(404, 'Order not found.');
        }

        $items = $this->database->prepare("
            SELECT oi.product_ID AS product_id,
                   p.prod_name AS product_name,
                   oi.quantity,
                   oi.unit_Price AS price,
                   oi.subtotal
            FROM ORDER_ITEMS oi
            LEFT JOIN PRODUCTS p ON p.prod_ID = oi.product_ID
            WHERE oi.order_ID = :order_id
            ORDER BY oi.product_ID
        ");
        $items->execute(['order_id' => $orderId]);
        $order['items'] = $items->fetchAll(PDO::FETCH_ASSOC);

        $history = $this->database->prepare("
            SELECT previous_Status AS previous_status,
                   new_Status AS new_status,
                   changed_At AS changed_at
            FROM ORDER_STATUS_HISTORY
            WHERE order_ID = :order_id
            ORDER BY changed_At, history_ID
        ");
        $history->execute(['order_id' => $orderId]);
        $order['status_history'] = $history->fetchAll(PDO::FETCH_ASSOC);

        return $order;
    }

    public function updateStatus(
        int $sellerId,
        int $userId,
        int $orderId,
        string $newStatus,
        string $idempotencyKey
    ): array {
        if ($orderId <= 0) {
            throw new SellerOrderException(422, 'A valid order ID is required.');
        }
        $newStatus = strtoupper(trim($newStatus));
        if (!in_array($newStatus, self::STATUSES, true)) {
            throw new SellerOrderException(422, 'Invalid order status.');
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $idempotencyKey)) {
            throw new SellerOrderException(422, 'A valid idempotency key of 8 to 128 characters is required.');
        }
        $keyHash = hash('sha256', $idempotencyKey);

        try {
            return $this->transaction(function () use (
                $sellerId,
                $userId,
                $orderId,
                $newStatus,
                $keyHash
            ): array {
                $removeExpired = $this->database->prepare(
                    'DELETE FROM SELLER_ORDER_IDEMPOTENCY WHERE expires_At <= NOW() AND key_Hash = :key_hash'
                );
                $removeExpired->execute(['key_hash' => $keyHash]);

                $cached = $this->database->prepare("
                    SELECT response_Payload
                    FROM SELLER_ORDER_IDEMPOTENCY
                    WHERE key_Hash = :key_hash AND seller_ID = :seller_id
                    LIMIT 1
                    FOR UPDATE
                ");
                $cached->execute(['key_hash' => $keyHash, 'seller_id' => $sellerId]);
                $cachedPayload = $cached->fetchColumn();
                if ($cachedPayload !== false) {
                    $decoded = json_decode((string) $cachedPayload, true);
                    if (is_array($decoded)) {
                        $this->assertIdempotencyRequest($decoded, $orderId, $newStatus);
                        return $decoded;
                    }
                    throw new RuntimeException('Stored idempotency response is invalid.');
                }

                $query = $this->database->prepare("
                    SELECT order_ID, seller_ID, order_Status
                    FROM ORDERS
                    WHERE order_ID = :order_id
                    FOR UPDATE
                ");
                $query->execute(['order_id' => $orderId]);
                $order = $query->fetch(PDO::FETCH_ASSOC);
                if ($order === false) {
                    throw new SellerOrderException(404, 'Order not found.');
                }
                if ((int) $order['seller_ID'] !== $sellerId) {
                    throw new SellerOrderException(403, 'Access denied.');
                }

                $currentStatus = strtoupper((string) $order['order_Status']);
                if (!in_array($newStatus, self::ALLOWED_TRANSITIONS[$currentStatus] ?? [], true)) {
                    throw new SellerOrderException(
                        409,
                        "Cannot change order status from {$currentStatus} to {$newStatus}."
                    );
                }

                if ($newStatus === 'SHIPPED') {
                    (new \App\Inventory\InventoryService($this->database))->deductStock(
                        $orderId,
                        'Order shipped'
                    );
                } elseif ($newStatus === 'CANCELLED') {
                    (new \App\Inventory\InventoryService($this->database))->releaseReservation(
                        $orderId,
                        'Order cancelled by seller'
                    );
                }

                $displayStatus = ucfirst(strtolower($newStatus));
                $update = $this->database->prepare(
                    'UPDATE ORDERS SET order_Status = :status WHERE order_ID = :order_id'
                );
                $update->execute(['status' => $displayStatus, 'order_id' => $orderId]);

                $history = $this->database->prepare("
                    INSERT INTO ORDER_STATUS_HISTORY
                        (order_ID, previous_Status, new_Status, changed_At, responsible_User_ID)
                    VALUES
                        (:order_id, :previous_status, :new_status, NOW(), :user_id)
                ");
                $history->execute([
                    'order_id' => $orderId,
                    'previous_status' => (string) $order['order_Status'],
                    'new_status' => $displayStatus,
                    'user_id' => $userId,
                ]);

                if (in_array($newStatus, ['SHIPPED', 'DELIVERED', 'CANCELLED'], true)) {
                    $shipping = $this->database->prepare(
                        'UPDATE SHIPPING SET shipping_Status = :status WHERE order_ID = :order_id'
                    );
                    $shipping->execute(['status' => $displayStatus, 'order_id' => $orderId]);
                }

                $response = [
                    'message' => "Order updated to {$displayStatus}.",
                    'order_id' => $orderId,
                    'status' => $displayStatus,
                ];
                $store = $this->database->prepare("
                    INSERT INTO SELLER_ORDER_IDEMPOTENCY
                        (key_Hash, seller_ID, order_ID, response_Payload, expires_At)
                    VALUES
                        (:key_hash, :seller_id, :order_id, :payload, DATE_ADD(NOW(), INTERVAL 1 DAY))
                ");
                $store->execute([
                    'key_hash' => $keyHash,
                    'seller_id' => $sellerId,
                    'order_id' => $orderId,
                    'payload' => json_encode($response, JSON_THROW_ON_ERROR),
                ]);

                return $response;
            });
        } catch (PDOException $error) {
            if ($this->isDuplicateKey($error)) {
                $cached = $this->cachedResponse($sellerId, $keyHash);
                if ($cached !== null) {
                    $this->assertIdempotencyRequest($cached, $orderId, $newStatus);
                    return $cached;
                }
                throw new SellerOrderException(409, 'Idempotency key has already been used.');
            }
            throw $error;
        }
    }

    private function cachedResponse(int $sellerId, string $keyHash): ?array
    {
        $query = $this->database->prepare("
            SELECT response_Payload
            FROM SELLER_ORDER_IDEMPOTENCY
            WHERE key_Hash = :key_hash AND seller_ID = :seller_id AND expires_At > NOW()
            LIMIT 1
        ");
        $query->execute(['key_hash' => $keyHash, 'seller_id' => $sellerId]);
        $payload = $query->fetchColumn();
        if ($payload === false) {
            return null;
        }
        $response = json_decode((string) $payload, true);

        return is_array($response) ? $response : null;
    }

    private function assertIdempotencyRequest(array $response, int $orderId, string $newStatus): void
    {
        if (
            (int) ($response['order_id'] ?? 0) !== $orderId
            || strtoupper((string) ($response['status'] ?? '')) !== $newStatus
        ) {
            throw new SellerOrderException(409, 'Idempotency key has already been used for a different request.');
        }
    }

    private function transaction(callable $action): mixed
    {
        $this->database->beginTransaction();
        try {
            $result = $action();
            $this->database->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function isDuplicateKey(PDOException $error): bool
    {
        return (string) ($error->errorInfo[1] ?? '') === '1062';
    }
}

function sellerOrderRespond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function handleSellerOrders(): never
{
    $sessions = new \SessionManager();
    try {
        $sessions->start();
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId <= 0) {
            sellerOrderRespond(401, ['error' => 'Authentication required.']);
        }

        $database = \Database::connect();
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
            sellerOrderRespond(403, ['error' => 'An active seller account is required.']);
        }

        $manager = new SellerOrderManager($database);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET' && ($_GET['action'] ?? '') === 'csrf') {
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            sellerOrderRespond(200, ['csrf_token' => $_SESSION['csrf_token']]);
        }

        if ($method === 'GET' && isset($_GET['order_id'])) {
            $orderId = filter_var($_GET['order_id'], FILTER_VALIDATE_INT);
            if ($orderId === false || $orderId <= 0) {
                sellerOrderRespond(422, ['error' => 'A valid order ID is required.']);
            }
            sellerOrderRespond(200, [
                'order' => $manager->getOrderDetails((int) $sellerId, $orderId),
            ]);
        }
        if ($method === 'GET') {
            $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
            $limit = filter_var($_GET['limit'] ?? 10, FILTER_VALIDATE_INT);
            if ($page === false || $limit === false) {
                sellerOrderRespond(422, ['error' => 'Page and limit must be integers.']);
            }
            $status = $_GET['status'] ?? null;
            $sort = $_GET['sort'] ?? 'DESC';
            if (($status !== null && !is_string($status)) || !is_string($sort)) {
                sellerOrderRespond(422, ['error' => 'Invalid order filters.']);
            }
            sellerOrderRespond(200, [
                'orders' => $manager->getOrders(
                    (int) $sellerId,
                    $status,
                    $page,
                    $limit,
                    $sort
                ),
            ]);
        }
        if ($method === 'POST') {
            $input = \ApiRequest::readJson();
            $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            $savedToken = $_SESSION['csrf_token'] ?? '';
            if (
                !is_string($submittedToken)
                || !is_string($savedToken)
                || $savedToken === ''
                || !hash_equals($savedToken, $submittedToken)
            ) {
                sellerOrderRespond(403, ['error' => 'Invalid or missing CSRF token.']);
            }
            $orderId = filter_var($input['order_id'] ?? null, FILTER_VALIDATE_INT);
            $status = $input['status'] ?? null;
            $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY']
                ?? $input['idempotency_key']
                ?? '';
            if (
                $orderId === false
                || $orderId <= 0
                || !is_string($status)
                || !is_string($idempotencyKey)
            ) {
                sellerOrderRespond(422, ['error' => 'Order ID, status, and idempotency key are required.']);
            }
            sellerOrderRespond(200, $manager->updateStatus(
                (int) $sellerId,
                (int) $userId,
                $orderId,
                $status,
                $idempotencyKey
            ));
        }

        header('Allow: GET, POST');
        sellerOrderRespond(405, ['error' => 'Method not allowed.']);
    } catch (SellerOrderException $error) {
        sellerOrderRespond($error->httpStatus, ['error' => $error->getMessage()]);
    } catch (\ApiException $error) {
        sellerOrderRespond($error->status, ['error' => $error->getMessage()]);
    } catch (\App\Inventory\InventoryException $error) {
        sellerOrderRespond(409, ['error' => $error->getMessage()]);
    } catch (PDOException $error) {
        error_log('Seller orders database error: ' . $error->getMessage());
        sellerOrderRespond(503, ['error' => 'Order service is temporarily unavailable.']);
    } catch (Throwable $error) {
        error_log('Seller orders API error: ' . $error->getMessage());
        sellerOrderRespond(500, ['error' => 'Order request could not be completed.']);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    handleSellerOrders();
}
