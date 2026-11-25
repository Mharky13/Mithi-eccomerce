<?php
declare(strict_types=1);

require_once __DIR__ . '/../Backend/database.php';
require_once __DIR__ . '/AdminAuth.php';
require_once __DIR__ . '/../Seller/inventory_Service.php';

final class AdminOrderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400
    ) {
        parent::__construct($message);
    }
}

final class AdminOrderRepository
{
    public const STATUSES = [
        'Pending',
        'Confirmed',
        'Processing',
        'Shipped',
        'Delivered',
        'Cancelled',
        'Refunded',
    ];

    public function __construct(private PDO $database)
    {
    }

    public function listOrders(array $filters, int $page, int $pageSize): array
    {
        $where = [];
        $parameters = [];
        if ($filters['search'] !== '') {
            $where[] = "(
                CAST(o.order_ID AS CHAR) LIKE :order_id_search
                OR c.customer_FN LIKE :first_name_search
                OR c.customer_LN LIKE :last_name_search
                OR u.user_Email LIKE :email_search
                OR s.seller_StoreName LIKE :store_search
            )";
            $needle = '%' . $filters['search'] . '%';
            $parameters['order_id_search'] = $needle;
            $parameters['first_name_search'] = $needle;
            $parameters['last_name_search'] = $needle;
            $parameters['email_search'] = $needle;
            $parameters['store_search'] = $needle;
        }
        if ($filters['status'] !== null) {
            $where[] = 'o.order_Status = :status';
            $parameters['status'] = $filters['status'];
        }
        if ($filters['date_from'] !== null) {
            $where[] = 'o.order_Date >= :date_from';
            $parameters['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== null) {
            $where[] = 'o.order_Date < :date_to';
            $parameters['date_to'] = (new DateTimeImmutable($filters['date_to']))
                ->modify('+1 day')
                ->format('Y-m-d 00:00:00');
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $joins = "
            FROM ORDERS o
            INNER JOIN CUSTOMERS c ON c.customer_ID = o.customer_ID
            INNER JOIN USERS u ON u.user_ID = c.user_ID
            INNER JOIN SELLERS s ON s.seller_ID = o.seller_ID
        ";

        $count = $this->database->prepare("SELECT COUNT(*) {$joins} {$whereSql}");
        $this->bind($count, $parameters);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $query = $this->database->prepare("
            SELECT o.order_ID AS id,
                   o.order_Date AS order_date,
                   o.order_Status AS status,
                   o.total_Amount AS total_amount,
                   c.customer_ID AS customer_id,
                   TRIM(CONCAT_WS(' ', c.customer_FN, c.customer_LN)) AS customer_name,
                   u.user_Email AS customer_email,
                   s.seller_ID AS seller_id,
                   s.seller_StoreName AS seller_name,
                   (SELECT p.payment_Status
                      FROM PAYMENTS p
                     WHERE p.order_ID = o.order_ID
                     ORDER BY p.payment_Date DESC, p.payment_ID DESC
                     LIMIT 1) AS payment_status
            {$joins}
            {$whereSql}
            ORDER BY o.order_Date DESC, o.order_ID DESC
            LIMIT :row_limit OFFSET :row_offset
        ");
        $this->bind($query, $parameters);
        $query->bindValue(':row_limit', $pageSize, PDO::PARAM_INT);
        $query->bindValue(':row_offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $query->execute();

        return [
            'orders' => $query->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $pageSize)),
            ],
        ];
    }

    public function order(int $orderId, bool $lock = false): ?array
    {
        $query = $this->database->prepare("
            SELECT o.order_ID AS id,
                   o.customer_ID AS customer_id,
                   o.seller_ID AS seller_id,
                   o.parent_Order_ID AS parent_order_id,
                   o.order_Type AS order_type,
                   o.order_Date AS order_date,
                   o.order_Status AS status,
                   o.subtotal_Amount AS subtotal,
                   o.discount_Amount AS discount,
                   o.shipping_Fee AS shipping_fee,
                   o.total_Amount AS total_amount,
                   c.customer_FN AS first_name,
                   c.customer_LN AS last_name,
                   c.customer_Phone AS customer_phone,
                   u.user_Email AS customer_email,
                   s.seller_StoreName AS seller_name
            FROM ORDERS o
            INNER JOIN CUSTOMERS c ON c.customer_ID = o.customer_ID
            INNER JOIN USERS u ON u.user_ID = c.user_ID
            INNER JOIN SELLERS s ON s.seller_ID = o.seller_ID
            WHERE o.order_ID = :order_id
            LIMIT 1
            " . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute(['order_id' => $orderId]);
        $order = $query->fetch(PDO::FETCH_ASSOC);

        return $order === false ? null : $order;
    }

    public function items(int $orderId): array
    {
        $query = $this->database->prepare("
            SELECT oi.product_ID AS product_id,
                   oi.product_Name AS product_name,
                   oi.variant_Information AS variant_information,
                   oi.quantity,
                   oi.unit_Price AS unit_price,
                   oi.discount_Amount AS discount_amount,
                   oi.subtotal
            FROM order_items oi
            WHERE oi.order_ID = :order_id
            ORDER BY oi.order_item_ID
        ");
        $query->execute(['order_id' => $orderId]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function shipping(int $orderId): array
    {
        $query = $this->database->prepare("
            SELECT shipping_ID AS id,
                   recipient_Name AS recipient_name,
                   recipient_phone,
                   shipping_Address AS address,
                   shipping_City AS city,
                   shipping_Province AS province,
                   shipping_PostalCode AS postal_code,
                   shipping_Courier AS courier,
                   shipping_TrackingNo AS tracking_number,
                   shipping_Status AS status,
                   shipping_ShippedAt AS shipped_at,
                   shipping_DeliveredAt AS delivered_at
            FROM SHIPPING
            WHERE order_ID = :order_id
            ORDER BY shipping_CreatedAt DESC, shipping_ID DESC
        ");
        $query->execute(['order_id' => $orderId]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function payments(int $orderId): array
    {
        $query = $this->database->prepare("
            SELECT payment_ID AS id,
                   payment_Method AS method,
                   payment_Provider AS provider,
                   payment_Amount AS amount,
                   payment_Status AS status,
                   transaction_No AS transaction_number,
                   payment_Date AS paid_at
            FROM PAYMENTS
            WHERE order_ID = :order_id
            ORDER BY payment_Date DESC, payment_ID DESC
        ");
        $query->execute(['order_id' => $orderId]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function history(int $orderId): array
    {
        $query = $this->database->prepare("
            SELECT previous_Status AS previous_status,
                   new_Status AS new_status,
                   changed_At AS changed_at,
                   responsible_User_ID AS changed_by,
                   remarks
            FROM ORDER_STATUS_HISTORY
            WHERE order_ID = :order_id
            ORDER BY changed_At, history_ID
        ");
        $query->execute(['order_id' => $orderId]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateStatus(int $orderId, string $status): void
    {
        $query = $this->database->prepare(
            'UPDATE ORDERS SET order_Status = :status WHERE order_ID = :order_id'
        );
        $query->execute(['status' => $status, 'order_id' => $orderId]);
    }

    public function addHistory(
        int $orderId,
        string $previousStatus,
        string $newStatus,
        int $userId,
        ?string $remarks
    ): void {
        $query = $this->database->prepare("
            INSERT INTO ORDER_STATUS_HISTORY
                (order_ID, previous_Status, new_Status, changed_At, responsible_User_ID, remarks)
            VALUES
                (:order_id, :previous_status, :new_status, NOW(), :user_id, :remarks)
        ");
        $query->execute([
            'order_id' => $orderId,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'user_id' => $userId,
            'remarks' => $remarks,
        ]);
    }

    public function updateShippingStatus(int $orderId, string $status): void
    {
        $query = $this->database->prepare("
            UPDATE SHIPPING
            SET shipping_Status = :status,
                shipping_ShippedAt = CASE
                    WHEN :shipped_status = 'Shipped' THEN COALESCE(shipping_ShippedAt, NOW())
                    ELSE shipping_ShippedAt
                END,
                shipping_DeliveredAt = CASE
                    WHEN :delivered_status = 'Delivered' THEN COALESCE(shipping_DeliveredAt, NOW())
                    ELSE shipping_DeliveredAt
                END
            WHERE order_ID = :order_id
        ");
        $query->execute([
            'status' => $status,
            'shipped_status' => $status,
            'delivered_status' => $status,
            'order_id' => $orderId,
        ]);
    }

    public function isFullyRefunded(int $orderId): bool
    {
        $query = $this->database->prepare("
            SELECT EXISTS (
                SELECT 1
                FROM PAYMENTS p
                WHERE p.order_ID = :order_id
                  AND (
                      p.payment_Status <> 'Refunded'
                      OR p.payment_Amount > (
                          SELECT COALESCE(SUM(r.refund_TotalAmount), 0)
                          FROM REFUNDS r
                          WHERE r.payment_ID = p.payment_ID
                            AND r.refund_Status = 'Completed'
                      )
                  )
            ) = 0
              AND EXISTS (
                  SELECT 1 FROM PAYMENTS p WHERE p.order_ID = :order_id_has_payment
              )
        ");
        $query->execute([
            'order_id' => $orderId,
            'order_id_has_payment' => $orderId,
        ]);

        return (int) $query->fetchColumn() === 1;
    }

    public function markPaymentsRefunded(int $orderId): void
    {
        $query = $this->database->prepare("
            UPDATE PAYMENTS p
            SET payment_Status = 'Refunded'
            WHERE p.order_ID = :order_id
              AND p.payment_Status = 'Paid'
              AND p.payment_Amount <= (
                  SELECT COALESCE(SUM(r.refund_TotalAmount), 0)
                  FROM REFUNDS r
                  WHERE r.payment_ID = p.payment_ID
                    AND r.refund_Status = 'Completed'
              )
        ");
        $query->execute(['order_id' => $orderId]);
    }

    public function logAdminAction(
        int $adminId,
        int $orderId,
        string $previousStatus,
        string $newStatus,
        ?string $remarks
    ): void {
        $description = "Order status changed from {$previousStatus} to {$newStatus}.";
        if ($remarks !== null) {
            $description .= ' Remarks: ' . $remarks;
        }
        $query = $this->database->prepare("
            INSERT INTO ADMIN_ACTIVITY_LOGS
                (admin_ID, action_Type, action_Description, target_Table, target_ID, ip_address)
            VALUES
                (:admin_id, 'ORDER_STATUS_UPDATE', :description, 'ORDERS', :order_id, :ip_address)
        ");
        $query->execute([
            'admin_id' => $adminId,
            'description' => $description,
            'order_id' => $orderId,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45),
        ]);
    }

    private function bind(PDOStatement $statement, array $parameters): void
    {
        foreach ($parameters as $key => $value) {
            $statement->bindValue(
                ':' . $key,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
    }
}

final class AdminOrderService
{
    private const PAGE_SIZE_MAX = 100;
    private const ALLOWED_TRANSITIONS = [
        'Pending' => ['Confirmed', 'Cancelled'],
        'Confirmed' => ['Processing', 'Cancelled'],
        'Processing' => ['Shipped', 'Cancelled'],
        'Shipped' => ['Delivered', 'Refunded'],
        'Delivered' => ['Refunded'],
        'Cancelled' => [],
        'Refunded' => [],
    ];

    public function __construct(
        private PDO $database,
        private AdminOrderRepository $repository
    ) {
    }

    public function list(array $query): array
    {
        $search = $query['search'] ?? '';
        if (!is_string($search) || mb_strlen(trim($search)) > 100) {
            throw new AdminOrderException('Invalid search value.', 422);
        }
        $status = $query['status'] ?? null;
        if ($status !== null && $status !== '') {
            if (!is_string($status) || !in_array($status, AdminOrderRepository::STATUSES, true)) {
                throw new AdminOrderException('Invalid order status filter.', 422);
            }
        } else {
            $status = null;
        }
        $dateFrom = $this->date($query['date_from'] ?? null, 'date_from');
        $dateTo = $this->date($query['date_to'] ?? null, 'date_to');
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new AdminOrderException('date_from must not be later than date_to.', 422);
        }

        $page = $this->positiveInteger($query['page'] ?? 1, 'page');
        $pageSize = $this->positiveInteger($query['page_size'] ?? 20, 'page_size');
        if (
            $pageSize > self::PAGE_SIZE_MAX
            || ($page - 1) > intdiv(PHP_INT_MAX - $pageSize, $pageSize)
        ) {
            throw new AdminOrderException('Invalid pagination parameters.', 422);
        }

        return $this->repository->listOrders([
            'search' => trim($search),
            'status' => $status,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ], $page, $pageSize);
    }

    public function details(array $query): array
    {
        $orderId = $this->positiveInteger($query['order_id'] ?? null, 'order_id');
        $order = $this->repository->order($orderId);
        if ($order === null) {
            throw new AdminOrderException('Order not found.', 404);
        }

        $order['items'] = $this->repository->items($orderId);
        $order['shipping'] = $this->repository->shipping($orderId);
        $order['payments'] = $this->repository->payments($orderId);
        $order['status_history'] = $this->repository->history($orderId);

        return ['order' => $order];
    }

    public function status(array $query): array
    {
        $orderId = $this->positiveInteger($query['order_id'] ?? null, 'order_id');
        $order = $this->repository->order($orderId);
        if ($order === null) {
            throw new AdminOrderException('Order not found.', 404);
        }

        return [
            'order_id' => $orderId,
            'status' => $order['status'],
            'order_date' => $order['order_date'],
        ];
    }

    public function history(array $query): array
    {
        $orderId = $this->positiveInteger($query['order_id'] ?? null, 'order_id');
        if ($this->repository->order($orderId) === null) {
            throw new AdminOrderException('Order not found.', 404);
        }

        return [
            'order_id' => $orderId,
            'history' => $this->repository->history($orderId),
        ];
    }

    public function updateStatus(array $input, array $admin): array
    {
        $orderId = $this->positiveInteger($input['order_id'] ?? null, 'order_id');
        $newStatus = $input['new_status'] ?? null;
        if (!is_string($newStatus) || !in_array($newStatus, AdminOrderRepository::STATUSES, true)) {
            throw new AdminOrderException('Invalid order status.', 422);
        }
        $remarks = $input['remarks'] ?? '';
        if (!is_string($remarks) || mb_strlen(trim($remarks)) > 1000) {
            throw new AdminOrderException('Remarks must be 1000 characters or fewer.', 422);
        }
        $remarks = trim($remarks);
        $remarks = $remarks === '' ? null : $remarks;

        return $this->transaction(function () use ($orderId, $newStatus, $remarks, $admin): array {
            $order = $this->repository->order($orderId, true);
            if ($order === null) {
                throw new AdminOrderException('Order not found.', 404);
            }
            $previousStatus = (string) $order['status'];
            if (!in_array($newStatus, self::ALLOWED_TRANSITIONS[$previousStatus] ?? [], true)) {
                throw new AdminOrderException(
                    "Cannot change order status from {$previousStatus} to {$newStatus}.",
                    409
                );
            }
            if ($newStatus === 'Refunded' && !$this->repository->isFullyRefunded($orderId)) {
                throw new AdminOrderException(
                    'The order can only be marked refunded after all of its payments are fully refunded.',
                    409
                );
            }

            if ($newStatus === 'Shipped') {
                (new \App\Inventory\InventoryService($this->database))->deductStock(
                    $orderId,
                    'Order shipped by administrator'
                );
            } elseif ($newStatus === 'Cancelled') {
                (new \App\Inventory\InventoryService($this->database))->releaseReservation(
                    $orderId,
                    'Order cancelled by administrator'
                );
            } elseif ($newStatus === 'Refunded') {
                $this->repository->markPaymentsRefunded($orderId);
            }

            $this->repository->updateStatus($orderId, $newStatus);
            if (in_array($newStatus, ['Shipped', 'Delivered', 'Cancelled'], true)) {
                $this->repository->updateShippingStatus($orderId, $newStatus);
            }
            $this->repository->addHistory(
                $orderId,
                $previousStatus,
                $newStatus,
                (int) $admin['user_ID'],
                $remarks
            );
            $this->repository->logAdminAction(
                (int) $admin['admin_ID'],
                $orderId,
                $previousStatus,
                $newStatus,
                $remarks
            );

            return [
                'order_id' => $orderId,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'message' => 'Order status updated successfully.',
            ];
        });
    }

    private function transaction(callable $action): array
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

    private function positiveInteger(mixed $value, string $key): int
    {
        if (
            (!is_int($value) && !is_string($value))
            || preg_match('/^[1-9]\d*$/', (string) $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT) === false
        ) {
            throw new AdminOrderException('A valid ' . $key . ' is required.', 422);
        }

        return (int) $value;
    }

    private function date(mixed $value, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = is_string($value)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $value)
            : false;
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            !$date
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new AdminOrderException($key . ' must use YYYY-MM-DD format.', 422);
        }

        return $value;
    }
}

final class AdminOrderController
{
    private const MAX_BODY_SIZE = 16384;

    public function __construct(
        private AdminOrderService $service,
        private AdminAuthMiddleware $auth
    ) {
    }

    public function run(): never
    {
        header('Cache-Control: no-store');
        try {
            $result = $this->auth->handle(function (array $admin): array {
                return $this->dispatch($admin);
            });
            $this->respond(200, ['success' => true, 'data' => $result, 'error' => null]);
        } catch (AdminAuthException $error) {
            $this->respond($error->httpStatus, [
                'success' => false,
                'data' => null,
                'error' => ['message' => $error->getMessage()],
            ]);
        } catch (AdminOrderException $error) {
            $this->respond($error->httpStatus, [
                'success' => false,
                'data' => null,
                'error' => ['message' => $error->getMessage()],
            ]);
        } catch (\App\Inventory\InventoryException | InvalidArgumentException $error) {
            $this->respond(409, [
                'success' => false,
                'data' => null,
                'error' => ['message' => $error->getMessage()],
            ]);
        } catch (PDOException $error) {
            error_log('[admin orders] ' . $error->getMessage());
            $this->respond(503, [
                'success' => false,
                'data' => null,
                'error' => ['message' => 'Order service is temporarily unavailable.'],
            ]);
        } catch (Throwable $error) {
            error_log('[admin orders] ' . $error->getMessage());
            $this->respond(500, [
                'success' => false,
                'data' => null,
                'error' => ['message' => 'The order request could not be completed.'],
            ]);
        }
    }

    private function dispatch(array $admin): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $action = $_GET['action'] ?? 'list';
            if (!is_string($action)) {
                throw new AdminOrderException('Invalid order action.', 400);
            }
            if ($action === 'csrf') {
                if (empty($_SESSION['csrf_token'])) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }

                return ['csrf_token' => $_SESSION['csrf_token']];
            }

            return match ($action) {
                'list' => $this->service->list($_GET),
                'details' => $this->service->details($_GET),
                'status' => $this->service->status($_GET),
                'history' => $this->service->history($_GET),
                default => throw new AdminOrderException('Unknown order action.', 404),
            };
        }

        if ($method !== 'POST') {
            header('Allow: GET, POST');
            throw new AdminOrderException('Method not allowed.', 405);
        }
        $input = $this->readInput();
        $this->requireCsrf($input);
        if (($input['action'] ?? '') !== 'update_status') {
            throw new AdminOrderException('Unknown order action.', 404);
        }

        return $this->service->updateStatus($input, $admin);
    }

    private function readInput(): array
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_SIZE) {
            throw new AdminOrderException('Request is too large.', 413);
        }
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType === 'application/json') {
            $input = json_decode(file_get_contents('php://input') ?: '', true);
            if (!is_array($input)) {
                throw new AdminOrderException('Malformed JSON request.', 400);
            }

            return $input;
        }
        if ($contentType === 'application/x-www-form-urlencoded') {
            return $_POST;
        }

        throw new AdminOrderException('Send a JSON request.', 415);
    }

    private function requireCsrf(array $input): void
    {
        $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (
            !is_string($submitted)
            || !is_string($expected)
            || $expected === ''
            || !hash_equals($expected, $submitted)
        ) {
            throw new AdminOrderException('Invalid or missing CSRF token.', 403);
        }
    }

    private function respond(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$database = Database::connect();
$sessions = new SessionManager();
$repository = new AdminOrderRepository($database);
$service = new AdminOrderService($database, $repository);
$auth = new AdminAuthMiddleware(new AdminAuthService($database, $sessions));

(new AdminOrderController($service, $auth))->run();
