<?php
declare(strict_types=1);

require_once __DIR__ . '/../Backend/database.php';
require_once __DIR__ . '/AdminAuth.php';

final class AdminComplaintException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

final class AdminComplaintStatus
{
    public const PENDING = 'Pending';
    public const IN_PROGRESS = 'In Progress';
    public const RESOLVED = 'Resolved';
    public const REJECTED = 'Rejected';
    public const CLOSED = 'Closed';

    private const TRANSITIONS = [
        self::PENDING => [self::IN_PROGRESS, self::REJECTED],
        self::IN_PROGRESS => [self::RESOLVED, self::REJECTED],
        self::RESOLVED => [self::CLOSED],
        self::REJECTED => [self::CLOSED],
        self::CLOSED => [],
    ];

    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}

final class AdminComplaintRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function search(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $parameters = [];
        if ($filters['keyword'] !== null) {
            $where[] = "(
                CAST(c.complaint_ID AS CHAR) LIKE :id_keyword
                OR c.complaint_Subject LIKE :subject_keyword
                OR c.complaint_Description LIKE :description_keyword
            )";
            $needle = '%' . $filters['keyword'] . '%';
            $parameters['id_keyword'] = $needle;
            $parameters['subject_keyword'] = $needle;
            $parameters['description_keyword'] = $needle;
        }
        if ($filters['status'] !== null) {
            $where[] = 'c.complaint_Status = :status';
            $parameters['status'] = $filters['status'];
        }
        if ($filters['complainant'] !== null) {
            $where[] = "(
                CAST(cu.user_ID AS CHAR) = :complainant_id
                OR cu.user_Email LIKE :complainant_email
                OR CONCAT_WS(' ', cu.customer_FN, cu.customer_LN) LIKE :complainant_name
            )";
            $parameters['complainant_id'] = $filters['complainant'];
            $parameters['complainant_email'] = '%' . $filters['complainant'] . '%';
            $parameters['complainant_name'] = '%' . $filters['complainant'] . '%';
        }
        if ($filters['date_from'] !== null) {
            $where[] = 'c.created_at >= :date_from';
            $parameters['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== null) {
            $where[] = 'c.created_at < :date_to';
            $parameters['date_to'] = (new DateTimeImmutable($filters['date_to']))
                ->modify('+1 day')
                ->format('Y-m-d 00:00:00');
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $joins = "
            FROM COMPLAINTS c
            INNER JOIN CUSTOMERS cc ON cc.customer_ID = c.customer_ID
            INNER JOIN USERS cu ON cu.user_ID = cc.user_ID
            LEFT JOIN SELLERS s ON s.seller_ID = c.seller_ID
        ";

        $count = $this->database->prepare("SELECT COUNT(*) {$joins} {$whereSql}");
        $this->bind($count, $parameters);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $query = $this->database->prepare("
            SELECT c.complaint_ID AS id,
                   c.complaint_Subject AS subject,
                   c.complaint_Status AS status,
                   c.created_at,
                   c.updated_at,
                   cc.customer_ID AS customer_id,
                   cu.user_ID AS complainant_user_id,
                   TRIM(CONCAT_WS(' ', cc.customer_FN, cc.customer_LN)) AS complainant_name,
                   cu.user_Email AS complainant_email,
                   s.seller_ID AS seller_id,
                   s.seller_StoreName AS seller_name,
                   c.admin_ID AS assigned_admin_id,
                   TRIM(CONCAT_WS(' ', assigned.admin_FN, assigned.admin_LN)) AS assigned_admin_name,
                   au.user_Email AS assigned_admin_email,
                   (SELECT h.created_at
                      FROM COMPLAINT_STATUS_HISTORY h
                     WHERE h.complaint_ID = c.complaint_ID
                       AND h.event_Type = 'ASSIGNED'
                     ORDER BY h.created_at DESC, h.history_ID DESC
                     LIMIT 1) AS assigned_at
            {$joins}
            LEFT JOIN ADMINS assigned ON assigned.admin_ID = c.admin_ID
            LEFT JOIN USERS au ON au.user_ID = assigned.user_ID
            {$whereSql}
            ORDER BY c.created_at DESC, c.complaint_ID DESC
            LIMIT :row_limit OFFSET :row_offset
        ");
        $this->bind($query, $parameters);
        $query->bindValue(':row_limit', $perPage, PDO::PARAM_INT);
        $query->bindValue(':row_offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $query->execute();
        $items = $query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as &$item) {
            $item['assigned_to'] = $item['assigned_admin_id'] === null ? null : [
                'id' => (int) $item['assigned_admin_id'],
                'name' => $item['assigned_admin_name'],
                'email' => $item['assigned_admin_email'],
            ];
            unset($item['assigned_admin_id']);
            unset($item['assigned_admin_name']);
            unset($item['assigned_admin_email']);
        }
        unset($item);

        return [
            'data' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function complaint(int $id, bool $lock = false): ?array
    {
        $query = $this->database->prepare("
            SELECT c.complaint_ID AS id,
                   c.customer_ID,
                   cc.user_ID AS complainant_user_id,
                   cc.customer_FN AS complainant_first_name,
                   cc.customer_LN AS complainant_last_name,
                   cu.user_Email AS complainant_email,
                   c.seller_ID,
                   s.user_ID AS respondent_user_id,
                   s.seller_StoreName AS respondent_store,
                   s.seller_FN AS respondent_first_name,
                   s.seller_LN AS respondent_last_name,
                   su.user_Email AS respondent_email,
                   c.order_ID,
                   c.complaint_Subject AS subject,
                   c.complaint_Description AS description,
                   c.complaint_Status AS status,
                   c.resolution_Notes AS resolution_notes,
                   c.created_at,
                   c.updated_at,
                   c.resolved_at,
                   c.admin_ID AS assigned_admin_id
            FROM COMPLAINTS c
            INNER JOIN CUSTOMERS cc ON cc.customer_ID = c.customer_ID
            INNER JOIN USERS cu ON cu.user_ID = cc.user_ID
            LEFT JOIN SELLERS s ON s.seller_ID = c.seller_ID
            LEFT JOIN USERS su ON su.user_ID = s.user_ID
            WHERE c.complaint_ID = :complaint_id
            LIMIT 1
            " . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute(['complaint_id' => $id]);
        $complaint = $query->fetch(PDO::FETCH_ASSOC);

        return $complaint === false ? null : $complaint;
    }

    public function order(?int $orderId): ?array
    {
        if ($orderId === null) {
            return null;
        }
        $query = $this->database->prepare("
            SELECT order_ID AS id, order_Status AS status, order_Date AS created_at,
                   total_Amount AS total_amount
            FROM ORDERS
            WHERE order_ID = :order_id
            LIMIT 1
        ");
        $query->execute(['order_id' => $orderId]);
        $order = $query->fetch(PDO::FETCH_ASSOC);

        return $order === false ? null : $order;
    }

    public function assignee(int $adminId): ?array
    {
        $query = $this->database->prepare("
            SELECT a.admin_ID AS id, a.user_ID, a.admin_FN AS first_name,
                   a.admin_LN AS last_name, u.user_Email AS email,
                   LOWER(TRIM(u.user_Role)) AS role,
                   LOWER(TRIM(u.user_Status)) AS user_status,
                   LOWER(TRIM(a.admin_Status)) AS admin_status
            FROM ADMINS a
            INNER JOIN USERS u ON u.user_ID = a.user_ID
            WHERE a.admin_ID = :admin_id
            LIMIT 1
        ");
        $query->execute(['admin_id' => $adminId]);
        $admin = $query->fetch(PDO::FETCH_ASSOC);

        return $admin === false ? null : $admin;
    }

    public function history(int $complaintId): array
    {
        $query = $this->database->prepare("
            SELECT h.history_ID AS id,
                   h.event_Type AS event,
                   h.previous_Status AS previous_status,
                   h.new_Status AS status,
                   h.details,
                   h.created_at,
                   h.admin_ID AS actor_admin_id,
                   a.admin_FN AS actor_first_name,
                   a.admin_LN AS actor_last_name,
                   u.user_Email AS actor_email
            FROM COMPLAINT_STATUS_HISTORY h
            INNER JOIN ADMINS a ON a.admin_ID = h.admin_ID
            INNER JOIN USERS u ON u.user_ID = a.user_ID
            WHERE h.complaint_ID = :complaint_id
            ORDER BY h.created_at DESC, h.history_ID DESC
        ");
        $query->execute(['complaint_id' => $complaintId]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function notifications(int $complaintId): array
    {
        $query = $this->database->prepare("
            SELECT n.notification_ID AS id,
                   n.user_ID,
                   n.notification_Title AS title,
                   n.notification_Message AS message,
                   n.notification_Status AS status,
                   n.notification_CreatedAt AS created_at
            FROM NOTIFICATIONS n
            WHERE n.complaint_ID = :complaint_id
            ORDER BY n.notification_CreatedAt DESC, n.notification_ID DESC
        ");
        $query->execute(['complaint_id' => $complaintId]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function eligibleAssignee(int $adminId): ?array
    {
        $query = $this->database->prepare("
            SELECT a.admin_ID, a.user_ID
            FROM ADMINS a
            INNER JOIN USERS u ON u.user_ID = a.user_ID
            WHERE a.admin_ID = :admin_id
              AND LOWER(TRIM(u.user_Role)) = 'admin'
              AND LOWER(TRIM(u.user_Status)) = 'active'
              AND LOWER(TRIM(a.admin_Status)) = 'active'
            LIMIT 1
        ");
        $query->execute(['admin_id' => $adminId]);
        $assignee = $query->fetch(PDO::FETCH_ASSOC);

        return $assignee === false ? null : $assignee;
    }

    public function assign(int $complaintId, int $adminId): void
    {
        $query = $this->database->prepare(
            'UPDATE COMPLAINTS SET admin_ID = :admin_id WHERE complaint_ID = :complaint_id'
        );
        $query->execute(['admin_id' => $adminId, 'complaint_id' => $complaintId]);
    }

    public function updateStatus(int $complaintId, string $status): void
    {
        $query = $this->database->prepare("
            UPDATE COMPLAINTS
            SET complaint_Status = :status
            WHERE complaint_ID = :complaint_id
        ");
        $query->execute(['status' => $status, 'complaint_id' => $complaintId]);
    }

    public function resolve(int $complaintId, string $resolution): void
    {
        $query = $this->database->prepare("
            UPDATE COMPLAINTS
            SET complaint_Status = 'Resolved',
                resolution_Notes = :resolution,
                resolved_at = NOW()
            WHERE complaint_ID = :complaint_id
        ");
        $query->execute(['resolution' => $resolution, 'complaint_id' => $complaintId]);
    }

    public function addHistory(
        int $complaintId,
        int $adminId,
        string $event,
        ?string $previousStatus,
        ?string $newStatus,
        ?string $details
    ): void {
        $query = $this->database->prepare("
            INSERT INTO COMPLAINT_STATUS_HISTORY
                (complaint_ID, admin_ID, event_Type, previous_Status, new_Status, details)
            VALUES
                (:complaint_id, :admin_id, :event, :previous_status, :new_status, :details)
        ");
        $query->execute([
            'complaint_id' => $complaintId,
            'admin_id' => $adminId,
            'event' => $event,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'details' => $details,
        ]);
    }

    public function notifyUsers(int $complaintId, array $userIds, string $title, string $message): void
    {
        $insert = $this->database->prepare("
            INSERT INTO NOTIFICATIONS
                (user_ID, complaint_ID, notification_Type, notification_Title, notification_Message, action_Link)
            VALUES
                (:user_id, :complaint_id, 'General', :title, :message, :action_link)
        ");
        foreach (array_unique($userIds) as $userId) {
            if ($userId === null) {
                continue;
            }
            $insert->execute([
                'user_id' => (int) $userId,
                'complaint_id' => $complaintId,
                'title' => $title,
                'message' => $message,
                'action_link' => '/complaints/' . $complaintId,
            ]);
        }
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

final class AdminComplaintService
{
    private const PAGE_MAX = 100;

    public function __construct(
        private PDO $database,
        private AdminComplaintRepository $repository
    ) {
    }

    public function search(array $query, bool $filtered): array
    {
        $page = $this->positiveInteger($query['page'] ?? 1, 'page');
        $perPage = $this->positiveInteger($query['per_page'] ?? 20, 'per_page');
        if ($perPage > self::PAGE_MAX || ($page - 1) > intdiv(PHP_INT_MAX - $perPage, $perPage)) {
            throw new AdminComplaintException('Invalid pagination parameters.', 422);
        }

        $filters = [
            'keyword' => null,
            'status' => null,
            'complainant' => null,
            'date_from' => null,
            'date_to' => null,
        ];
        if ($filtered) {
            $filters['keyword'] = $this->optionalString($query, 'keyword', 150);
            $filters['status'] = $this->optionalStatus($query['status'] ?? null);
            $filters['complainant'] = $this->optionalString($query, 'complainant', 150);
            $filters['date_from'] = $this->date($query['date_from'] ?? null, 'date_from');
            $filters['date_to'] = $this->date($query['date_to'] ?? null, 'date_to');
            if (
                $filters['date_from'] !== null
                && $filters['date_to'] !== null
                && $filters['date_from'] > $filters['date_to']
            ) {
                throw new AdminComplaintException('date_from must not be later than date_to.', 422);
            }
        }

        return $this->repository->search($filters, $page, $perPage);
    }

    public function details(int $id): array
    {
        $complaint = $this->repository->complaint($id);
        if ($complaint === null) {
            throw new AdminComplaintException('Complaint not found.', 404);
        }
        $order = $this->repository->order(
            $complaint['order_ID'] === null ? null : (int) $complaint['order_ID']
        );
        $assignee = $complaint['assigned_admin_id'] === null
            ? null
            : $this->repository->assignee((int) $complaint['assigned_admin_id']);

        return [
            'complaint' => $complaint,
            'complainant' => [
                'id' => (int) $complaint['complainant_user_id'],
                'name' => trim($complaint['complainant_first_name'] . ' ' . $complaint['complainant_last_name']),
                'email' => $complaint['complainant_email'],
            ],
            'respondent' => $complaint['seller_ID'] === null ? null : [
                'seller_id' => (int) $complaint['seller_ID'],
                'user_id' => (int) $complaint['respondent_user_id'],
                'store_name' => $complaint['respondent_store'],
                'name' => trim($complaint['respondent_first_name'] . ' ' . $complaint['respondent_last_name']),
                'email' => $complaint['respondent_email'],
            ],
            'assignee' => $assignee,
            'order' => $order,
            'history' => $this->repository->history($id),
            'resolution' => $complaint['resolution_notes'] === null ? null : [
                'notes' => $complaint['resolution_notes'],
                'resolved_at' => $complaint['resolved_at'],
            ],
        ];
    }

    public function notifications(int $id): array
    {
        if ($this->repository->complaint($id) === null) {
            throw new AdminComplaintException('Complaint not found.', 404);
        }

        return ['data' => $this->repository->notifications($id)];
    }

    public function assign(int $id, array $body, array $actor): array
    {
        $assigneeId = $this->positiveInteger($body['assignee_id'] ?? null, 'assignee_id');
        $confirmReassign = $body['confirm_reassign'] ?? false;
        if (!is_bool($confirmReassign)) {
            throw new AdminComplaintException('confirm_reassign must be boolean.', 422);
        }

        return $this->transaction(function () use ($id, $assigneeId, $confirmReassign, $actor): array {
            $complaint = $this->requireComplaint($id, true);
            if (
                $complaint['assigned_admin_id'] !== null
                && (int) $complaint['assigned_admin_id'] !== $assigneeId
                && !$confirmReassign
            ) {
                throw new AdminComplaintException(
                    'Complaint is already assigned. Send confirm_reassign=true to reassign.',
                    409
                );
            }
            if (
                $complaint['assigned_admin_id'] !== null
                && (int) $complaint['assigned_admin_id'] === $assigneeId
            ) {
                throw new AdminComplaintException('Complaint is already assigned to this admin.', 409);
            }

            $assignee = $this->repository->eligibleAssignee($assigneeId);
            if ($assignee === null) {
                throw new AdminComplaintException('Assignee must be an active admin.', 422);
            }
            if (
                (int) $assignee['user_ID'] === (int) $complaint['complainant_user_id']
                || (
                    $complaint['respondent_user_id'] !== null
                    && (int) $assignee['user_ID'] === (int) $complaint['respondent_user_id']
                )
            ) {
                throw new AdminComplaintException('Assignee cannot be a party to the complaint.', 422);
            }

            $this->repository->assign($id, $assigneeId);
            $details = 'Assigned to admin ' . $assigneeId . '.';
            $this->repository->addHistory(
                $id,
                (int) $actor['admin_ID'],
                'ASSIGNED',
                (string) $complaint['status'],
                (string) $complaint['status'],
                $details
            );
            $this->repository->notifyUsers(
                $id,
                [(int) $complaint['complainant_user_id'], (int) $assignee['user_ID']],
                'Complaint assigned',
                'Complaint #' . $id . ' has been assigned for review.'
            );

            return ['id' => $id, 'assigned_admin_id' => $assigneeId];
        });
    }

    public function updateStatus(int $id, array $body, array $actor): array
    {
        $status = $body['status'] ?? null;
        if (!is_string($status) || !in_array($status, AdminComplaintStatus::all(), true)) {
            throw new AdminComplaintException(
                'Status must be one of: ' . implode(', ', AdminComplaintStatus::all()) . '.',
                422
            );
        }
        if ($status === AdminComplaintStatus::RESOLVED) {
            throw new AdminComplaintException('Use the resolution action to resolve a complaint.', 422);
        }
        $remarks = $this->optionalString($body, 'remarks', 1000);

        return $this->transaction(function () use ($id, $status, $remarks, $actor): array {
            $complaint = $this->requireComplaint($id, true);
            $previous = (string) $complaint['status'];
            if (!AdminComplaintStatus::canTransition($previous, $status)) {
                throw new AdminComplaintException(
                    "Cannot change status from '{$previous}' to '{$status}'.",
                    409
                );
            }

            $this->repository->updateStatus($id, $status);
            $this->repository->addHistory(
                $id,
                (int) $actor['admin_ID'],
                'STATUS_CHANGED',
                $previous,
                $status,
                $remarks
            );
            $this->notifyParties($id, $complaint, 'Complaint status updated', $status);

            return ['id' => $id, 'previous_status' => $previous, 'status' => $status];
        });
    }

    public function resolve(int $id, array $body, array $actor): array
    {
        $resolution = $this->resolutionFields($body);

        return $this->transaction(function () use ($id, $resolution, $actor): array {
            $complaint = $this->requireComplaint($id, true);
            $previous = (string) $complaint['status'];
            if (!AdminComplaintStatus::canTransition($previous, AdminComplaintStatus::RESOLVED)) {
                throw new AdminComplaintException(
                    "Cannot resolve a complaint from status '{$previous}'.",
                    409
                );
            }

            $this->repository->resolve($id, $resolution['stored_notes']);
            $this->repository->addHistory(
                $id,
                (int) $actor['admin_ID'],
                'RESOLUTION_RECORDED',
                $previous,
                AdminComplaintStatus::RESOLVED,
                $resolution['resolution']
            );
            $this->notifyParties(
                $id,
                $complaint,
                'Complaint resolved',
                'The resolution has been recorded.'
            );

            return [
                'id' => $id,
                'status' => AdminComplaintStatus::RESOLVED,
                'resolved_at' => date('Y-m-d H:i:s'),
            ];
        });
    }

    private function notifyParties(int $id, array $complaint, string $title, string $message): void
    {
        $userIds = [(int) $complaint['complainant_user_id']];
        if ($complaint['respondent_user_id'] !== null) {
            $userIds[] = (int) $complaint['respondent_user_id'];
        }
        $this->repository->notifyUsers($id, $userIds, $title, 'Complaint #' . $id . ': ' . $message);
    }

    private function requireComplaint(int $id, bool $lock): array
    {
        $complaint = $this->repository->complaint($id, $lock);
        if ($complaint === null) {
            throw new AdminComplaintException('Complaint not found.', 404);
        }

        return $complaint;
    }

    private function resolutionFields(array $body): array
    {
        $limits = [
            'findings' => [true, 5000],
            'resolution' => [true, 5000],
            'action_taken' => [false, 2000],
            'admin_remarks' => [false, 2000],
        ];
        $values = [];
        $errors = [];
        foreach ($limits as $field => [$required, $maximum]) {
            $value = $body[$field] ?? null;
            if ($value !== null && !is_string($value)) {
                $errors[$field] = 'Must be text.';
                continue;
            }
            $value = $value === null ? '' : trim($value);
            if ($required && $value === '') {
                $errors[$field] = 'Required.';
            } elseif (mb_strlen($value) > $maximum) {
                $errors[$field] = 'Must not exceed ' . $maximum . ' characters.';
            } else {
                $values[$field] = $value;
            }
        }
        if ($errors !== []) {
            throw new AdminComplaintException('Validation failed.', 422, $errors);
        }

        $storedNotes = "Findings: {$values['findings']}\nResolution: {$values['resolution']}";
        if ($values['action_taken'] !== '') {
            $storedNotes .= "\nAction taken: " . $values['action_taken'];
        }
        if ($values['admin_remarks'] !== '') {
            $storedNotes .= "\nAdmin remarks: " . $values['admin_remarks'];
        }

        return ['stored_notes' => $storedNotes, 'resolution' => $values['resolution']];
    }

    private function optionalString(array $input, string $key, int $maximum): ?string
    {
        $value = $input[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > $maximum) {
            throw new AdminComplaintException($key . ' must be text up to ' . $maximum . ' characters.', 422);
        }

        return trim($value);
    }

    private function optionalStatus(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !in_array($value, AdminComplaintStatus::all(), true)) {
            throw new AdminComplaintException(
                'Invalid status filter. Allowed values: ' . implode(', ', AdminComplaintStatus::all()) . '.',
                422
            );
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
            throw new AdminComplaintException($key . ' must be a positive integer.', 422);
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
            throw new AdminComplaintException($key . ' must use YYYY-MM-DD format.', 422);
        }

        return $value;
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
}

final class AdminComplaintController
{
    private const MAX_BODY_SIZE = 16384;

    public function __construct(
        private AdminComplaintService $service,
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
        } catch (AdminComplaintException $error) {
            $this->respond($error->httpStatus, [
                'success' => false,
                'data' => null,
                'error' => [
                    'message' => $error->getMessage(),
                    ...($error->details === [] ? [] : ['details' => $error->details]),
                ],
            ]);
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                $this->respond(409, [
                    'success' => false,
                    'data' => null,
                    'error' => ['message' => 'The requested change conflicts with existing data.'],
                ]);
            }
            error_log('[admin complaints] ' . $error->getMessage());
            $this->respond(503, [
                'success' => false,
                'data' => null,
                'error' => ['message' => 'Complaint service is temporarily unavailable.'],
            ]);
        } catch (Throwable $error) {
            error_log('[admin complaints] ' . $error->getMessage());
            $this->respond(500, [
                'success' => false,
                'data' => null,
                'error' => ['message' => 'The complaint request could not be completed.'],
            ]);
        }
    }

    private function dispatch(array $admin): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $action = $_GET['action'] ?? 'list';
            if (!is_string($action)) {
                throw new AdminComplaintException('Invalid complaint action.', 400);
            }
            if ($action === 'csrf') {
                if (empty($_SESSION['csrf_token'])) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }

                return ['csrf_token' => $_SESSION['csrf_token']];
            }

            return match ($action) {
                'list' => $this->service->search($_GET, false),
                'search' => $this->service->search($_GET, true),
                'details' => $this->service->details(
                    $this->queryId($_GET['id'] ?? null)
                ),
                'notifications' => $this->service->notifications(
                    $this->queryId($_GET['id'] ?? null)
                ),
                default => throw new AdminComplaintException('Unknown complaint action.', 404),
            };
        }

        if ($method !== 'POST') {
            header('Allow: GET, POST');
            throw new AdminComplaintException('Method not allowed.', 405);
        }
        $body = $this->readBody();
        $this->requireCsrf($body);
        $id = $this->queryId($body['complaint_id'] ?? null);
        $action = $body['action'] ?? '';
        if (!is_string($action)) {
            throw new AdminComplaintException('Invalid complaint action.', 400);
        }

        return match ($action) {
            'assign' => $this->service->assign($id, $body, $admin),
            'status' => $this->service->updateStatus($id, $body, $admin),
            'resolution' => $this->service->resolve($id, $body, $admin),
            default => throw new AdminComplaintException('Unknown complaint action.', 404),
        };
    }

    private function readBody(): array
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_SIZE) {
            throw new AdminComplaintException('Request is too large.', 413);
        }
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType === 'application/json') {
            $body = json_decode(file_get_contents('php://input') ?: '', true);
            if (!is_array($body)) {
                throw new AdminComplaintException('Request body must be valid JSON.', 400);
            }

            return $body;
        }
        if ($contentType === 'application/x-www-form-urlencoded') {
            return $_POST;
        }

        throw new AdminComplaintException('Send a JSON request.', 415);
    }

    private function requireCsrf(array $body): void
    {
        $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $body['csrf_token'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (
            !is_string($submitted)
            || !is_string($expected)
            || $expected === ''
            || !hash_equals($expected, $submitted)
        ) {
            throw new AdminComplaintException('Invalid or missing CSRF token.', 403);
        }
    }

    private function queryId(mixed $value): int
    {
        if (
            (!is_int($value) && !is_string($value))
            || preg_match('/^[1-9]\d*$/', (string) $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT) === false
        ) {
            throw new AdminComplaintException('A valid complaint ID is required.', 422);
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
$repository = new AdminComplaintRepository($database);
$service = new AdminComplaintService($database, $repository);
$auth = new AdminAuthMiddleware(new AdminAuthService($database, new SessionManager()));

(new AdminComplaintController($service, $auth))->run();
