<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

class AdminUserManagement
{
    private PDO $db;
    private int $adminId;

    public function __construct(PDO $db, int $adminId)
    {
        $this->db = $db;
        $this->adminId = $adminId;
    }

    // 1. Retrieve Users
    public function retrieveUsers(): array
    {
        $sql = "SELECT
                    u.user_ID,
                    u.user_Email,
                    u.user_Role,
                    u.user_Status,
                    u.user_CreatedAt,
                    c.customer_ID,
                    c.customer_FN,
                    c.customer_LN,
                    s.seller_ID,
                    s.seller_FN,
                    s.seller_LN,
                    s.seller_StoreName,
                    CASE
                        WHEN u.user_Role = 'Customer'
                            THEN CONCAT_WS(' ', c.customer_FN, c.customer_LN)
                        WHEN u.user_Role = 'Seller'
                            THEN CONCAT_WS(' ', s.seller_FN, s.seller_LN)
                    END AS full_Name
                FROM USERS u
                LEFT JOIN CUSTOMERS c ON c.user_ID = u.user_ID
                LEFT JOIN SELLERS s ON s.user_ID = u.user_ID
                WHERE u.user_Role IN ('Customer', 'Seller')
                ORDER BY u.user_CreatedAt DESC";

        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    // 2. Search and Filter Users
    public function searchUsers(array $filters): array
    {
        $conditions = ["u.user_Role IN ('Customer', 'Seller')"];
        $parameters = [];

        if (!empty($filters['name'])) {
            $conditions[] = "CONCAT_WS(' ', c.customer_FN, c.customer_LN,
                s.seller_FN, s.seller_LN, s.seller_StoreName) LIKE :name";

            $parameters[':name'] = '%' . trim($filters['name']) . '%';
        }

        if (!empty($filters['email'])) {
            $conditions[] = 'u.user_Email LIKE :email';
            $parameters[':email'] = '%' . trim($filters['email']) . '%';
        }

        if (!empty($filters['role'])) {
            if (!in_array($filters['role'], ['Customer', 'Seller'], true)) {
                throw new InvalidArgumentException('Invalid user role.');
            }

            $conditions[] = 'u.user_Role = :role';
            $parameters[':role'] = $filters['role'];
        }

        if (!empty($filters['status'])) {
            if (!in_array($filters['status'], ['Active', 'Inactive', 'Suspended'], true)) {
                throw new InvalidArgumentException('Invalid account status.');
            }

            $conditions[] = 'u.user_Status = :status';
            $parameters[':status'] = $filters['status'];
        }

        $sql = "SELECT
                    u.user_ID,
                    u.user_Email,
                    u.user_Role,
                    u.user_Status,
                    u.user_CreatedAt,
                    c.customer_ID,
                    s.seller_ID,
                    CASE
                        WHEN u.user_Role = 'Customer'
                            THEN CONCAT_WS(' ', c.customer_FN, c.customer_LN)
                        WHEN u.user_Role = 'Seller'
                            THEN CONCAT_WS(' ', s.seller_FN, s.seller_LN)
                    END AS full_Name,
                    s.seller_StoreName
                FROM USERS u
                LEFT JOIN CUSTOMERS c ON c.user_ID = u.user_ID
                LEFT JOIN SELLERS s ON s.user_ID = u.user_ID
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY u.user_CreatedAt DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($parameters);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 3. View User Details
    public function viewUserDetails(int $userId): array
    {
        $user = $this->findUser($userId);

        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        $profile = null;
        $transactions = [];

        if ($user['user_Role'] === 'Customer') {

            $profileStmt = $this->db->prepare(
                "SELECT *
                 FROM CUSTOMER_PROFILE
                 WHERE customer_ID = :customer_ID
                 ORDER BY updated_at DESC, profile_ID DESC
                 LIMIT 1"
            );

            $profileStmt->execute([
                ':customer_ID' => $user['customer_ID']
            ]);

            $profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $orderStmt = $this->db->prepare(
                "SELECT *
                 FROM ORDERS
                 WHERE customer_ID = :customer_ID
                 ORDER BY order_Date DESC"
            );

            $orderStmt->execute([
                ':customer_ID' => $user['customer_ID']
            ]);

            $transactions = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

        } else {

            $orderStmt = $this->db->prepare(
                "SELECT *
                 FROM ORDERS
                 WHERE seller_ID = :seller_ID
                 ORDER BY order_Date DESC"
            );

            $orderStmt->execute([
                ':seller_ID' => $user['seller_ID']
            ]);

            $transactions = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'account' => $user,
            'profile' => $profile,
            'transactions' => $transactions
        ];
    }

    // 4. Update User
    public function updateUser(int $userId, array $data): void
    {
        $this->db->beginTransaction();

        try {
            $user = $this->findUser($userId, true);

            if (!$user) {
                throw new RuntimeException('User not found.');
            }

            $updatedFields = [];

            if (array_key_exists('user_Email', $data)) {
                $email = trim((string) $data['user_Email']);

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Invalid email address.');
                }

                $stmt = $this->db->prepare(
                    "UPDATE USERS
                     SET user_Email = :email
                     WHERE user_ID = :user_ID"
                );

                $stmt->execute([
                    ':email' => $email,
                    ':user_ID' => $userId
                ]);

                $updatedFields[] = 'user_Email';
            }

            if ($user['user_Role'] === 'Customer') {

                $table = 'CUSTOMERS';
                $idColumn = 'customer_ID';
                $recordId = (int) $user['customer_ID'];

                $allowedFields = [
                    'customer_FN',
                    'customer_LN',
                    'customer_Phone',
                    'customer_Address'
                ];

            } else {

                $table = 'SELLERS';
                $idColumn = 'seller_ID';
                $recordId = (int) $user['seller_ID'];

                $allowedFields = [
                    'seller_Email',
                    'seller_FN',
                    'seller_LN',
                    'seller_Phone',
                    'seller_City',
                    'seller_Province',
                    'seller_Region',
                    'seller_StoreName'
                ];
            }

            $setParts = [];
            $parameters = [];

            foreach ($allowedFields as $field) {

                if (!array_key_exists($field, $data)) {
                    continue;
                }

                $value = trim((string) $data[$field]);

                $requiredFields = [
                    'customer_FN',
                    'customer_LN',
                    'seller_FN',
                    'seller_LN',
                    'seller_StoreName'
                ];

                if (in_array($field, $requiredFields, true) && $value === '') {
                    throw new InvalidArgumentException(
                        $field . ' cannot be empty.'
                    );
                }

                if ($field === 'seller_Email' &&
                    !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Invalid seller email.');
                }

                $setParts[] = "$field = :$field";
                $parameters[":$field"] = $value;

                $updatedFields[] = $field;
            }

            if (!empty($setParts)) {

                $parameters[':record_ID'] = $recordId;

                $sql = "UPDATE $table
                        SET " . implode(', ', $setParts) . "
                        WHERE $idColumn = :record_ID";

                $stmt = $this->db->prepare($sql);
                $stmt->execute($parameters);
            }

            if (empty($updatedFields)) {
                throw new InvalidArgumentException(
                    'No information was provided for updating.'
                );
            }

            $this->recordAdminAction(
                'Update User',
                'Updated fields: ' . implode(', ', $updatedFields),
                'USERS',
                $userId
            );

            $this->db->commit();

        } catch (Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    // 5 and 6. Suspend and Reactivate User
    public function changeUserStatus(int $userId, string $newStatus): void
    {
        if (!in_array($newStatus, ['Active', 'Suspended'], true)) {
            throw new InvalidArgumentException('Invalid account status.');
        }

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                "SELECT user_ID, user_Status
                 FROM USERS
                 WHERE user_ID = :user_ID
                 AND user_Role IN ('Customer', 'Seller')
                 FOR UPDATE"
            );

            $stmt->execute([
                ':user_ID' => $userId
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new RuntimeException('User not found.');
            }

            $currentStatus = $user['user_Status'];

            if ($newStatus === 'Suspended' && $currentStatus !== 'Active') {
                throw new RuntimeException(
                    'Only active accounts can be suspended.'
                );
            }

            if (
                $newStatus === 'Active' &&
                !in_array($currentStatus, ['Suspended', 'Inactive'], true)
            ) {
                throw new RuntimeException(
                    'This account is already active.'
                );
            }

            $update = $this->db->prepare(
                "UPDATE USERS
                 SET user_Status = :status
                 WHERE user_ID = :user_ID"
            );

            $update->execute([
                ':status' => $newStatus,
                ':user_ID' => $userId
            ]);

            $action = $newStatus === 'Suspended'
                ? 'Suspend User'
                : 'Reactivate User';

            $this->recordAdminAction(
                $action,
                "Account status changed from $currentStatus to $newStatus",
                'USERS',
                $userId
            );

            $this->db->commit();

        } catch (Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    // 7. User Activity Retrieval
    public function retrieveUserActivity(int $userId): array
    {
        $check = $this->db->prepare(
            "SELECT user_ID
             FROM USERS
             WHERE user_ID = :user_ID"
        );

        $check->execute([
            ':user_ID' => $userId
        ]);

        if (!$check->fetchColumn()) {
            throw new RuntimeException('User not found.');
        }

        $stmt = $this->db->prepare(
            "SELECT *
             FROM ACTIVITY_LOGS
             WHERE user_ID = :user_ID
             ORDER BY activity_Date DESC"
        );

        $stmt->execute([
            ':user_ID' => $userId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Find customer or seller account.
    private function findUser(int $userId, bool $lock = false): array|false
    {
        $sql = "SELECT
                    u.user_ID,
                    u.user_Email,
                    u.user_Role,
                    u.user_Status,
                    u.user_CreatedAt,
                    c.customer_ID,
                    c.customer_FN,
                    c.customer_LN,
                    c.customer_Phone,
                    c.customer_Address,
                    s.seller_ID,
                    s.seller_Email,
                    s.seller_FN,
                    s.seller_LN,
                    s.seller_Phone,
                    s.seller_City,
                    s.seller_Province,
                    s.seller_Region,
                    s.seller_StoreName
                FROM USERS u
                LEFT JOIN CUSTOMERS c ON c.user_ID = u.user_ID
                LEFT JOIN SELLERS s ON s.user_ID = u.user_ID
                WHERE u.user_ID = :user_ID
                AND u.user_Role IN ('Customer', 'Seller')";

        if ($lock) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':user_ID' => $userId
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 9. Administrative Action Logging
    private function recordAdminAction(
        string $actionType,
        string $description,
        string $targetTable,
        int $targetId
    ): void {

        $stmt = $this->db->prepare(
            "INSERT INTO ADMIN_ACTIVITY_LOGS
                (admin_ID, action_Type, action_Description,
                 target_Table, target_ID, ip_address)
             VALUES
                (:admin_ID, :action_Type, :description,
                 :target_Table, :target_ID, :ip_address)"
        );

        $stmt->execute([
            ':admin_ID' => $this->adminId,
            ':action_Type' => $actionType,
            ':description' => $description,
            ':target_Table' => $targetTable,
            ':target_ID' => $targetId,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ]);
    }
}


// API REQUEST HANDLING

try {

    // Use the existing project database connection.
    $db = function_exists('db') ? db() : ($pdo ?? null);

    if (!$db instanceof PDO) {
        throw new RuntimeException('Database connection not available.');
    }

    // Verify the logged-in administrator.
    $adminId = (int) ($_SESSION['admin_ID'] ?? 0);
    $sessionUserId = (int) ($_SESSION['user_ID'] ?? 0);

    if ($adminId > 0) {

        $adminStmt = $db->prepare(
            "SELECT a.admin_ID
             FROM ADMINS a
             INNER JOIN USERS u ON u.user_ID = a.user_ID
             WHERE a.admin_ID = :admin_ID
             AND a.admin_Status = 'Active'
             AND u.user_Status = 'Active'
             AND u.user_Role = 'Admin'"
        );

        $adminStmt->execute([
            ':admin_ID' => $adminId
        ]);

    } elseif ($sessionUserId > 0) {

        $adminStmt = $db->prepare(
            "SELECT a.admin_ID
             FROM ADMINS a
             INNER JOIN USERS u ON u.user_ID = a.user_ID
             WHERE u.user_ID = :user_ID
             AND a.admin_Status = 'Active'
             AND u.user_Status = 'Active'
             AND u.user_Role = 'Admin'"
        );

        $adminStmt->execute([
            ':user_ID' => $sessionUserId
        ]);

    } else {

        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'Admin login is required.'
        ]);

        exit;
    }

    $authenticatedAdminId = (int) $adminStmt->fetchColumn();

    if ($authenticatedAdminId <= 0) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Administrator access denied.'
        ]);

        exit;
    }

    $userManagement = new AdminUserManagement(
        $db,
        $authenticatedAdminId
    );

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $action = strtolower(trim($_GET['action'] ?? ''));

    $userId = filter_var(
        $_GET['user_ID'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($method === 'GET' && $action === 'retrieve') {

        $result = $userManagement->retrieveUsers();

    } elseif ($method === 'GET' && $action === 'search') {

        $result = $userManagement->searchUsers($_GET);

    } elseif (
        $method === 'GET' &&
        $action === 'details' &&
        $userId !== false &&
        $userId > 0
    ) {

        $result = $userManagement->viewUserDetails((int) $userId);

    } elseif (
        $method === 'GET' &&
        $action === 'activity' &&
        $userId !== false &&
        $userId > 0
    ) {

        $result = $userManagement->retrieveUserActivity((int) $userId);

    } elseif (
        $method === 'PUT' &&
        $action === 'update' &&
        $userId !== false &&
        $userId > 0
    ) {

        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON request.');
        }

        $userManagement->updateUser((int) $userId, $data);

        $result = [
            'message' => 'User information updated successfully.'
        ];

    } elseif (
        $method === 'PATCH' &&
        $action === 'suspend' &&
        $userId !== false &&
        $userId > 0
    ) {

        $userManagement->changeUserStatus(
            (int) $userId,
            'Suspended'
        );

        $result = [
            'message' => 'User successfully suspended.'
        ];

    } elseif (
        $method === 'PATCH' &&
        $action === 'reactivate' &&
        $userId !== false &&
        $userId > 0
    ) {

        $userManagement->changeUserStatus(
            (int) $userId,
            'Active'
        );

        $result = [
            'message' => 'User successfully reactivated.'
        ];

    } else {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid API action, method, or user ID.'
        ]);

        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => $result
    ]);

} catch (InvalidArgumentException $e) {

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

} catch (RuntimeException $e) {

    http_response_code(404);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred.'
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to process the request.'
    ]);
}