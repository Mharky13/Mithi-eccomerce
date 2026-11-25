<?php
declare(strict_types=1);

namespace App\Dashboard;

use DateTimeImmutable;
use PDO;
use PDOException;
use Throwable;

require_once __DIR__ . '/../Backend/database.php';
require_once __DIR__ . '/../Backend/Auth.php';

final class DashboardException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus
    ) {
        parent::__construct($message);
    }
}

final class DateFilter
{
    /** @return array{0:string,1:string} */
    public function resolve(array $query, ?DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $startInput = $query['start_date'] ?? '';
        $endInput = $query['end_date'] ?? '';

        if (!is_string($startInput) || !is_string($endInput)) {
            throw self::invalid('Start and end dates must be strings.');
        }

        if ($startInput !== '' || $endInput !== '') {
            $from = $this->parse($startInput);
            $to = $this->parse($endInput);
        } else {
            $to = $today;
            $range = $query['range'] ?? 'last_30_days';
            if (!is_string($range)) {
                throw self::invalid('Unsupported date range.');
            }
            $from = match ($range) {
                'today' => $today,
                'last_7_days' => $today->modify('-6 days'),
                'last_30_days' => $today->modify('-29 days'),
                'this_month' => $today->modify('first day of this month'),
                default => throw self::invalid('Unsupported date range.'),
            };
        }

        if ($from > $to) {
            throw self::invalid('Start date is after end date.');
        }
        if ($to > $today) {
            throw self::invalid('End date cannot be in the future.');
        }
        if ($from->diff($to)->days > 366) {
            throw self::invalid('Date range is too large.');
        }

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    private function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw self::invalid('start_date and end_date are required in YYYY-MM-DD format.');
        }

        return $date;
    }

    private static function invalid(string $message): DashboardException
    {
        return new DashboardException('INVALID_DATE_RANGE', $message, 400);
    }
}

final class DashboardRepository
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
    public const LOW_STOCK_THRESHOLD = 10;

    public function __construct(private PDO $database)
    {
    }

    public function storeIdFor(?int $userId): int
    {
        $row = $userId === null
            ? null
            : ($this->rows(
                'SELECT u.user_Role, u.user_Status, s.seller_ID
                   FROM USERS u
                   LEFT JOIN SELLERS s ON s.user_ID = u.user_ID
                  WHERE u.user_ID = :id
                  LIMIT 1',
                ['id' => $userId]
            )[0] ?? null);

        if ($row === null) {
            throw new DashboardException('UNAUTHENTICATED', 'Authentication required.', 401);
        }
        if (
            strtolower(trim((string) $row['user_Role'])) !== 'seller'
            || strtolower(trim((string) $row['user_Status'])) !== 'active'
            || $row['seller_ID'] === null
        ) {
            throw new DashboardException('FORBIDDEN', 'An active seller account is required.', 403);
        }

        return (int) $row['seller_ID'];
    }

    public function productCount(int $storeId): int
    {
        return (int) $this->rows(
            'SELECT COUNT(*) AS product_count FROM PRODUCTS WHERE seller_ID = :store_id',
            ['store_id' => $storeId]
        )[0]['product_count'];
    }

    public function statusSummary(int $storeId, string $start, string $end): array
    {
        $summary = array_fill_keys(
            self::STATUSES,
            ['count' => 0, 'amount' => 0.0]
        );
        $rows = $this->rows(
            'SELECT order_Status AS status,
                    COUNT(*) AS order_count,
                    COALESCE(SUM(total_Amount), 0) AS amount
               FROM ORDERS
              WHERE seller_ID = :store_id
                AND order_Date BETWEEN :start_date AND :end_date
           GROUP BY order_Status',
            [
                'store_id' => $storeId,
                'start_date' => $start . ' 00:00:00',
                'end_date' => $end . ' 23:59:59',
            ]
        );

        foreach ($rows as $row) {
            $statusKey = strtolower(trim((string) $row['status']));
            foreach (self::STATUSES as $status) {
                if (strtolower($status) === $statusKey) {
                    $summary[$status] = [
                        'count' => (int) $row['order_count'],
                        'amount' => (float) $row['amount'],
                    ];
                    break;
                }
            }
        }

        return $summary;
    }

    public function recentOrders(int $storeId, int $limit): array
    {
        return $this->rows(
            'SELECT order_ID AS id,
                    order_Status AS status,
                    total_Amount AS total,
                    order_Date AS order_date
               FROM ORDERS
              WHERE seller_ID = :store_id
           ORDER BY order_Date DESC, order_ID DESC
              LIMIT :row_limit',
            ['store_id' => $storeId, 'row_limit' => $limit]
        );
    }

    public function lowStock(int $storeId, int $limit): array
    {
        return $this->rows(
            'SELECT p.prod_ID AS product_id,
                    p.prod_name AS name,
                    GREATEST(i.inventory_QTY - i.inventory_Reserved, 0) AS quantity
               FROM PRODUCTS p
               JOIN INVENTORY i ON i.prod_ID = p.prod_ID
              WHERE p.seller_ID = :store_id
                AND i.inventory_QTY <= :threshold
           ORDER BY i.inventory_QTY, p.prod_ID
              LIMIT :row_limit',
            [
                'store_id' => $storeId,
                'threshold' => self::LOW_STOCK_THRESHOLD,
                'row_limit' => $limit,
            ]
        );
    }

    private function rows(string $sql, array $parameters): array
    {
        $statement = $this->database->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(
                ':' . $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

final class DashboardController
{
    private const REVENUE_STATUSES = ['Delivered'];

    public function __construct(
        private DashboardRepository $repository,
        private DateFilter $dates = new DateFilter()
    ) {
    }

    public function summary(?int $userId, array $query = []): array
    {
        return $this->run(function () use ($userId, $query): array {
            $storeId = $this->repository->storeIdFor($userId);
            [$start, $end] = $this->dates->resolve($query);
            $summary = $this->repository->statusSummary($storeId, $start, $end);
            $counts = array_map(
                static fn(array $row): int => $row['count'],
                $summary
            );
            $revenueRows = array_intersect_key(
                $summary,
                array_flip(self::REVENUE_STATUSES)
            );

            return [
                'period' => ['start' => $start, 'end' => $end],
                'product_count' => $this->repository->productCount($storeId),
                'orders' => [
                    'total' => array_sum($counts),
                    'by_status' => $counts,
                ],
                'sales' => [
                    'total' => round(array_sum(array_column($revenueRows, 'amount')), 2),
                    'counted_orders' => array_sum(array_column($revenueRows, 'count')),
                ],
                'recent_orders' => $this->orders($storeId, 10),
                'low_stock' => $this->stock($storeId, 20),
            ];
        });
    }

    public function recentOrders(?int $userId, array $query = []): array
    {
        return $this->run(fn(): array => [
            'recent_orders' => $this->orders(
                $this->repository->storeIdFor($userId),
                $this->limit($query, 10)
            ),
        ]);
    }

    public function lowStock(?int $userId, array $query = []): array
    {
        return $this->run(fn(): array => [
            'low_stock' => $this->stock(
                $this->repository->storeIdFor($userId),
                $this->limit($query, 20)
            ),
        ]);
    }

    public static function send(array $response): never
    {
        http_response_code($response['status']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($response['body'], JSON_THROW_ON_ERROR);
        exit;
    }

    private function orders(int $storeId, int $limit): array
    {
        return array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'status' => (string) $row['status'],
                'total' => round((float) $row['total'], 2),
                'order_date' => (string) $row['order_date'],
            ],
            $this->repository->recentOrders($storeId, $limit)
        );
    }

    private function stock(int $storeId, int $limit): array
    {
        return array_map(
            static fn(array $row): array => [
                'product_id' => (int) $row['product_id'],
                'name' => (string) $row['name'],
                'quantity' => (int) $row['quantity'],
                'threshold' => DashboardRepository::LOW_STOCK_THRESHOLD,
            ],
            $this->repository->lowStock($storeId, $limit)
        );
    }

    private function limit(array $query, int $default): int
    {
        $value = $query['limit'] ?? $default;
        if (!is_scalar($value) || filter_var((string) $value, FILTER_VALIDATE_INT) === false) {
            throw new DashboardException('INVALID_LIMIT', 'Limit must be an integer.', 400);
        }

        return min(max((int) $value, 1), 50);
    }

    private function run(callable $action): array
    {
        try {
            return [
                'status' => 200,
                'body' => ['success' => true, 'data' => $action(), 'error' => null],
            ];
        } catch (DashboardException $error) {
            return $this->fail(
                $error->errorCode,
                $error->getMessage(),
                $error->httpStatus
            );
        } catch (PDOException $error) {
            error_log('Seller dashboard database error: ' . $error->getMessage());
            return $this->fail(
                'DATA_UNAVAILABLE',
                'Dashboard data is temporarily unavailable.',
                503
            );
        } catch (Throwable $error) {
            error_log('Seller dashboard error: ' . $error->getMessage());
            return $this->fail('INTERNAL_ERROR', 'Something went wrong.', 500);
        }
    }

    private function fail(string $code, string $message, int $status): array
    {
        return [
            'status' => $status,
            'body' => [
                'success' => false,
                'data' => null,
                'error' => ['code' => $code, 'message' => $message],
            ],
        ];
    }
}

function handleDashboardRequest(): never
{
    header('Cache-Control: no-store');
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            header('Allow: GET');
            DashboardController::send([
                'status' => 405,
                'body' => [
                    'success' => false,
                    'data' => null,
                    'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Use a GET request.'],
                ],
            ]);
            exit;
        }

        $sessions = new \SessionManager();
        $sessions->start();
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        $resolvedUserId = $userId === false || $userId <= 0 ? null : $userId;
        if ($resolvedUserId === null) {
            DashboardController::send([
                'status' => 401,
                'body' => [
                    'success' => false,
                    'data' => null,
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'message' => 'Authentication required.',
                    ],
                ],
            ]);
        }

        $action = $_GET['action'] ?? 'summary';
        if (!is_string($action)) {
            throw new DashboardException('INVALID_ACTION', 'Invalid dashboard action.', 400);
        }

        $controller = new DashboardController(
            new DashboardRepository(\Database::connect())
        );
        $response = match ($action) {
            'summary' => $controller->summary($resolvedUserId, $_GET),
            'recent-orders' => $controller->recentOrders($resolvedUserId, $_GET),
            'low-stock' => $controller->lowStock($resolvedUserId, $_GET),
            default => [
                'status' => 404,
                'body' => [
                    'success' => false,
                    'data' => null,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Unknown dashboard action.'],
                ],
            ],
        };
        DashboardController::send($response);
    } catch (PDOException $error) {
        error_log('Seller dashboard database connection error: ' . $error->getMessage());
        DashboardController::send([
            'status' => 503,
            'body' => [
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'DATA_UNAVAILABLE',
                    'message' => 'Dashboard data is temporarily unavailable.',
                ],
            ],
        ]);
    } catch (DashboardException $error) {
        DashboardController::send([
            'status' => $error->httpStatus,
            'body' => [
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => $error->errorCode,
                    'message' => $error->getMessage(),
                ],
            ],
        ]);
    } catch (Throwable $error) {
        error_log('Seller dashboard request error: ' . $error->getMessage());
        DashboardController::send([
            'status' => 500,
            'body' => [
                'success' => false,
                'data' => null,
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Something went wrong.'],
            ],
        ]);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    handleDashboardRequest();
}
