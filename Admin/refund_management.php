<?php
declare(strict_types=1);

require_once __DIR__ . '/../Backend/database.php';
require_once __DIR__ . '/AdminAuth.php';

final class AdminRefundException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

final class AdminRefundRepository
{
    public const STATUSES = ['Pending', 'Approved', 'Rejected', 'Processing', 'Completed'];

    public function __construct(private PDO $database)
    {
    }

    public function list(array $filters, int $page, int $pageSize): array
    {
        $where = [];
        $parameters = [];
        if ($filters['keyword'] !== null) {
            $where[] = "(
                CAST(r.refund_ID AS CHAR) LIKE :refund_id_keyword
                OR CAST(r.order_ID AS CHAR) LIKE :order_id_keyword
                OR CAST(r.payment_ID AS CHAR) LIKE :payment_id_keyword
                OR u.user_Email LIKE :email_keyword
                OR c.customer_FN LIKE :first_name_keyword
                OR c.customer_LN LIKE :last_name_keyword
                OR r.refund_Reason LIKE :reason_keyword
            )";
            $like = '%' . $filters['keyword'] . '%';
            $parameters = [
                'refund_id_keyword' => $like,
                'order_id_keyword' => $like,
                'payment_id_keyword' => $like,
                'email_keyword' => $like,
                'first_name_keyword' => $like,
                'last_name_keyword' => $like,
                'reason_keyword' => $like,
            ];
        }
        if ($filters['status'] !== null) {
            $where[] = 'r.refund_Status = :status';
            $parameters['status'] = $filters['status'];
        }
        if ($filters['date_from'] !== null) {
            $where[] = 'r.refund_RequestedAt >= :date_from';
            $parameters['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== null) {
            $where[] = 'r.refund_RequestedAt < :date_to';
            $parameters['date_to'] = (new DateTimeImmutable($filters['date_to']))
                ->modify('+1 day')
                ->format('Y-m-d 00:00:00');
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $joins = "
            FROM REFUNDS r
            INNER JOIN PAYMENTS p ON p.payment_ID = r.payment_ID
            INNER JOIN ORDERS o ON o.order_ID = r.order_ID
            INNER JOIN CUSTOMERS c ON c.customer_ID = r.customer_ID
            INNER JOIN USERS u ON u.user_ID = c.user_ID
        ";

        $count = $this->database->prepare("SELECT COUNT(*) {$joins} {$whereSql}");
        $this->bind($count, $parameters);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $query = $this->database->prepare("
            SELECT r.refund_ID AS id,
                   r.order_ID AS order_id,
                   r.payment_ID AS payment_id,
                   r.refund_TotalAmount AS amount,
                   r.refund_Reason AS reason,
                   r.refund_Status AS status,
                   r.refund_RequestedAt AS requested_at,
                   r.refund_ProcessedAt AS processed_at,
                   r.refund_ProviderReference AS provider_reference,
                   o.order_Status AS order_status,
                   o.total_Amount AS order_total,
                   p.payment_Status AS payment_status,
                   p.payment_Method AS payment_method,
                   p.payment_Amount AS payment_amount,
                   u.user_ID AS customer_user_id,
                   TRIM(CONCAT_WS(' ', c.customer_FN, c.customer_LN)) AS customer_name,
                   u.user_Email AS customer_email
            {$joins}
            {$whereSql}
            ORDER BY r.refund_RequestedAt DESC, r.refund_ID DESC
            LIMIT :row_limit OFFSET :row_offset
        ");
        $this->bind($query, $parameters);
        $query->bindValue(':row_limit', $pageSize, PDO::PARAM_INT);
        $query->bindValue(':row_offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $query->execute();

        return [
            'refunds' => $query->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $pageSize)),
            ],
        ];
    }

    public function refund(int $refundId, bool $lock = false): ?array
    {
        $query = $this->database->prepare("
            SELECT r.refund_ID AS id,
                   r.payment_ID AS payment_id,
                   r.order_ID AS order_id,
                   r.customer_ID AS customer_id,
                   r.admin_ID AS admin_id,
                   r.refund_TotalAmount AS amount,
                   r.refund_Reason AS reason,
                   r.refund_Status AS status,
                   r.refund_ReviewNotes AS review_notes,
                   r.refund_ProviderReference AS provider_reference,
                   r.refund_RequestedAt AS requested_at,
                   r.refund_ProcessedAt AS processed_at,
                   p.payment_Method AS payment_method,
                   p.payment_Provider AS payment_provider,
                   p.payment_Amount AS payment_amount,
                   p.payment_Status AS payment_status,
                   p.transaction_No AS payment_transaction,
                   o.customer_ID AS order_customer_id,
                   o.order_Status AS order_status,
                   o.order_Date AS order_date,
                   o.total_Amount AS order_total,
                   u.user_ID AS customer_user_id,
                   u.user_Email AS customer_email,
                   c.customer_FN AS customer_first_name,
                   c.customer_LN AS customer_last_name
            FROM REFUNDS r
            INNER JOIN PAYMENTS p ON p.payment_ID = r.payment_ID
            INNER JOIN ORDERS o ON o.order_ID = r.order_ID
            INNER JOIN CUSTOMERS c ON c.customer_ID = r.customer_ID
            INNER JOIN USERS u ON u.user_ID = c.user_ID
            WHERE r.refund_ID = :refund_id
            LIMIT 1
            " . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute(['refund_id' => $refundId]);
        $refund = $query->fetch(PDO::FETCH_ASSOC);

        return $refund === false ? null : $refund;
    }

    public function history(int $refundId): array
    {
        $query = $this->database->prepare("
            SELECT h.history_ID AS id,
                   h.event_Type AS event,
                   h.previous_Status AS previous_status,
                   h.new_Status AS status,
                   h.details,
                   h.created_at,
                   h.admin_ID,
                   a.admin_FN AS admin_first_name,
                   a.admin_LN AS admin_last_name,
                   u.user_Email AS admin_email
            FROM REFUND_STATUS_HISTORY h
            INNER JOIN ADMINS a ON a.admin_ID = h.admin_ID
            INNER JOIN USERS u ON u.user_ID = a.user_ID
            WHERE h.refund_ID = :refund_id
            ORDER BY h.created_at DESC, h.history_ID DESC
        ");
        $query->execute(['refund_id' => $refundId]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function paymentForUpdate(int $paymentId): ?array
    {
        $query = $this->database->prepare("
            SELECT payment_ID, order_ID, payment_Amount, payment_Status,
                   payment_Method, payment_Provider, transaction_No
            FROM PAYMENTS
            WHERE payment_ID = :payment_id
            FOR UPDATE
        ");
        $query->execute(['payment_id' => $paymentId]);
        $payment = $query->fetch(PDO::FETCH_ASSOC);

        return $payment === false ? null : $payment;
    }

    public function fitsRemainingBalance(int $paymentId, int $refundId, string $requestedAmount): bool
    {
        $query = $this->database->prepare("
            SELECT CASE
                WHEN CAST(:requested_amount_positive AS DECIMAL(10,2)) > 0
                 AND CAST(:requested_amount AS DECIMAL(10,2)) <= GREATEST(
                    p.payment_Amount - COALESCE((
                        SELECT SUM(r.refund_TotalAmount)
                        FROM REFUNDS r
                        WHERE r.payment_ID = p.payment_ID
                          AND r.refund_ID <> :refund_id
                          AND r.refund_Status IN ('Approved', 'Processing', 'Completed')
                    ), 0),
                    0
                ) THEN 1
                ELSE 0
            END
            FROM PAYMENTS p
            WHERE p.payment_ID = :payment_id
        ");
        $query->execute([
            'requested_amount_positive' => $requestedAmount,
            'requested_amount' => $requestedAmount,
            'refund_id' => $refundId,
            'payment_id' => $paymentId,
        ]);

        return (int) $query->fetchColumn() === 1;
    }

    public function updateStatus(
        int $refundId,
        int $adminId,
        string $status,
        ?string $reviewNotes = null,
        ?string $providerReference = null,
        bool $processed = false
    ): void {
        $query = $this->database->prepare("
            UPDATE REFUNDS
            SET refund_Status = :status,
                admin_ID = :admin_id,
                refund_ReviewNotes = COALESCE(:review_notes, refund_ReviewNotes),
                refund_ProviderReference = COALESCE(:provider_reference, refund_ProviderReference),
                refund_ProcessedAt = CASE WHEN :processed = 1 THEN NOW() ELSE refund_ProcessedAt END
            WHERE refund_ID = :refund_id
        ");
        $query->execute([
            'status' => $status,
            'admin_id' => $adminId,
            'review_notes' => $reviewNotes,
            'provider_reference' => $providerReference,
            'processed' => $processed ? 1 : 0,
            'refund_id' => $refundId,
        ]);
    }

    public function addHistory(
        int $refundId,
        int $adminId,
        string $event,
        string $previousStatus,
        string $newStatus,
        ?string $details
    ): void {
        $query = $this->database->prepare("
            INSERT INTO REFUND_STATUS_HISTORY
                (refund_ID, admin_ID, event_Type, previous_Status, new_Status, details)
            VALUES
                (:refund_id, :admin_id, :event, :previous_status, :new_status, :details)
        ");
        $query->execute([
            'refund_id' => $refundId,
            'admin_id' => $adminId,
            'event' => $event,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'details' => $details,
        ]);
    }

    public function notifyCustomer(array $refund, string $title, string $message): void
    {
        $query = $this->database->prepare("
            INSERT INTO NOTIFICATIONS
                (user_ID, order_ID, notification_Type, notification_Title, notification_Message)
            VALUES
                (:user_id, :order_id, 'Payment', :title, :message)
        ");
        $query->execute([
            'user_id' => (int) $refund['customer_user_id'],
            'order_id' => (int) $refund['order_id'],
            'title' => $title,
            'message' => $message,
        ]);
    }

    public function markPaymentRefundedIfFullyRefunded(int $paymentId): void
    {
        $query = $this->database->prepare("
            UPDATE PAYMENTS
            SET payment_Status = 'Refunded'
            WHERE payment_ID = :payment_id
              AND payment_Status = 'Paid'
              AND payment_Amount <= (
                  SELECT COALESCE(SUM(r.refund_TotalAmount), 0)
                  FROM REFUNDS r
                  WHERE r.payment_ID = :payment_id_for_sum
                    AND r.refund_Status = 'Completed'
              )
        ");
        $query->execute([
            'payment_id' => $paymentId,
            'payment_id_for_sum' => $paymentId,
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

final class AdminRefundService
{
    private const PAGE_SIZE_MAX = 100;

    public function __construct(
        private PDO $database,
        private AdminRefundRepository $repository
    ) {
    }

    public function list(array $query): array
    {
        $keyword = $this->optionalString($query, 'search', 150);
        $status = $query['status'] ?? null;
        if ($status !== null && $status !== '') {
            if (!is_string($status) || !in_array($status, AdminRefundRepository::STATUSES, true)) {
                throw new AdminRefundException(
                    'Invalid refund status filter. Allowed values: '
                        . implode(', ', AdminRefundRepository::STATUSES) . '.',
                    422
                );
            }
        } else {
            $status = null;
        }
        $dateFrom = $this->date($query['date_from'] ?? null, 'date_from');
        $dateTo = $this->date($query['date_to'] ?? null, 'date_to');
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            throw new AdminRefundException('date_from must not be later than date_to.', 422);
        }

        $page = $this->positiveInteger($query['page'] ?? 1, 'page');
        $pageSize = $this->positiveInteger($query['page_size'] ?? 20, 'page_size');
        if (
            $pageSize > self::PAGE_SIZE_MAX
            || ($page - 1) > intdiv(PHP_INT_MAX - $pageSize, $pageSize)
        ) {
            throw new AdminRefundException('Invalid pagination parameters.', 422);
        }

        return $this->repository->list([
            'keyword' => $keyword,
            'status' => $status,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ], $page, $pageSize);
    }

    public function details(int $refundId): array
    {
        $refund = $this->repository->refund($refundId);
        if ($refund === null) {
            throw new AdminRefundException('Refund request not found.', 404);
        }

        return [
            'refund' => $refund,
            'history' => $this->repository->history($refundId),
        ];
    }

    public function approve(int $refundId, array $input, array $admin): array
    {
        $notes = $this->optionalString($input, 'notes', 2000);

        return $this->transaction(function () use ($refundId, $notes, $admin): array {
            $refund = $this->requireRefund($refundId, true);
            if ($refund['status'] !== 'Pending') {
                throw new AdminRefundException('Only pending refund requests can be approved.', 409);
            }
            $payment = $this->repository->paymentForUpdate((int) $refund['payment_id']);
            if (
                $payment === null
                || (int) $payment['order_ID'] !== (int) $refund['order_id']
                || (int) $refund['order_customer_id'] !== (int) $refund['customer_id']
                || !in_array($payment['payment_Status'], ['Paid'], true)
            ) {
                throw new AdminRefundException(
                    'The linked payment must belong to this order and be paid before approval.',
                    409
                );
            }
            if (!$this->repository->fitsRemainingBalance(
                (int) $refund['payment_id'],
                $refundId,
                (string) $refund['amount']
            )) {
                throw new AdminRefundException(
                    'Approving this request would exceed the payment amount after other approved or completed refunds.',
                    409
                );
            }

            $this->repository->updateStatus($refundId, (int) $admin['admin_ID'], 'Approved', $notes);
            $this->recordEvent($refund, $admin, 'APPROVED', 'Approved', $notes);
            $this->repository->notifyCustomer(
                $refund,
                'Refund approved',
                'Your refund request #' . $refundId . ' was approved and is awaiting processing.'
            );

            return [
                'refund_id' => $refundId,
                'status' => 'Approved',
                'message' => 'Refund approved. Start the external refund process before completing it.',
            ];
        });
    }

    public function reject(int $refundId, array $input, array $admin): array
    {
        $reason = $this->requiredString($input, 'reason', 2000);

        return $this->transaction(function () use ($refundId, $reason, $admin): array {
            $refund = $this->requireRefund($refundId, true);
            if (!in_array($refund['status'], ['Pending', 'Approved'], true)) {
                throw new AdminRefundException('This refund request can no longer be rejected.', 409);
            }

            $this->repository->updateStatus(
                $refundId,
                (int) $admin['admin_ID'],
                'Rejected',
                $reason,
                null,
                true
            );
            $this->recordEvent($refund, $admin, 'REJECTED', 'Rejected', $reason);
            $this->repository->notifyCustomer(
                $refund,
                'Refund request rejected',
                'Your refund request #' . $refundId . ' was rejected. Reason: ' . $reason
            );

            return ['refund_id' => $refundId, 'status' => 'Rejected'];
        });
    }

    public function initiate(int $refundId, array $input, array $admin): array
    {
        $notes = $this->optionalString($input, 'notes', 2000);

        return $this->transaction(function () use ($refundId, $notes, $admin): array {
            $refund = $this->requireRefund($refundId, true);
            if ($refund['status'] !== 'Approved') {
                throw new AdminRefundException('Only approved refund requests can enter processing.', 409);
            }
            $payment = $this->repository->paymentForUpdate((int) $refund['payment_id']);
            if (
                $payment === null
                || (int) $payment['order_ID'] !== (int) $refund['order_id']
                || (int) $refund['order_customer_id'] !== (int) $refund['customer_id']
                || $payment['payment_Status'] !== 'Paid'
                || !$this->repository->fitsRemainingBalance(
                    (int) $refund['payment_id'],
                    $refundId,
                    (string) $refund['amount']
                )
            ) {
                throw new AdminRefundException('The linked payment could not be verified.', 409);
            }

            $details = 'Manual refund processing started for '
                . (string) ($payment['payment_Provider'] ?: $payment['payment_Method']) . '.';
            if ($notes !== null) {
                $details .= ' ' . $notes;
            }
            $this->repository->updateStatus(
                $refundId,
                (int) $admin['admin_ID'],
                'Processing',
                $notes
            );
            $this->recordEvent($refund, $admin, 'PROCESSING_STARTED', 'Processing', $details);
            $this->repository->notifyCustomer(
                $refund,
                'Refund processing started',
                'Refund request #' . $refundId . ' is being processed.'
            );

            return [
                'refund_id' => $refundId,
                'status' => 'Processing',
                'payment_method' => $payment['payment_Method'],
                'payment_provider' => $payment['payment_Provider'],
                'message' => 'Complete the refund with the payment provider or manual payment workflow, then record its reference.',
            ];
        });
    }

    public function complete(int $refundId, array $input, array $admin): array
    {
        $reference = $this->requiredString($input, 'provider_reference', 100);
        if (($input['confirm_refund_sent'] ?? false) !== true) {
            throw new AdminRefundException(
                'Set confirm_refund_sent to true only after the external refund has been sent.',
                422
            );
        }

        return $this->transaction(function () use ($refundId, $reference, $admin): array {
            $refund = $this->requireRefund($refundId, true);
            if ($refund['status'] !== 'Processing') {
                throw new AdminRefundException('Only processing refunds can be completed.', 409);
            }
            $payment = $this->repository->paymentForUpdate((int) $refund['payment_id']);
            if (
                $payment === null
                || (int) $payment['order_ID'] !== (int) $refund['order_id']
                || (int) $refund['order_customer_id'] !== (int) $refund['customer_id']
                || $payment['payment_Status'] !== 'Paid'
                || !$this->repository->fitsRemainingBalance(
                    (int) $refund['payment_id'],
                    $refundId,
                    (string) $refund['amount']
                )
            ) {
                throw new AdminRefundException('The linked payment could not be verified.', 409);
            }

            $this->repository->updateStatus(
                $refundId,
                (int) $admin['admin_ID'],
                'Completed',
                null,
                $reference,
                true
            );
            $this->repository->markPaymentRefundedIfFullyRefunded((int) $refund['payment_id']);
            $this->recordEvent(
                $refund,
                $admin,
                'COMPLETED',
                'Completed',
                'External refund reference: ' . $reference
            );
            $this->repository->notifyCustomer(
                $refund,
                'Refund completed',
                'Your refund request #' . $refundId . ' has been completed.'
            );

            return [
                'refund_id' => $refundId,
                'status' => 'Completed',
                'provider_reference' => $reference,
            ];
        });
    }

    private function requireRefund(int $refundId, bool $lock): array
    {
        $refund = $this->repository->refund($refundId, $lock);
        if ($refund === null) {
            throw new AdminRefundException('Refund request not found.', 404);
        }

        return $refund;
    }

    private function recordEvent(array $refund, array $admin, string $event, string $status, ?string $details): void
    {
        $this->repository->addHistory(
            (int) $refund['id'],
            (int) $admin['admin_ID'],
            $event,
            (string) $refund['status'],
            $status,
            $details
        );
    }

    private function transaction(callable $operation): array
    {
        $this->database->beginTransaction();
        try {
            $result = $operation();
            $this->database->commit();

            return $result;
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function optionalString(array $input, string $key, int $maximum): ?string
    {
        $value = $input[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > $maximum) {
            throw new AdminRefundException($key . ' must be text up to ' . $maximum . ' characters.', 422);
        }

        return trim($value);
    }

    private function requiredString(array $input, string $key, int $maximum): string
    {
        $value = $this->optionalString($input, $key, $maximum);
        if ($value === null) {
            throw new AdminRefundException($key . ' is required.', 422);
        }

        return $value;
    }

    private function positiveInteger(mixed $value, string $key): int
    {
        if (
            (!is_int($value) && !is_string($value))
            || preg_match('/^[1-9]\d*$/', (string) $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT) === false
        ) {
            throw new AdminRefundException($key . ' must be a positive integer.', 422);
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
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$date
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new AdminRefundException($key . ' must use YYYY-MM-DD format.', 422);
        }

        return $value;
    }
}

final class AdminRefundController
{
    private const MAX_BODY_SIZE = 16384;

    public function __construct(
        private AdminRefundService $service,
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
        } catch (AdminRefundException $error) {
            $this->respond($error->httpStatus, [
                'success' => false,
                'data' => null,
                'error' => [
                    'message' => $error->getMessage(),
                    ...($error->details === [] ? [] : ['details' => $error->details]),
                ],
            ]);
        } catch (PDOException $error) {
            if ((string) ($error->errorInfo[1] ?? '') === '1062') {
                $this->respond(409, [
                    'success' => false,
                    'data' => null,
                    'error' => ['message' => 'This provider reference has already been used.'],
                ]);
            }
            error_log('[admin refunds] ' . $error->getMessage());
            $this->respond(503, [
                'success' => false,
                'data' => null,
                'error' => ['message' => 'Refund service is temporarily unavailable.'],
            ]);
        } catch (Throwable $error) {
            error_log('[admin refunds] ' . $error->getMessage());
            $this->respond(500, [
                'success' => false,
                'data' => null,
                'error' => ['message' => 'The refund request could not be completed.'],
            ]);
        }
    }

    private function dispatch(array $admin): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $action = $_GET['action'] ?? 'list';
            if (!is_string($action)) {
                throw new AdminRefundException('Invalid refund action.', 400);
            }
            if ($action === 'csrf') {
                if (empty($_SESSION['csrf_token'])) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }

                return ['csrf_token' => $_SESSION['csrf_token']];
            }
            if ($action === 'details' || $action === 'history') {
                $refundId = $this->positiveInteger($_GET['refund_id'] ?? null, 'refund_id');
                if ($action === 'history') {
                    return ['history' => $this->service->details($refundId)['history']];
                }

                return $this->service->details($refundId);
            }
            if ($action === 'list' || $action === 'search') {
                return $this->service->list($_GET);
            }

            throw new AdminRefundException('Unknown refund action.', 404);
        }

        if ($method !== 'POST') {
            header('Allow: GET, POST');
            throw new AdminRefundException('Method not allowed.', 405);
        }
        $input = $this->readInput();
        $this->requireCsrf($input);
        $refundId = $this->positiveInteger($input['refund_id'] ?? null, 'refund_id');
        $action = $input['action'] ?? '';
        if (!is_string($action)) {
            throw new AdminRefundException('Invalid refund action.', 400);
        }

        return match ($action) {
            'approve' => $this->service->approve($refundId, $input, $admin),
            'reject' => $this->service->reject($refundId, $input, $admin),
            'initiate' => $this->service->initiate($refundId, $input, $admin),
            'complete' => $this->service->complete($refundId, $input, $admin),
            default => throw new AdminRefundException('Unknown refund action.', 404),
        };
    }

    private function readInput(): array
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_SIZE) {
            throw new AdminRefundException('Request is too large.', 413);
        }
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType === 'application/json') {
            $input = json_decode(file_get_contents('php://input') ?: '', true);
            if (!is_array($input)) {
                throw new AdminRefundException('Malformed JSON request.', 400);
            }

            return $input;
        }
        if ($contentType === 'application/x-www-form-urlencoded') {
            return $_POST;
        }

        throw new AdminRefundException('Send a JSON request.', 415);
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
            throw new AdminRefundException('Invalid or missing CSRF token.', 403);
        }
    }

    private function positiveInteger(mixed $value, string $key): int
    {
        if (
            (!is_int($value) && !is_string($value))
            || preg_match('/^[1-9]\d*$/', (string) $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT) === false
        ) {
            throw new AdminRefundException($key . ' must be a positive integer.', 422);
        }

        return (int) $value;
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
$repository = new AdminRefundRepository($database);
$service = new AdminRefundService($database, $repository);
$auth = new AdminAuthMiddleware(new AdminAuthService($database, new SessionManager()));

(new AdminRefundController($service, $auth))->run();
