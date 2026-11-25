<?php
declare(strict_types=1);

require_once __DIR__ . '/../Backend/database.php';
require_once __DIR__ . '/AdminAuth.php';

final class AdminCatalogException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

final class AdminCatalogRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function categories(): array
    {
        $query = $this->database->query("
            SELECT c.categ_ID AS id,
                   c.categ_name AS name,
                   c.categ_description AS description,
                   COUNT(p.prod_ID) AS product_count
            FROM PRODUCT_CATEGORIES c
            LEFT JOIN PRODUCTS p ON p.categ_ID = c.categ_ID
            GROUP BY c.categ_ID, c.categ_name, c.categ_description
            ORDER BY c.categ_name, c.categ_ID
        ");

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function products(array $filters, int $page, int $pageSize): array
    {
        $where = [];
        $parameters = [];
        if ($filters['keyword'] !== '') {
            $where[] = '(p.prod_name LIKE :product_keyword OR s.seller_StoreName LIKE :store_keyword OR c.categ_name LIKE :category_keyword)';
            $needle = '%' . $filters['keyword'] . '%';
            $parameters['product_keyword'] = $needle;
            $parameters['store_keyword'] = $needle;
            $parameters['category_keyword'] = $needle;
        }
        if ($filters['seller_id'] !== null) {
            $where[] = 'p.seller_ID = :seller_id';
            $parameters['seller_id'] = $filters['seller_id'];
        }
        if ($filters['category_id'] !== null) {
            $where[] = 'p.categ_ID = :category_id';
            $parameters['category_id'] = $filters['category_id'];
        }
        if ($filters['condition'] !== null) {
            $where[] = 'p.prod_condition = :product_condition';
            $parameters['product_condition'] = $filters['condition'];
        }
        if ($filters['availability'] !== null) {
            $where[] = 'p.availability_type = :availability';
            $parameters['availability'] = $filters['availability'];
        }
        if ($filters['status'] !== null) {
            $where[] = "COALESCE(ps.listing_Status, 'Active') = :listing_status";
            $parameters['listing_status'] = $filters['status'];
        }
        if ($filters['include_deleted'] !== true) {
            $where[] = 'COALESCE(p.is_deleted, 0) = 0';
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $count = $this->database->prepare("
            SELECT COUNT(*)
            FROM PRODUCTS p
            INNER JOIN SELLERS s ON s.seller_ID = p.seller_ID
            INNER JOIN PRODUCT_CATEGORIES c ON c.categ_ID = p.categ_ID
            LEFT JOIN SELLER_PRODUCT_SETTINGS ps ON ps.prod_ID = p.prod_ID
            {$whereSql}
        ");
        $this->bind($count, $parameters);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $query = $this->database->prepare("
            SELECT p.prod_ID AS id,
                   p.prod_name AS name,
                   p.prod_description AS description,
                   p.prod_price AS price,
                   p.prod_condition AS `condition`,
                   p.availability_type AS availability,
                   p.is_deleted,
                   p.created_at,
                   c.categ_ID AS category_id,
                   c.categ_name AS category_name,
                   s.seller_ID AS seller_id,
                   s.seller_StoreName AS seller_name,
                   COALESCE(ps.listing_Status, 'Active') AS status,
                   COALESCE(stock.quantity, 0) AS quantity,
                   (SELECT pi.img_URL
                      FROM PRODUCT_IMAGES pi
                      LEFT JOIN SELLER_PRODUCT_IMAGE_SETTINGS psi ON psi.img_ID = pi.img_ID
                     WHERE pi.prod_ID = p.prod_ID
                     ORDER BY COALESCE(psi.display_Order, pi.img_ID), pi.img_ID
                     LIMIT 1) AS image
            FROM PRODUCTS p
            INNER JOIN SELLERS s ON s.seller_ID = p.seller_ID
            INNER JOIN PRODUCT_CATEGORIES c ON c.categ_ID = p.categ_ID
            LEFT JOIN SELLER_PRODUCT_SETTINGS ps ON ps.prod_ID = p.prod_ID
            LEFT JOIN (
                SELECT prod_ID, SUM(inventory_QTY) AS quantity
                FROM INVENTORY
                GROUP BY prod_ID
            ) stock ON stock.prod_ID = p.prod_ID
            {$whereSql}
            ORDER BY p.created_at DESC, p.prod_ID DESC
            LIMIT :row_limit OFFSET :row_offset
        ");
        $this->bind($query, $parameters);
        $query->bindValue(':row_limit', $pageSize, PDO::PARAM_INT);
        $query->bindValue(':row_offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $query->execute();

        return [
            'items' => $query->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $pageSize)),
            ],
        ];
    }

    public function product(int $productId): ?array
    {
        $query = $this->database->prepare("
            SELECT p.prod_ID AS id,
                   p.seller_ID AS seller_id,
                   p.prod_name AS name,
                   p.prod_description AS description,
                   p.prod_price AS price,
                   p.prod_condition AS `condition`,
                   p.availability_type AS availability,
                   p.categ_ID AS category_id,
                   p.is_deleted,
                   p.created_at,
                   c.categ_name AS category_name,
                   s.seller_StoreName AS seller_name,
                   s.user_ID AS seller_user_id,
                   LOWER(TRIM(u.user_Role)) AS seller_role,
                   LOWER(TRIM(u.user_Status)) AS seller_status,
                   COALESCE(ps.listing_Status, 'Active') AS status
            FROM PRODUCTS p
            INNER JOIN PRODUCT_CATEGORIES c ON c.categ_ID = p.categ_ID
            INNER JOIN SELLERS s ON s.seller_ID = p.seller_ID
            INNER JOIN USERS u ON u.user_ID = s.user_ID
            LEFT JOIN SELLER_PRODUCT_SETTINGS ps ON ps.prod_ID = p.prod_ID
            WHERE p.prod_ID = :product_id
            LIMIT 1
        ");
        $query->execute(['product_id' => $productId]);
        $product = $query->fetch(PDO::FETCH_ASSOC);
        if ($product === false) {
            return null;
        }

        $images = $this->database->prepare("
            SELECT pi.img_ID AS id,
                   pi.img_URL AS url,
                   COALESCE(psi.display_Order, pi.img_ID) AS display_order
            FROM PRODUCT_IMAGES pi
            LEFT JOIN SELLER_PRODUCT_IMAGE_SETTINGS psi ON psi.img_ID = pi.img_ID
            WHERE pi.prod_ID = :product_id
            ORDER BY COALESCE(psi.display_Order, pi.img_ID), pi.img_ID
        ");
        $images->execute(['product_id' => $productId]);
        $product['images'] = $images->fetchAll(PDO::FETCH_ASSOC);

        $inventory = $this->database->prepare("
            SELECT inventory_ID AS id,
                   inventory_QTY AS quantity,
                   inventory_Reserved AS reserved,
                   availability_Status AS status,
                   region,
                   province,
                   city_municipality AS city
            FROM INVENTORY
            WHERE prod_ID = :product_id
            ORDER BY region, province, city_municipality, inventory_ID
        ");
        $inventory->execute(['product_id' => $productId]);
        $product['inventory'] = $inventory->fetchAll(PDO::FETCH_ASSOC);

        return $product;
    }

    public function categoryExists(int $categoryId): bool
    {
        $query = $this->database->prepare(
            'SELECT 1 FROM PRODUCT_CATEGORIES WHERE categ_ID = :category_id LIMIT 1'
        );
        $query->execute(['category_id' => $categoryId]);

        return $query->fetchColumn() !== false;
    }

    public function categoryNameExists(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM PRODUCT_CATEGORIES WHERE categ_name = :name';
        $parameters = ['name' => $name];
        if ($exceptId !== null) {
            $sql .= ' AND categ_ID <> :except_id';
            $parameters['except_id'] = $exceptId;
        }
        $query = $this->database->prepare($sql . ' LIMIT 1');
        $query->execute($parameters);

        return $query->fetchColumn() !== false;
    }

    public function createCategory(string $name, ?string $description): int
    {
        $query = $this->database->prepare("
            INSERT INTO PRODUCT_CATEGORIES (categ_name, categ_description)
            VALUES (:name, :description)
        ");
        $query->execute(['name' => $name, 'description' => $description]);

        return (int) $this->database->lastInsertId();
    }

    public function updateCategory(int $categoryId, ?string $name, ?string $description, bool $updateDescription): void
    {
        $query = $this->database->prepare("
            UPDATE PRODUCT_CATEGORIES
            SET categ_name = COALESCE(:name, categ_name),
                categ_description = CASE WHEN :update_description = 1
                    THEN :description ELSE categ_description END
            WHERE categ_ID = :category_id
        ");
        $query->execute([
            'name' => $name,
            'update_description' => $updateDescription ? 1 : 0,
            'description' => $description,
            'category_id' => $categoryId,
        ]);
    }

    public function deleteCategory(int $categoryId): void
    {
        $query = $this->database->prepare(
            'DELETE FROM PRODUCT_CATEGORIES WHERE categ_ID = :category_id'
        );
        $query->execute(['category_id' => $categoryId]);
    }

    public function productCountForCategory(int $categoryId): int
    {
        $query = $this->database->prepare(
            'SELECT COUNT(*) FROM PRODUCTS WHERE categ_ID = :category_id'
        );
        $query->execute(['category_id' => $categoryId]);

        return (int) $query->fetchColumn();
    }

    public function updateProductStatus(int $productId, string $status): void
    {
        $query = $this->database->prepare("
            INSERT INTO SELLER_PRODUCT_SETTINGS (prod_ID, listing_Status)
            VALUES (:product_id, :status)
            ON DUPLICATE KEY UPDATE listing_Status = VALUES(listing_Status)
        ");
        $query->execute(['product_id' => $productId, 'status' => $status]);
    }

    public function hasOpenOrders(int $productId): bool
    {
        $query = $this->database->prepare("
            SELECT 1
            FROM order_items oi
            INNER JOIN ORDERS o ON o.order_ID = oi.order_ID
            WHERE oi.product_ID = :product_id
              AND o.order_Status IN ('Pending', 'Confirmed', 'Processing', 'Shipped')
            LIMIT 1
        ");
        $query->execute(['product_id' => $productId]);

        return $query->fetchColumn() !== false;
    }

    public function softDeleteProduct(int $productId): void
    {
        $query = $this->database->prepare("
            UPDATE PRODUCTS
            SET is_deleted = 1, deleted_at = NOW()
            WHERE prod_ID = :product_id AND COALESCE(is_deleted, 0) = 0
        ");
        $query->execute(['product_id' => $productId]);
    }

    public function notifySeller(int $userId, int $productId, string $reason): void
    {
        $query = $this->database->prepare("
            INSERT INTO NOTIFICATIONS
                (user_ID, prod_ID, notification_Type, notification_Title, notification_Message)
            VALUES
                (:user_id, :product_id, 'Product', 'Product removed', :message)
        ");
        $query->execute([
            'user_id' => $userId,
            'product_id' => $productId,
            'message' => 'An administrator removed your product. Reason: ' . $reason,
        ]);
    }

    public function logAction(
        int $adminId,
        string $action,
        string $description,
        ?string $table,
        ?int $targetId
    ): void {
        $query = $this->database->prepare("
            INSERT INTO ADMIN_ACTIVITY_LOGS
                (admin_ID, action_Type, action_Description, target_Table, target_ID, ip_address)
            VALUES
                (:admin_id, :action_type, :description, :target_table, :target_id, :ip_address)
        ");
        $query->execute([
            'admin_id' => $adminId,
            'action_type' => $action,
            'description' => $description,
            'target_table' => $table,
            'target_id' => $targetId,
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

final class AdminCatalogService
{
    private const PRODUCT_STATUSES = ['Active', 'Draft', 'Archived'];
    private const CONDITIONS = ['New', 'Used', 'Refurbished'];
    private const AVAILABILITY = ['In Stock', 'Out of Stock', 'Available for Pre-order'];

    public function __construct(
        private PDO $database,
        private AdminCatalogRepository $repository
    ) {
    }

    public function listCategories(): array
    {
        return ['categories' => $this->repository->categories()];
    }

    public function listProducts(array $query): array
    {
        $filters = [
            'keyword' => $this->optionalString($query, 'keyword', 150),
            'seller_id' => $this->optionalPositiveId($query, 'seller_id'),
            'category_id' => $this->optionalPositiveId($query, 'category_id'),
            'condition' => $this->optionalChoice($query, 'condition', self::CONDITIONS),
            'availability' => $this->optionalChoice($query, 'availability', self::AVAILABILITY),
            'status' => $this->optionalChoice($query, 'status', self::PRODUCT_STATUSES),
            'include_deleted' => $this->booleanOption($query, 'include_deleted'),
        ];
        [$page, $pageSize] = $this->pagination($query);

        return $this->repository->products($filters, $page, $pageSize);
    }

    public function productDetails(array $query): array
    {
        $productId = $this->requiredPositiveId($query, 'id');
        $product = $this->repository->product($productId);
        if ($product === null) {
            throw new AdminCatalogException('Product not found.', 404);
        }

        return ['product' => $product];
    }

    public function createCategory(array $input, array $admin): array
    {
        $name = $this->categoryName($input['name'] ?? null);
        $description = $this->description($input['description'] ?? '');
        if ($this->repository->categoryNameExists($name)) {
            throw new AdminCatalogException('Category name already exists.', 409);
        }

        return $this->transaction(function () use ($name, $description, $admin): array {
            $categoryId = $this->repository->createCategory($name, $description);
            $this->repository->logAction(
                (int) $admin['admin_ID'],
                'CATEGORY_CREATE',
                'Created category "' . $name . '".',
                'PRODUCT_CATEGORIES',
                $categoryId
            );

            return ['categoryId' => $categoryId, 'message' => 'Category created.'];
        });
    }

    public function updateCategory(array $input, array $admin): array
    {
        $categoryId = $this->requiredPositiveId($input, 'category_id');
        if (!$this->repository->categoryExists($categoryId)) {
            throw new AdminCatalogException('Category not found.', 404);
        }

        $name = null;
        if (array_key_exists('name', $input)) {
            $name = $this->categoryName($input['name']);
            if ($this->repository->categoryNameExists($name, $categoryId)) {
                throw new AdminCatalogException('Category name already exists.', 409);
            }
        }
        $updateDescription = array_key_exists('description', $input);
        $description = $updateDescription ? $this->description($input['description']) : null;
        if ($name === null && !$updateDescription) {
            throw new AdminCatalogException('Provide a category name or description to update.', 422);
        }

        return $this->transaction(function () use (
            $categoryId,
            $name,
            $description,
            $updateDescription,
            $admin
        ): array {
            $this->repository->updateCategory(
                $categoryId,
                $name,
                $description,
                $updateDescription
            );
            $this->repository->logAction(
                (int) $admin['admin_ID'],
                'CATEGORY_UPDATE',
                'Updated category ' . $categoryId . '.',
                'PRODUCT_CATEGORIES',
                $categoryId
            );

            return ['message' => 'Category updated.'];
        });
    }

    public function deleteCategory(array $input, array $admin): array
    {
        $categoryId = $this->requiredPositiveId($input, 'category_id');
        if (!$this->repository->categoryExists($categoryId)) {
            throw new AdminCatalogException('Category not found.', 404);
        }
        $productCount = $this->repository->productCountForCategory($categoryId);
        if ($productCount > 0) {
            throw new AdminCatalogException(
                'Category cannot be deleted while products use it. Reassign or remove those products first.',
                409
            );
        }

        return $this->transaction(function () use ($categoryId, $admin): array {
            $this->repository->deleteCategory($categoryId);
            $this->repository->logAction(
                (int) $admin['admin_ID'],
                'CATEGORY_DELETE',
                'Deleted category ' . $categoryId . '.',
                'PRODUCT_CATEGORIES',
                $categoryId
            );

            return ['message' => 'Category deleted.'];
        });
    }

    public function updateProductStatus(array $input, array $admin): array
    {
        $productId = $this->requiredPositiveId($input, 'product_id');
        $status = $input['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::PRODUCT_STATUSES, true)) {
            throw new AdminCatalogException(
                'Status must be Active, Draft, or Archived.',
                422
            );
        }
        $product = $this->repository->product($productId);
        if ($product === null || (int) $product['is_deleted'] === 1) {
            throw new AdminCatalogException('Product not found.', 404);
        }
        if ($product['status'] === $status) {
            throw new AdminCatalogException('Product already has this status.', 409);
        }
        $reason = $this->reason($input['reason'] ?? null, $status !== 'Active');
        if (
            $status === 'Active'
            && (
                $product['seller_role'] !== 'seller'
                || $product['seller_status'] !== 'active'
                || !$this->repository->categoryExists((int) $product['category_id'])
            )
        ) {
            throw new AdminCatalogException(
                'Cannot activate a product unless its seller account and category are available.',
                409
            );
        }

        return $this->transaction(function () use ($productId, $status, $reason, $admin): array {
            $this->repository->updateProductStatus($productId, $status);
            $this->repository->logAction(
                (int) $admin['admin_ID'],
                'PRODUCT_STATUS',
                'Changed product ' . $productId . ' listing status to ' . $status
                    . ($reason === null ? '.' : '. Reason: ' . $reason),
                'PRODUCTS',
                $productId
            );

            return ['productId' => $productId, 'status' => $status, 'message' => 'Product status updated.'];
        });
    }

    public function removeProduct(array $input, array $admin): array
    {
        $productId = $this->requiredPositiveId($input, 'product_id');
        $reason = $this->reason($input['reason'] ?? null, true);
        $product = $this->repository->product($productId);
        if ($product === null) {
            throw new AdminCatalogException('Product not found.', 404);
        }
        if ((int) $product['is_deleted'] === 1) {
            throw new AdminCatalogException('Product already removed.', 409);
        }
        if ($this->repository->hasOpenOrders($productId)) {
            throw new AdminCatalogException('Product has open orders; resolve them first.', 409);
        }

        return $this->transaction(function () use ($productId, $product, $reason, $admin): array {
            $this->repository->softDeleteProduct($productId);
            $this->repository->logAction(
                (int) $admin['admin_ID'],
                'PRODUCT_REMOVE',
                'Removed product ' . $productId . '. Reason: ' . $reason,
                'PRODUCTS',
                $productId
            );
            $this->repository->notifySeller(
                (int) $product['seller_user_id'],
                $productId,
                (string) $reason
            );

            return ['productId' => $productId, 'message' => 'Product removed.'];
        });
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

    private function categoryName(mixed $value): string
    {
        if (!is_string($value)) {
            throw new AdminCatalogException('Category name is required.', 422);
        }
        $name = trim($value);
        $length = mb_strlen($name);
        if ($length < 2 || $length > 50) {
            throw new AdminCatalogException('Category name must be 2 to 50 characters.', 422);
        }

        return $name;
    }

    private function description(mixed $value): ?string
    {
        if (!is_string($value)) {
            throw new AdminCatalogException('Category description must be text.', 422);
        }
        $description = trim($value);
        if (mb_strlen($description) > 255) {
            throw new AdminCatalogException('Category description must not exceed 255 characters.', 422);
        }

        return $description === '' ? null : $description;
    }

    private function reason(mixed $value, bool $required): ?string
    {
        if ($value === null && !$required) {
            return null;
        }
        if (!is_string($value)) {
            throw new AdminCatalogException('A reason is required.', 422);
        }
        $reason = trim($value);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new AdminCatalogException('Reason must be 1 to 255 characters.', 422);
        }

        return $reason;
    }

    private function optionalString(array $input, string $key, int $maxLength): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value) || mb_strlen(trim($value)) > $maxLength) {
            throw new AdminCatalogException('Invalid ' . $key . ' filter.', 422);
        }

        return trim($value);
    }

    private function optionalPositiveId(array $input, string $key): ?int
    {
        if (!isset($input[$key]) || $input[$key] === '') {
            return null;
        }

        return $this->requiredPositiveId($input, $key);
    }

    private function requiredPositiveId(array $input, string $key): int
    {
        $value = $input[$key] ?? null;
        if (
            (!is_int($value) && !is_string($value))
            || preg_match('/^[1-9]\d*$/', (string) $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT) === false
        ) {
            throw new AdminCatalogException('A valid ' . $key . ' is required.', 422);
        }

        return (int) $value;
    }

    private function optionalChoice(array $input, string $key, array $allowed): ?string
    {
        if (!isset($input[$key]) || $input[$key] === '') {
            return null;
        }
        if (!is_string($input[$key]) || !in_array($input[$key], $allowed, true)) {
            throw new AdminCatalogException('Invalid ' . $key . ' filter.', 422);
        }

        return $input[$key];
    }

    private function booleanOption(array $input, string $key): bool
    {
        $value = $input[$key] ?? false;
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        throw new AdminCatalogException('Invalid ' . $key . ' option.', 422);
    }

    private function pagination(array $query): array
    {
        $page = $this->pageValue($query['page'] ?? 1, 'page');
        $pageSize = $this->pageValue($query['page_size'] ?? 20, 'page_size');
        if ($pageSize > 100 || ($page - 1) > intdiv(PHP_INT_MAX - $pageSize, $pageSize)) {
            throw new AdminCatalogException('Invalid pagination parameters.', 422);
        }

        return [$page, $pageSize];
    }

    private function pageValue(mixed $value, string $key): int
    {
        if (
            (!is_int($value) && !is_string($value))
            || preg_match('/^[1-9]\d*$/', (string) $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT) === false
        ) {
            throw new AdminCatalogException('Invalid ' . $key . ' parameter.', 422);
        }

        return (int) $value;
    }
}

final class AdminCatalogController
{
    private const MAX_BODY_SIZE = 16384;

    public function __construct(
        private AdminCatalogService $service,
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
        } catch (AdminCatalogException $error) {
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
            error_log('[admin product catalog] ' . $error->getMessage());
            $this->respond(500, [
                'success' => false,
                'data' => null,
                'error' => ['message' => 'The product catalog request could not be completed.'],
            ]);
        } catch (Throwable $error) {
            error_log('[admin product catalog] ' . $error->getMessage());
            $this->respond(500, [
                'success' => false,
                'data' => null,
                'error' => ['message' => 'The product catalog request could not be completed.'],
            ]);
        }
    }

    private function dispatch(array $admin): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $action = $_GET['action'] ?? 'products';
            if (!is_string($action)) {
                throw new AdminCatalogException('Invalid action.', 400);
            }
            if ($action === 'csrf') {
                if (empty($_SESSION['csrf_token'])) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }

                return ['csrf_token' => $_SESSION['csrf_token']];
            }
            return match ($action) {
                'products' => $this->service->listProducts($_GET),
                'product' => $this->service->productDetails($_GET),
                'categories' => $this->service->listCategories(),
                default => throw new AdminCatalogException('Unknown catalog action.', 404),
            };
        }

        if ($method !== 'POST') {
            header('Allow: GET, POST');
            throw new AdminCatalogException('Method not allowed.', 405);
        }

        $input = $this->readInput();
        $this->requireCsrf($input);
        $action = $input['action'] ?? '';
        if (!is_string($action)) {
            throw new AdminCatalogException('Invalid action.', 400);
        }

        return match ($action) {
            'create-category' => $this->service->createCategory($input, $admin),
            'update-category' => $this->service->updateCategory($input, $admin),
            'delete-category' => $this->service->deleteCategory($input, $admin),
            'update-product-status' => $this->service->updateProductStatus($input, $admin),
            'remove-product' => $this->service->removeProduct($input, $admin),
            default => throw new AdminCatalogException('Unknown catalog action.', 404),
        };
    }

    private function readInput(): array
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_SIZE) {
            throw new AdminCatalogException('Request is too large.', 413);
        }
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType === 'application/json') {
            $input = json_decode(file_get_contents('php://input') ?: '', true);
            if (!is_array($input)) {
                throw new AdminCatalogException('Malformed JSON request.', 400);
            }

            return $input;
        }
        if ($contentType === 'application/x-www-form-urlencoded') {
            return $_POST;
        }

        throw new AdminCatalogException('Send a JSON request.', 415);
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
            throw new AdminCatalogException('Invalid or missing CSRF token.', 403);
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
$repository = new AdminCatalogRepository($database);
$service = new AdminCatalogService($database, $repository);
$auth = new AdminAuthMiddleware(new AdminAuthService($database, $sessions));

(new AdminCatalogController($service, $auth))->run();
