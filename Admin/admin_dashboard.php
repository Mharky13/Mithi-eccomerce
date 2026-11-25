<?php
declare(strict_types=1);

require_once __DIR__ . '/../Backend/database.php';
require_once __DIR__ . '/AdminAuth.php';

final class AdminDashboardException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

final class AdminDashboardRepository
{
    private const COUNTED_ORDER_STATUSES = ['Processing', 'Shipped', 'Delivered'];

    public function __construct(private PDO $database)
    {
    }

    public function platformCounts(): array
    {
        $query = $this->database->query("
            SELECT
                (SELECT COUNT(*)
                   FROM USERS
                  WHERE LOWER(TRIM(user_Role)) = 'customer'
                    AND LOWER(TRIM(user_Status)) = 'active') AS total_users,
                (SELECT COUNT(*)
                   FROM SELLERS s
                   INNER JOIN USERS u ON u.user_ID = s.user_ID
                  WHERE LOWER(TRIM(u.user_Role)) = 'seller'
                    AND LOWER(TRIM(u.user_Status)) = 'active') AS total_sellers,
                (SELECT COUNT(*)
                   FROM PRODUCTS p
                   LEFT JOIN SELLER_PRODUCT_SETTINGS ps ON ps.prod_ID = p.prod_ID
                  WHERE COALESCE(p.is_deleted, 0) = 0
                    AND COALESCE(ps.listing_Status, 'Active') = 'Active') AS total_products,
                (SELECT COUNT(*) FROM ORDERS) AS total_orders,
                (SELECT COUNT(*)
                   FROM SELLER_APPLICATIONS
                  WHERE application_Status = 'Pending') AS pending_applications
        ");
        $counts = $query->fetch(PDO::FETCH_ASSOC);
        if ($counts === false) {
            throw new RuntimeException('Could not load dashboard counts.');
        }

        return array_map('intval', $counts);
    }

    public function salesTotals(string $from, string $toExclusive): array
    {
        $statusPlaceholders = implode(',', array_fill(0, count(self::COUNTED_ORDER_STATUSES), '?'));
        $sales = $this->database->prepare("
            SELECT COUNT(*) AS counted_orders,
                   COALESCE(SUM(o.total_Amount), 0) AS total_sales
            FROM ORDERS o
            WHERE o.order_Date >= ?
              AND o.order_Date < ?
              AND o.order_Status IN ({$statusPlaceholders})
              AND EXISTS (
                  SELECT 1
                  FROM PAYMENTS p
                  WHERE p.order_ID = o.order_ID
                    AND p.payment_Status = 'Paid'
              )
        ");
        $sales->execute([$from, $toExclusive, ...self::COUNTED_ORDER_STATUSES]);
        $totals = $sales->fetch(PDO::FETCH_ASSOC);

        $allOrders = $this->database->prepare("
            SELECT COUNT(*)
            FROM ORDERS
            WHERE order_Date >= ? AND order_Date < ?
        ");
        $allOrders->execute([$from, $toExclusive]);

        return [
            'counted_orders' => (int) $totals['counted_orders'],
            'total_sales' => (float) $totals['total_sales'],
            'orders_in_range' => (int) $allOrders->fetchColumn(),
        ];
    }

    public function recentOrders(int $limit): array
    {
        return $this->fetchLimited("
            SELECT o.order_ID AS id,
                   o.order_Date AS date,
                   TRIM(CONCAT_WS(' ', c.customer_FN, c.customer_LN)) AS customer,
                   s.seller_StoreName AS seller,
                   o.total_Amount AS total,
                   o.order_Status AS status
            FROM ORDERS o
            INNER JOIN CUSTOMERS c ON c.customer_ID = o.customer_ID
            INNER JOIN SELLERS s ON s.seller_ID = o.seller_ID
            ORDER BY o.order_Date DESC, o.order_ID DESC
            LIMIT :limit
        ", $limit);
    }

    public function recentApplications(int $limit): array
    {
        return $this->fetchLimited("
            SELECT a.application_ID AS id,
                   TRIM(CONCAT_WS(' ', c.customer_FN, c.customer_LN)) AS applicant,
                   a.store_Name AS store_name,
                   a.application_Status AS status,
                   a.application_Date AS submitted_at
            FROM SELLER_APPLICATIONS a
            LEFT JOIN CUSTOMERS c ON c.user_ID = a.user_ID
            ORDER BY a.application_Date DESC, a.application_ID DESC
            LIMIT :limit
        ", $limit);
    }

    public function recentComplaints(int $limit): array
    {
        return $this->fetchLimited("
            SELECT complaint_ID AS id,
                   complaint_Subject AS subject,
                   created_at AS submitted_at,
                   complaint_Status AS status
            FROM COMPLAINTS
            ORDER BY created_at DESC, complaint_ID DESC
            LIMIT :limit
        ", $limit);
    }

    private function fetchLimited(string $sql, int $limit): array
    {
        $query = $this->database->prepare($sql);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->execute();

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}

final class AdminDashboardService
{
    private const DEFAULT_RANGE_DAYS = 30;
    private const DEFAULT_RECENT_LIMIT = 5;
    private const MAX_RECENT_LIMIT = 50;

    public function __construct(private AdminDashboardRepository $repository)
    {
    }

    public function summary(): array
    {
        return $this->repository->platformCounts();
    }

    public function sales(array $query): array
    {
        [$from, $to] = $this->dateRange($query);
        $toExclusive = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00');
        $totals = $this->repository->salesTotals($from . ' 00:00:00', $toExclusive);
        $countedOrders = $totals['counted_orders'];

        return [
            'date_from' => $from,
            'date_to' => $to,
            'total_sales' => round($totals['total_sales'], 2),
            'counted_orders' => $countedOrders,
            'average_order_value' => $countedOrders > 0
                ? round($totals['total_sales'] / $countedOrders, 2)
                : 0.0,
            'excluded_orders' => $totals['orders_in_range'] - $countedOrders,
            'counted_order_statuses' => ['Processing', 'Shipped', 'Delivered'],
            'counted_payment_status' => 'Paid',
        ];
    }

    public function recentOrders(array $query): array
    {
        return $this->repository->recentOrders($this->limit($query));
    }

    public function recentApplications(array $query): array
    {
        return $this->repository->recentApplications($this->limit($query));
    }

    public function recentComplaints(array $query): array
    {
        return $this->repository->recentComplaints($this->limit($query));
    }

    public function full(array $query): array
    {
        $this->dateRange($query);
        $limit = $this->limit($query);

        return [
            'summary' => $this->summary(),
            'sales' => $this->sales($query),
            'recent_orders' => $this->repository->recentOrders($limit),
            'recent_applications' => $this->repository->recentApplications($limit),
            'recent_complaints' => $this->repository->recentComplaints($limit),
            'generated_at' => date('c'),
        ];
    }

    private function dateRange(array $query): array
    {
        $to = $query['date_to'] ?? date('Y-m-d');
        $from = $query['date_from'] ?? date(
            'Y-m-d',
            strtotime('-' . self::DEFAULT_RANGE_DAYS . ' days')
        );
        $errors = [];

        foreach (['date_from' => $from, 'date_to' => $to] as $key => $value) {
            $date = is_string($value)
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $value)
                : false;
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (
                !$date
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                || $date->format('Y-m-d') !== $value
            ) {
                $errors[$key] = 'Must use format YYYY-MM-DD.';
            }
        }

        if ($errors === [] && $from > $to) {
            $errors['date_from'] = 'Must not be later than date_to.';
        }
        if ($errors !== []) {
            throw new AdminDashboardException('Validation failed.', 422, $errors);
        }

        return [$from, $to];
    }

    private function limit(array $query): int
    {
        $limit = $query['limit'] ?? self::DEFAULT_RECENT_LIMIT;
        if (
            (!is_int($limit) && !is_string($limit))
            || !ctype_digit((string) $limit)
            || (int) $limit < 1
            || (int) $limit > self::MAX_RECENT_LIMIT
        ) {
            throw new AdminDashboardException('Validation failed.', 422, [
                'limit' => 'Must be between 1 and ' . self::MAX_RECENT_LIMIT . '.',
            ]);
        }

        return (int) $limit;
    }
}

final class AdminDashboardController
{
    public function __construct(
        private AdminDashboardService $service,
        private AdminAuthMiddleware $auth
    ) {
    }

    public function run(): never
    {
        header('Cache-Control: no-store');
        try {
            $data = $this->auth->handle(fn(): array => $this->dispatch());
            $this->respond(200, ['data' => $data]);
        } catch (AdminAuthException $error) {
            $this->respond($error->httpStatus, ['error' => ['message' => $error->getMessage()]]);
        } catch (AdminDashboardException $error) {
            $this->respond($error->httpStatus, [
                'error' => [
                    'message' => $error->getMessage(),
                    ...($error->details === [] ? [] : ['details' => $error->details]),
                ],
            ]);
        } catch (PDOException $error) {
            error_log('[admin dashboard] ' . $error->getMessage());
            $this->respond(500, ['error' => ['message' => 'Dashboard data could not be loaded.']]);
        } catch (Throwable $error) {
            error_log('[admin dashboard] ' . $error->getMessage());
            $this->respond(500, ['error' => ['message' => 'Internal server error.']]);
        }
    }

    private function dispatch(): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            throw new AdminDashboardException('Method not allowed.', 405);
        }

        $action = $_GET['action'] ?? 'full';
        if (!is_string($action)) {
            throw new AdminDashboardException('Invalid dashboard action.', 400);
        }

        return match ($action) {
            'full' => $this->service->full($_GET),
            'summary' => $this->service->summary(),
            'sales' => $this->service->sales($_GET),
            'recent-orders' => $this->service->recentOrders($_GET),
            'recent-applications' => $this->service->recentApplications($_GET),
            'recent-complaints' => $this->service->recentComplaints($_GET),
            default => throw new AdminDashboardException('Dashboard route not found.', 404),
        };
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
$repository = new AdminDashboardRepository($database);
$service = new AdminDashboardService($repository);
$auth = new AdminAuthMiddleware(new AdminAuthService($database, $sessions));

(new AdminDashboardController($service, $auth))->run();
