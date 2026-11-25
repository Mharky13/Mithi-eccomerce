<?php
declare(strict_types=1);

namespace App\SellerReports;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../Backend/database.php';
require_once __DIR__ . '/../Backend/Auth.php';

final class SellerReportException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        string $message
    ) {
        parent::__construct($message);
    }
}

final class SellerReportDateRange
{
    /** @return array{0:string,1:string} */
    public function resolve(array $query, ?DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $startInput = $query['start_date'] ?? '';
        $endInput = $query['end_date'] ?? '';
        if (!is_string($startInput) || !is_string($endInput)) {
            throw new SellerReportException(400, 'Start and end dates must be strings.');
        }

        if ($startInput !== '' || $endInput !== '') {
            $start = $this->parse($startInput);
            $end = $this->parse($endInput);
        } else {
            $range = $query['range'] ?? 'last_30_days';
            if (!is_string($range)) {
                throw new SellerReportException(400, 'Unsupported date range.');
            }
            $end = $today;
            $start = match ($range) {
                'today' => $today,
                'last_7_days' => $today->modify('-6 days'),
                'last_30_days' => $today->modify('-29 days'),
                'this_month' => $today->modify('first day of this month'),
                default => throw new SellerReportException(400, 'Unsupported date range.'),
            };
        }

        if ($start > $end) {
            throw new SellerReportException(400, 'Start date is after end date.');
        }
        if ($end > $today) {
            throw new SellerReportException(400, 'End date cannot be in the future.');
        }
        if ($start->diff($end)->days > 366) {
            throw new SellerReportException(400, 'Date range cannot exceed 367 days.');
        }

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    private function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new SellerReportException(400, 'Provide both dates in YYYY-MM-DD format.');
        }

        return $date;
    }
}

final class SellerReportRepository
{
    public const REPORT_TYPES = [
        'SUMMARY',
        'ORDERS',
        'PRODUCTS',
        'BEST_SELLING',
        'SALES_TRENDS',
        'INVENTORY',
    ];
    private const REVENUE_STATUS = 'Delivered';

    public function __construct(private PDO $database)
    {
    }

    public function sellerIdFor(int $userId): int
    {
        $query = $this->database->prepare("
            SELECT s.seller_ID
            FROM USERS u
            INNER JOIN SELLERS s ON s.user_ID = u.user_ID
            WHERE u.user_ID = :user_id
              AND LOWER(TRIM(u.user_Role)) = 'seller'
              AND LOWER(TRIM(u.user_Status)) = 'active'
            LIMIT 1
        ");
        $query->execute(['user_id' => $userId]);
        $sellerId = $query->fetchColumn();
        if ($sellerId === false) {
            throw new SellerReportException(403, 'An active seller account is required.');
        }

        return (int) $sellerId;
    }

    public function summary(int $sellerId, string $start, string $end): array
    {
        $statusQuery = $this->database->prepare("
            SELECT order_Status AS status,
                   COUNT(*) AS total_orders,
                   COALESCE(SUM(total_Amount), 0) AS total_amount
            FROM ORDERS
            WHERE seller_ID = :seller_id
              AND order_Date >= :start_date
              AND order_Date < DATE_ADD(:end_date, INTERVAL 1 DAY)
            GROUP BY order_Status
            ORDER BY order_Status
        ");
        $statusQuery->execute($this->dateParameters($sellerId, $start, $end));
        $statuses = $statusQuery->fetchAll(PDO::FETCH_ASSOC);

        $salesQuery = $this->database->prepare("
            SELECT COUNT(*) AS order_count,
                   COALESCE(SUM(total_Amount), 0) AS sales_total
            FROM ORDERS
            WHERE seller_ID = :seller_id
              AND order_Status = :revenue_status
              AND order_Date >= :start_date
              AND order_Date < DATE_ADD(:end_date, INTERVAL 1 DAY)
        ");
        $salesQuery->execute([
            ...$this->dateParameters($sellerId, $start, $end),
            'revenue_status' => self::REVENUE_STATUS,
        ]);
        $sales = $salesQuery->fetch(PDO::FETCH_ASSOC);

        return [
            'sales_total' => round((float) ($sales['sales_total'] ?? 0), 2),
            'order_count' => (int) ($sales['order_count'] ?? 0),
            'revenue_status' => self::REVENUE_STATUS,
            'order_status' => array_map(static fn(array $row): array => [
                'status' => (string) $row['status'],
                'total_orders' => (int) $row['total_orders'],
                'total_amount' => round((float) $row['total_amount'], 2),
            ], $statuses),
            'product_sales' => $this->productSales($sellerId, $start, $end),
        ];
    }

    public function orders(int $sellerId, string $start, string $end): array
    {
        $query = $this->database->prepare("
            SELECT order_ID AS order_id,
                   order_Status AS status,
                   total_Amount AS total_amount,
                   order_Date AS order_date
            FROM ORDERS
            WHERE seller_ID = :seller_id
              AND order_Status = :revenue_status
              AND order_Date >= :start_date
              AND order_Date < DATE_ADD(:end_date, INTERVAL 1 DAY)
            ORDER BY order_Date DESC, order_ID DESC
        ");
        $query->execute([
            ...$this->dateParameters($sellerId, $start, $end),
            'revenue_status' => self::REVENUE_STATUS,
        ]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function productSales(int $sellerId, string $start, string $end): array
    {
        $query = $this->database->prepare("
            SELECT p.prod_ID AS product_id,
                   p.prod_name AS product_name,
                   SUM(oi.quantity) AS units_sold,
                   ROUND(SUM(oi.subtotal), 2) AS revenue
            FROM ORDER_ITEMS oi
            INNER JOIN ORDERS o ON o.order_ID = oi.order_ID
            INNER JOIN PRODUCTS p ON p.prod_ID = oi.product_ID
            WHERE o.seller_ID = :seller_id
              AND p.seller_ID = :product_seller_id
              AND o.order_Status = :revenue_status
              AND o.order_Date >= :start_date
              AND o.order_Date < DATE_ADD(:end_date, INTERVAL 1 DAY)
            GROUP BY p.prod_ID, p.prod_name
            ORDER BY p.prod_name, p.prod_ID
        ");
        $query->execute([
            ...$this->dateParameters($sellerId, $start, $end),
            'product_seller_id' => $sellerId,
            'revenue_status' => self::REVENUE_STATUS,
        ]);

        return array_map(static fn(array $row): array => [
            'product_id' => (int) $row['product_id'],
            'product_name' => (string) $row['product_name'],
            'units_sold' => (int) $row['units_sold'],
            'revenue' => round((float) $row['revenue'], 2),
        ], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function salesTrends(
        int $sellerId,
        string $start,
        string $end,
        string $period
    ): array {
        $format = match ($period) {
            'DAILY' => '%Y-%m-%d',
            'MONTHLY' => '%Y-%m',
            default => throw new SellerReportException(400, 'Invalid trend period.'),
        };
        $query = $this->database->prepare("
            SELECT DATE_FORMAT(order_Date, '{$format}') AS period,
                COUNT(*) AS order_count,
                ROUND(SUM(total_Amount), 2) AS sales_total
            FROM ORDERS
            WHERE seller_ID = :seller_id
            AND order_Status = :revenue_status
            AND order_Date >= :start_date
            AND order_Date < DATE_ADD(:end_date, INTERVAL 1 DAY)
            GROUP BY period
            ORDER BY period ASC
        ");
        $query->execute([
            ...$this->dateParameters($sellerId, $start, $end),
            'revenue_status' => self::REVENUE_STATUS,
        ]);

        return array_map(static fn(array $row): array => [
            'period' => (string) $row['period'],
            'order_count' => (int) $row['order_count'],
            'sales_total' => round((float) $row['sales_total'], 2),
        ], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function inventory(int $sellerId): array
    {
        $query = $this->database->prepare("
            SELECT p.prod_ID AS product_id,
                p.prod_name AS product_name,
                i.inventory_ID AS inventory_id,
                i.inventory_QTY AS quantity,
                i.inventory_Reserved AS reserved,
                GREATEST(i.inventory_QTY - i.inventory_Reserved, 0) AS available
            FROM PRODUCTS p
            INNER JOIN INVENTORY i ON i.prod_ID = p.prod_ID
            WHERE p.seller_ID = :seller_id
            AND i.is_active = 1
            ORDER BY p.prod_name, p.prod_ID, i.inventory_ID
        ");
        $query->execute(['seller_id' => $sellerId]);

        return array_map(static fn(array $row): array => [
            'product_id' => (int) $row['product_id'],
            'product_name' => (string) $row['product_name'],
            'inventory_id' => (int) $row['inventory_id'],
            'quantity' => (int) $row['quantity'],
            'reserved' => (int) $row['reserved'],
            'available' => (int) $row['available'],
        ], $query->fetchAll(PDO::FETCH_ASSOC));
    }

    private function dateParameters(int $sellerId, string $start, string $end): array
    {
        return [
            'seller_id' => $sellerId,
            'start_date' => $start,
            'end_date' => $end,
        ];
    }
}

final class SellerReportsController
{
    private const MAX_PAGE_SIZE = 100;

    public function __construct(
        private SellerReportRepository $repository,
        private SellerReportDateRange $dates = new SellerReportDateRange()
    ) {
    }

    public function handle(): never
    {
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                header('Allow: GET');
                throw new SellerReportException(405, 'Use a GET request.');
            }

            $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
            if ($userId === false || $userId <= 0) {
                throw new SellerReportException(401, 'Authentication required.');
            }
            $sellerId = $this->repository->sellerIdFor((int) $userId);
            $reportType = $_GET['report_type'] ?? 'SUMMARY';
            if (!is_string($reportType)) {
                throw new SellerReportException(400, 'Invalid report type.');
            }
            $reportType = strtoupper($reportType);
            if (!in_array($reportType, SellerReportRepository::REPORT_TYPES, true)) {
                throw new SellerReportException(400, 'Invalid report type.');
            }

            [$start, $end] = $this->dates->resolve($_GET);
            $page = $this->positiveInteger($_GET['page'] ?? 1, 'page');
            $limit = $this->positiveInteger($_GET['limit'] ?? 10, 'limit');
            if ($limit > self::MAX_PAGE_SIZE) {
                throw new SellerReportException(422, 'Limit must be between 1 and 100.');
            }
            if ($page - 1 > intdiv(PHP_INT_MAX - $limit, $limit)) {
                throw new SellerReportException(422, 'Page is too large.');
            }

            $report = match ($reportType) {
                'SUMMARY' => $this->repository->summary($sellerId, $start, $end),
                'ORDERS' => $this->repository->orders($sellerId, $start, $end),
                'PRODUCTS' => $this->repository->productSales($sellerId, $start, $end),
                'BEST_SELLING' => $this->bestSelling($sellerId, $start, $end),
                'SALES_TRENDS' => $this->repository->salesTrends(
                    $sellerId,
                    $start,
                    $end,
                    $this->stringQuery('period', 'DAILY')
                ),
                'INVENTORY' => $this->repository->inventory($sellerId),
            };
            if ($reportType === 'BEST_SELLING' && $report === null) {
                throw new SellerReportException(400, 'Invalid ranking method.');
            }

            $period = ['start' => $start, 'end' => $end];
            if (($this->stringQuery('export', 'NO')) === 'YES') {
                if (($_SESSION['can_export'] ?? false) !== true) {
                    throw new SellerReportException(403, 'Export permission denied.');
                }
                $this->exportCsv($reportType, $period, $report);
            }

            $pagination = null;
            if (in_array($reportType, ['ORDERS', 'PRODUCTS', 'BEST_SELLING', 'SALES_TRENDS', 'INVENTORY'], true)) {
                $totalRecords = count($report);
                $report = array_slice($report, ($page - 1) * $limit, $limit);
                $pagination = [
                    'current_page' => $page,
                    'records_per_page' => $limit,
                    'total_records' => $totalRecords,
                    'total_pages' => (int) ceil($totalRecords / $limit),
                ];
            }

            $data = [
                'report_type' => $reportType,
                'period' => $period,
                'data' => $report,
            ];
            if ($pagination !== null) {
                $data['pagination'] = $pagination;
            }
            $this->respond(200, [
                'success' => true,
                'message' => $this->isEmpty($report)
                    ? 'No records found.'
                    : 'Sales report generated successfully.',
                ...$data,
            ]);
        } catch (SellerReportException $error) {
            $this->respond($error->httpStatus, [
                'success' => false,
                'message' => $error->getMessage(),
            ]);
        } catch (PDOException $error) {
            error_log('Seller reports database error: ' . $error->getMessage());
            $this->respond(503, [
                'success' => false,
                'message' => 'Reports are temporarily unavailable.',
            ]);
        } catch (Throwable $error) {
            error_log('Seller reports API error: ' . $error->getMessage());
            $this->respond(500, [
                'success' => false,
                'message' => 'An unexpected error occurred.',
            ]);
        }
    }

    private function bestSelling(int $sellerId, string $start, string $end): ?array
    {
        $ranking = $this->stringQuery('ranking', 'UNITS');
        if (!in_array($ranking, ['UNITS', 'REVENUE'], true)) {
            return null;
        }
        $products = $this->repository->productSales($sellerId, $start, $end);
        usort($products, static function (array $left, array $right) use ($ranking): int {
            $comparison = $ranking === 'UNITS'
                ? $right['units_sold'] <=> $left['units_sold']
                : $right['revenue'] <=> $left['revenue'];

            return $comparison !== 0
                ? $comparison
                : ($left['product_id'] <=> $right['product_id']);
        });

        return $products;
    }

    private function stringQuery(string $key, string $default): string
    {
        $value = $_GET[$key] ?? $default;
        if (!is_string($value)) {
            throw new SellerReportException(400, "Invalid {$key} parameter.");
        }

        return strtoupper($value);
    }

    private function positiveInteger(mixed $value, string $name): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 1) {
            throw new SellerReportException(422, ucfirst($name) . ' must be a positive integer.');
        }

        return $integer;
    }

    private function exportCsv(string $reportType, array $period, mixed $report): never
    {
        $rows = is_array($report) ? $report : [];
        $filename = strtolower($reportType) . '_' . $period['start'] . '_to_' . $period['end'] . '.csv';
        $output = fopen('php://output', 'w');
        if ($output === false) {
            throw new RuntimeException('Could not open CSV output stream.');
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        if ($rows !== [] && is_array(reset($rows))) {
            $columns = array_keys(reset($rows));
            fputcsv($output, $columns);
            foreach ($rows as $row) {
                fputcsv($output, array_map([$this, 'csvCell'], array_values($row)));
            }
        } elseif (is_array($rows)) {
            fputcsv($output, ['Field', 'Value']);
            foreach ($rows as $key => $value) {
                $value = is_array($value)
                    ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
                    : $value;
                fputcsv($output, [
                    $this->csvCell((string) $key),
                    $this->csvCell(is_string($value) ? $value : (is_scalar($value) ? $value : '')),
                ]);
            }
        }
        fclose($output);
        exit;
    }

    private function csvCell(mixed $value): string|int|float|null
    {
        if (!is_string($value)) {
            return is_scalar($value) || $value === null
                ? $value
                : (json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
        }
        if (preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    private function isEmpty(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        if ($value === []) {
            return true;
        }
        foreach ($value as $item) {
            if (is_array($item) && $this->isEmpty($item)) {
                continue;
            }
            return false;
        }

        return true;
    }

    private function respond(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $sessions = new \SessionManager();
        $sessions->start();
        $database = \Database::connect();
        (new SellerReportsController(new SellerReportRepository($database)))->handle();
    } catch (Throwable $error) {
        error_log('Seller reports bootstrap error: ' . $error->getMessage());
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'success' => false,
            'message' => 'Reports are temporarily unavailable.',
        ], JSON_UNESCAPED_SLASHES);
    }
}
