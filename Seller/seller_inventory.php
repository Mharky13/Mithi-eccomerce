<?php
declare(strict_types=1);

namespace App\SellerProducts;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../Backend/database.php';
require_once __DIR__ . '/../Backend/Auth.php';

final class ProductApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

final class ProductConfig
{
    public const MAX_IMAGE_SIZE = 5242880;
    public const MAX_IMAGES = 5;
    public const MAX_NAME_LENGTH = 100;
    public const MAX_PAGE_SIZE = 50;
    public const STATUSES = ['Draft', 'Active', 'Archived'];
    public const SORT_OPTIONS = ['newest', 'oldest', 'price_asc', 'price_desc', 'name_asc'];
    public const OPEN_ORDER_STATUSES = ['Pending', 'Confirmed', 'Processing', 'Shipped'];
    public const TRANSITIONS = [
        'Draft' => ['Active', 'Archived'],
        'Active' => ['Draft', 'Archived'],
        'Archived' => [],
    ];
}

final class ProductImageStorage
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private string $directory;
    private string $baseUrl;

    public function __construct()
    {
        $this->directory = getenv('PRODUCT_IMAGE_DIR')
            ?: dirname(__DIR__) . '/Frontend/seller/images/uploads/seller-products';
        $projectBaseUrl = rtrim(
            dirname(dirname(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/Seller/seller_inventory.php'))),
            '/'
        );
        $this->baseUrl = rtrim(
            getenv('PRODUCT_IMAGE_BASE_URL')
                ?: $projectBaseUrl . '/Frontend/seller/images/uploads/seller-products',
            '/'
        );
    }

    public function store(array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ProductApiException(422, 'An image upload failed.');
        }
        if (!isset($file['tmp_name'], $file['size']) || (int) $file['size'] > ProductConfig::MAX_IMAGE_SIZE) {
            throw new ProductApiException(422, 'Each image must be no larger than 5 MB.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new ProductApiException(422, 'An uploaded image is invalid.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime]) || @getimagesize($file['tmp_name']) === false) {
            throw new ProductApiException(422, 'Images must be valid JPEG, PNG, or WebP files.');
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Could not create product image storage.');
        }

        $fileName = bin2hex(random_bytes(16)) . '.' . self::MIME_EXTENSIONS[$mime];
        $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new RuntimeException('Could not save product image.');
        }

        return $this->baseUrl . '/' . $fileName;
    }

    public function delete(string $url): void
    {
        $prefix = $this->baseUrl . '/';
        if (!str_starts_with($url, $prefix)) {
            return;
        }
        $fileName = substr($url, strlen($prefix));
        if ($fileName === '' || basename($fileName) !== $fileName) {
            return;
        }
        $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
        if (is_file($path) && !unlink($path)) {
            error_log('Unable to remove product image: ' . $path);
        }
    }
}

final class ProductRepository
{
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
            throw new ProductApiException(403, 'An active seller account is required.');
        }

        return (int) $sellerId;
    }

    public function categoryExists(int $categoryId): bool
    {
        $query = $this->database->prepare(
            'SELECT 1 FROM PRODUCT_CATEGORIES WHERE categ_ID = :category_id LIMIT 1'
        );
        $query->execute(['category_id' => $categoryId]);

        return $query->fetchColumn() !== false;
    }

    public function product(int $productId, int $sellerId, bool $lock = false): ?array
    {
        $query = $this->database->prepare("
            SELECT p.prod_ID, p.seller_ID, p.prod_name, p.prod_description,
            p.prod_price, p.categ_ID, p.availability_type,
            COALESCE(ps.listing_Status, 'Active') AS listing_status
            FROM PRODUCTS p
            LEFT JOIN SELLER_PRODUCT_SETTINGS ps ON ps.prod_ID = p.prod_ID
            WHERE p.prod_ID = :product_id AND p.seller_ID = :seller_id
            LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute(['product_id' => $productId, 'seller_id' => $sellerId]);
        $product = $query->fetch();

        return $product === false ? null : $product;
    }

    public function images(int $productId, bool $lock = false): array
    {
        $query = $this->database->prepare(
            'SELECT pi.img_ID AS id, pi.img_URL AS url,
                    COALESCE(si.display_Order, pi.img_ID) AS display_order
        FROM PRODUCT_IMAGES pi
        LEFT JOIN SELLER_PRODUCT_IMAGE_SETTINGS si ON si.img_ID = pi.img_ID
        WHERE pi.prod_ID = :product_id
        ORDER BY COALESCE(si.display_Order, pi.img_ID), pi.img_ID'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute(['product_id' => $productId]);

        return $query->fetchAll();
    }

    public function variants(int $productId): array
    {
        $query = $this->database->prepare(
            'SELECT variant_ID AS id, variant_Size AS size, variant_Color AS color, variant_Price AS price
        FROM SELLER_PRODUCT_VARIANTS WHERE prod_ID = :product_id ORDER BY variant_ID'
        );
        $query->execute(['product_id' => $productId]);

        return $query->fetchAll();
    }

    public function inventoryQuantity(int $productId): int
    {
        $query = $this->database->prepare(
            'SELECT COALESCE(SUM(inventory_QTY), 0) FROM INVENTORY WHERE prod_ID = :product_id'
        );
        $query->execute(['product_id' => $productId]);

        return (int) $query->fetchColumn();
    }

    public function list(
        int $sellerId,
        int $page,
        int $pageSize,
        string $sort,
        array $filters
    ): array {
        $conditions = ['p.seller_ID = :seller_id'];
        $parameters = ['seller_id' => $sellerId];

        if (isset($filters['status'])) {
            $conditions[] = "COALESCE(ps.listing_Status, 'Active') = :status";
            $parameters['status'] = $filters['status'];
        }
        if (isset($filters['category'])) {
            $conditions[] = 'p.categ_ID = :category_id';
            $parameters['category_id'] = (int) $filters['category'];
        }
        if (isset($filters['keyword']) && $filters['keyword'] !== '') {
            $conditions[] = 'p.prod_name LIKE :keyword';
            $parameters['keyword'] = '%' . $filters['keyword'] . '%';
        }

        $sortSql = match ($sort) {
            'oldest' => 'p.created_at ASC, p.prod_ID ASC',
            'price_asc' => 'p.prod_price ASC, p.prod_ID DESC',
            'price_desc' => 'p.prod_price DESC, p.prod_ID DESC',
            'name_asc' => 'p.prod_name ASC, p.prod_ID DESC',
            default => 'p.created_at DESC, p.prod_ID DESC',
        };
        $where = implode(' AND ', $conditions);
        $count = $this->database->prepare("
            SELECT COUNT(*) FROM PRODUCTS p
            LEFT JOIN SELLER_PRODUCT_SETTINGS ps ON ps.prod_ID = p.prod_ID
            WHERE {$where}
        ");
        $this->bind($count, $parameters);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $offset = ($page - 1) * $pageSize;
        $query = $this->database->prepare("
            SELECT p.prod_ID AS product_id, p.prod_name AS name,
                p.prod_description AS description, p.prod_price AS price,
                p.categ_ID AS category_id, c.categ_name AS category_name,
                COALESCE(ps.listing_Status, 'Active') AS status,
                COALESCE(i.stock, 0) AS quantity,
                (SELECT pi.img_URL FROM PRODUCT_IMAGES pi
                    LEFT JOIN SELLER_PRODUCT_IMAGE_SETTINGS psi ON psi.img_ID = pi.img_ID
                    WHERE pi.prod_ID = p.prod_ID
                    ORDER BY COALESCE(psi.display_Order, pi.img_ID), pi.img_ID
                    LIMIT 1) AS image
            FROM PRODUCTS p
            INNER JOIN PRODUCT_CATEGORIES c ON c.categ_ID = p.categ_ID
            LEFT JOIN SELLER_PRODUCT_SETTINGS ps ON ps.prod_ID = p.prod_ID
            LEFT JOIN (
                SELECT prod_ID, SUM(inventory_QTY) AS stock
                FROM INVENTORY GROUP BY prod_ID
            ) i ON i.prod_ID = p.prod_ID
            WHERE {$where}
            ORDER BY {$sortSql}
            LIMIT :row_limit OFFSET :row_offset
        ");
        $this->bind($query, $parameters);
        $query->bindValue(':row_limit', $pageSize, PDO::PARAM_INT);
        $query->bindValue(':row_offset', $offset, PDO::PARAM_INT);
        $query->execute();

        return [
            'items' => $query->fetchAll(),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $pageSize)),
            ],
        ];
    }

    public function create(array $data, int $sellerId, string $status): int
    {
        $query = $this->database->prepare("
            INSERT INTO PRODUCTS
                (seller_ID, prod_name, prod_description, prod_price, categ_ID, availability_type)
            VALUES
                (:seller_id, :name, :description, :price, :category_id, :availability)
        ");
        $query->execute([
            'seller_id' => $sellerId,
            'name' => $data['name'],
            'description' => $data['description'],
            'price' => $data['price'],
            'category_id' => $data['category_id'],
            'availability' => (int) $data['quantity'] > 0 ? 'In Stock' : 'Out of Stock',
        ]);
        $productId = (int) $this->database->lastInsertId();

        $settings = $this->database->prepare(
            'INSERT INTO SELLER_PRODUCT_SETTINGS (prod_ID, listing_Status) VALUES (:product_id, :status)'
        );
        $settings->execute(['product_id' => $productId, 'status' => $status]);
        $this->saveInventory($productId, $data);

        return $productId;
    }

    public function update(int $productId, array $data, string $status): void
    {
        $query = $this->database->prepare("
            UPDATE PRODUCTS
            SET prod_name = :name, prod_description = :description,
                prod_price = :price, categ_ID = :category_id
            WHERE prod_ID = :product_id
        ");
        $query->execute([
            'name' => $data['name'],
            'description' => $data['description'],
            'price' => $data['price'],
            'category_id' => $data['category_id'],
            'product_id' => $productId,
        ]);

        $settings = $this->database->prepare("
            INSERT INTO SELLER_PRODUCT_SETTINGS (prod_ID, listing_Status)
            VALUES (:product_id, :status)
            ON DUPLICATE KEY UPDATE listing_Status = VALUES(listing_Status)
        ");
        $settings->execute(['product_id' => $productId, 'status' => $status]);
    }

    public function insertImage(int $productId, string $url): void
    {
        $query = $this->database->prepare(
            'INSERT INTO PRODUCT_IMAGES (prod_ID, img_URL) VALUES (:product_id, :url)'
        );
        $query->execute(['product_id' => $productId, 'url' => $url]);
    }

    public function updateImageOrder(int $imageId, int $productId, int $order): void
    {
        $query = $this->database->prepare(
            'INSERT INTO SELLER_PRODUCT_IMAGE_SETTINGS (img_ID, prod_ID, display_Order)
        SELECT img_ID, prod_ID, :display_order FROM PRODUCT_IMAGES
        WHERE img_ID = :image_id AND prod_ID = :product_id
        ON DUPLICATE KEY UPDATE display_Order = VALUES(display_Order)'
        );
        $query->execute([
            'display_order' => $order,
            'image_id' => $imageId,
            'product_id' => $productId,
        ]);
        if ($query->rowCount() === 0 && !$this->imageExists($imageId, $productId)) {
            throw new ProductApiException(404, 'Image does not belong to this product.');
        }
    }

    public function reorderImages(int $productId, int $imageId, int $order): void
    {
        $images = $this->images($productId, true);
        $imageIds = array_map(static fn(array $image): int => (int) $image['id'], $images);
        $currentIndex = array_search($imageId, $imageIds, true);
        if ($currentIndex === false) {
            throw new ProductApiException(404, 'Image does not belong to this product.');
        }

        $ordered = $imageIds;
        array_splice($ordered, $currentIndex, 1);
        array_splice($ordered, min($order - 1, count($ordered)), 0, [$imageId]);
        foreach ($ordered as $index => $orderedImageId) {
            $this->updateImageOrder($orderedImageId, $productId, $index + 1);
        }
    }

    public function deleteImage(int $imageId, int $productId): string
    {
        $query = $this->database->prepare(
            'SELECT img_URL FROM PRODUCT_IMAGES WHERE img_ID = :image_id AND prod_ID = :product_id'
        );
        $query->execute(['image_id' => $imageId, 'product_id' => $productId]);
        $url = $query->fetchColumn();
        if ($url === false) {
            throw new ProductApiException(404, 'Image does not belong to this product.');
        }

        $delete = $this->database->prepare(
            'DELETE FROM PRODUCT_IMAGES WHERE img_ID = :image_id AND prod_ID = :product_id'
        );
        $delete->execute(['image_id' => $imageId, 'product_id' => $productId]);
        $deleteOrder = $this->database->prepare(
            'DELETE FROM SELLER_PRODUCT_IMAGE_SETTINGS WHERE img_ID = :image_id'
        );
        $deleteOrder->execute(['image_id' => $imageId]);

        return (string) $url;
    }

    public function replaceImage(int $imageId, int $productId, string $url): string
    {
        $old = $this->database->prepare(
            'SELECT img_URL FROM PRODUCT_IMAGES WHERE img_ID = :image_id AND prod_ID = :product_id'
        );
        $old->execute(['image_id' => $imageId, 'product_id' => $productId]);
        $oldUrl = $old->fetchColumn();
        if ($oldUrl === false) {
            throw new ProductApiException(404, 'Image does not belong to this product.');
        }

        $update = $this->database->prepare(
            'UPDATE PRODUCT_IMAGES SET img_URL = :url WHERE img_ID = :image_id AND prod_ID = :product_id'
        );
        $update->execute([
            'url' => $url,
            'image_id' => $imageId,
            'product_id' => $productId,
        ]);

        return (string) $oldUrl;
    }

    public function replaceVariants(int $productId, array $variants): void
    {
        $delete = $this->database->prepare(
            'DELETE FROM SELLER_PRODUCT_VARIANTS WHERE prod_ID = :product_id'
        );
        $delete->execute(['product_id' => $productId]);

        $insert = $this->database->prepare("
            INSERT INTO SELLER_PRODUCT_VARIANTS
                (prod_ID, variant_Size, variant_Color, variant_Price)
            VALUES (:product_id, :size, :color, :price)
        ");
        foreach ($variants as $variant) {
            $insert->execute([
                'product_id' => $productId,
                'size' => $variant['size'],
                'color' => $variant['color'],
                'price' => $variant['price'],
            ]);
        }
    }

    public function hasOpenOrders(int $productId): bool
    {
        $placeholders = implode(',', array_fill(0, count(ProductConfig::OPEN_ORDER_STATUSES), '?'));
        $query = $this->database->prepare("
            SELECT 1
            FROM ORDER_ITEMS oi
            INNER JOIN ORDERS o ON o.order_ID = oi.order_ID
            WHERE oi.product_ID = ?
              AND o.order_Status IN ({$placeholders})
            LIMIT 1
        ");
        $query->execute([$productId, ...ProductConfig::OPEN_ORDER_STATUSES]);

        return $query->fetchColumn() !== false;
    }

    public function archive(int $productId): void
    {
        $query = $this->database->prepare("
            INSERT INTO SELLER_PRODUCT_SETTINGS (prod_ID, listing_Status)
            VALUES (:product_id, 'Archived')
            ON DUPLICATE KEY UPDATE listing_Status = 'Archived'
        ");
        $query->execute(['product_id' => $productId]);
    }

    private function saveInventory(int $productId, array $data): void
    {
        $query = $this->database->prepare(
            'INSERT INTO INVENTORY (prod_ID, inventory_QTY, region, province, city_municipality)
             VALUES (:product_id, :quantity, :region, :province, :city_municipality)'
        );
        $query->execute([
            'product_id' => $productId,
            'quantity' => $data['quantity'],
            'region' => $data['region'],
            'province' => $data['province'],
            'city_municipality' => $data['city_municipality'],
        ]);
    }

    private function imageExists(int $imageId, int $productId): bool
    {
        $query = $this->database->prepare(
            'SELECT 1 FROM PRODUCT_IMAGES WHERE img_ID = :image_id AND prod_ID = :product_id'
        );
        $query->execute(['image_id' => $imageId, 'product_id' => $productId]);

        return $query->fetchColumn() !== false;
    }

    private function bind(\PDOStatement $statement, array $parameters): void
    {
        foreach ($parameters as $name => $value) {
            $statement->bindValue(
                ':' . $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
    }
}

final class ProductService
{
    public function __construct(
        private PDO $database,
        private ProductRepository $products,
        private ProductImageStorage $images
    ) {
    }

    public function list(int $sellerId, array $input): array
    {
        $page = $this->integer($input['page'] ?? 1, 'page', 1);
        $pageSize = $this->integer($input['page_size'] ?? 20, 'page_size', 1);
        if ($pageSize > ProductConfig::MAX_PAGE_SIZE) {
            throw new ProductApiException(422, 'page_size must not exceed 50.');
        }

        $sort = $input['sort'] ?? 'newest';
        if (!is_string($sort) || !in_array($sort, ProductConfig::SORT_OPTIONS, true)) {
            throw new ProductApiException(422, 'Invalid sort option.');
        }
        $filters = [];
        if (isset($input['status'])) {
            if (!is_string($input['status']) || !in_array($input['status'], ProductConfig::STATUSES, true)) {
                throw new ProductApiException(422, 'Invalid product status filter.');
            }
            $filters['status'] = $input['status'];
        }
        if (isset($input['category'])) {
            $filters['category'] = $this->integer($input['category'], 'category', 1);
        }
        if (isset($input['keyword'])) {
            if (!is_string($input['keyword']) || strlen($input['keyword']) > 100) {
                throw new ProductApiException(422, 'Keyword must be 100 characters or fewer.');
            }
            $filters['keyword'] = trim($input['keyword']);
        }

        return $this->products->list($sellerId, $page, $pageSize, $sort, $filters);
    }

    public function details(int $sellerId, int $productId): array
    {
        $product = $this->products->product($productId, $sellerId);
        if ($product === null) {
            throw new ProductApiException(404, 'Product not found.');
        }
        $product['images'] = $this->products->images($productId);
        $product['variants'] = $this->products->variants($productId);
        $product['quantity'] = $this->products->inventoryQuantity($productId);

        return $product;
    }

    public function create(int $sellerId, array $input, array $files): array
    {
        $data = $this->productFields($input);
        $variants = $this->validateVariants($this->decodeArray($input['variants'] ?? '[]', 'variants'));
        $imageFiles = $this->normalizeFiles($files['images'] ?? null);
        if (count($imageFiles) < 1 || count($imageFiles) > ProductConfig::MAX_IMAGES) {
            throw new ProductApiException(422, 'Upload between 1 and 5 product images.');
        }
        foreach ($imageFiles as $file) {
            $this->validateImage($file);
        }
        $status = $input['status'] ?? $input['requestedStatus'] ?? 'Draft';
        if (!is_string($status) || !in_array($status, ['Draft', 'Active'], true)) {
            throw new ProductApiException(422, 'New products may be created as Draft or Active.');
        }
        if (!$this->products->categoryExists($data['category_id'])) {
            throw new ProductApiException(422, 'Selected category does not exist.');
        }
        if ($status === 'Active' && (int) $data['quantity'] === 0) {
            throw new ProductApiException(422, 'Active products require available stock.');
        }

        $saved = [];
        try {
            return $this->transaction(function () use ($data, $variants, $imageFiles, $sellerId, $status, &$saved): array {
                $productId = $this->products->create($data, $sellerId, $status);
                foreach ($imageFiles as $file) {
                    $url = $this->images->store($file);
                    $saved[] = $url;
                    $this->products->insertImage($productId, $url);
                }
                $this->products->replaceVariants($productId, $variants);

                return ['product_id' => $productId, 'status' => $status];
            });
        } catch (Throwable $error) {
            foreach ($saved as $url) {
                $this->images->delete($url);
            }
            throw $error;
        }
    }

    public function update(int $sellerId, int $productId, array $input, array $files): array
    {
        $product = $this->ownedProduct($sellerId, $productId);
        if ($product['listing_status'] === 'Archived') {
            throw new ProductApiException(409, 'Archived products cannot be modified.');
        }
        if (array_key_exists('quantity', $input) || array_key_exists('initialQty', $input)) {
            throw new ProductApiException(422, 'Inventory quantity cannot be changed through product update.');
        }
        if (!isset($input['category_id']) && isset($input['categoryId'])) {
            $input['category_id'] = $input['categoryId'];
        }
        if (!isset($input['image_changes']) && isset($input['imageChanges'])) {
            $input['image_changes'] = $input['imageChanges'];
        }
        if (!isset($input['status']) && isset($input['requestedStatus'])) {
            $input['status'] = $input['requestedStatus'];
        }

        $input = array_merge([
            'name' => $product['prod_name'],
            'description' => $product['prod_description'],
            'price' => $product['prod_price'],
            'category_id' => $product['categ_ID'],
            'quantity' => $this->products->inventoryQuantity($productId),
        ], $input);
        $data = $this->productFields($input);
        if (!$this->products->categoryExists($data['category_id'])) {
            throw new ProductApiException(422, 'Selected category does not exist.');
        }
        $changes = $this->decodeArray($input['image_changes'] ?? '[]', 'image_changes');
        $imageFiles = $this->normalizeFiles($files['images'] ?? null);
        $variants = array_key_exists('variants', $input)
            ? $this->validateVariants($this->decodeArray($input['variants'], 'variants'))
            : null;

        $existingImages = $this->products->images($productId);
        $imageIds = array_column($existingImages, 'id');
        $removeCount = 0;
        $uploadCount = 0;
        foreach ($changes as $change) {
            if (!is_array($change) || !isset($change['type'])) {
                throw new ProductApiException(422, 'Invalid image change.');
            }
            if ($change['type'] === 'Upload') {
                $uploadCount++;
                $this->fileAt($imageFiles, $change);
            } elseif ($change['type'] === 'Replace') {
                $this->assertImageId($change, $imageIds);
                $this->fileAt($imageFiles, $change);
            } elseif ($change['type'] === 'Remove') {
                $this->assertImageId($change, $imageIds);
                $removeCount++;
            } elseif ($change['type'] === 'Reorder') {
                $this->assertImageId($change, $imageIds);
                $this->integer($change['newOrder'] ?? null, 'newOrder', 1);
            } else {
                throw new ProductApiException(422, 'Unsupported image change.');
            }
        }
        if (count($imageIds) + $uploadCount - $removeCount < 1) {
            throw new ProductApiException(422, 'A product must retain at least one image.');
        }
        if (count($imageIds) + $uploadCount - $removeCount > ProductConfig::MAX_IMAGES) {
            throw new ProductApiException(422, 'A product may have no more than 5 images.');
        }

        $status = $product['listing_status'];
        if (isset($input['status'])) {
            if (!is_string($input['status']) || !in_array($input['status'], ProductConfig::STATUSES, true)) {
                throw new ProductApiException(422, 'Invalid product status.');
            }
            if (!in_array($input['status'], ProductConfig::TRANSITIONS[$status] ?? [], true)) {
                throw new ProductApiException(422, 'Invalid status change.');
            }
            $status = $input['status'];
            if ($status === 'Active' && !$this->products->categoryExists($data['category_id'])) {
                throw new ProductApiException(422, 'Product category does not exist.');
            }
        }

        $newFiles = [];
        try {
            $result = $this->transaction(function () use (
                $productId,
                $sellerId,
                $data,
                $status,
                $changes,
                $imageFiles,
                $variants,
                &$newFiles
            ): array {
                $product = $this->ownedProduct($sellerId, $productId, true);
                if ($product['listing_status'] === 'Archived') {
                    throw new ProductApiException(409, 'Archived products cannot be modified.');
                }
                if (
                    $status !== $product['listing_status']
                    && !in_array(
                        $status,
                        ProductConfig::TRANSITIONS[$product['listing_status']] ?? [],
                        true
                    )
                ) {
                    throw new ProductApiException(409, 'Product status changed. Reload and try again.');
                }
                if (
                    $status === 'Active'
                    && $product['listing_status'] !== 'Active'
                    && $this->products->inventoryQuantity($productId) <= 0
                ) {
                    throw new ProductApiException(422, 'Active products require available stock.');
                }
                if ($status === 'Archived' && $this->products->hasOpenOrders($productId)) {
                    throw new ProductApiException(409, 'Product cannot be archived while open orders contain it.');
                }
                $oldFiles = [];
                $this->products->update($productId, $data, $status);
                foreach ($changes as $change) {
                    if ($change['type'] === 'Upload') {
                        $url = $this->images->store($this->fileAt($imageFiles, $change));
                        $newFiles[] = $url;
                        $this->products->insertImage($productId, $url);
                    } elseif ($change['type'] === 'Replace') {
                        $url = $this->images->store($this->fileAt($imageFiles, $change));
                        $newFiles[] = $url;
                        $oldFiles[] = $this->products->replaceImage(
                            (int) $change['imageId'],
                            $productId,
                            $url
                        );
                    } elseif ($change['type'] === 'Remove') {
                        $oldFiles[] = $this->products->deleteImage(
                            (int) $change['imageId'],
                            $productId
                        );
                    } else {
                        $this->products->reorderImages(
                            $productId,
                            (int) $change['imageId'],
                            (int) $change['newOrder']
                        );
                    }
                }
                if ($variants !== null) {
                    $this->products->replaceVariants($productId, $variants);
                }
                return [
                    'product_id' => $productId,
                    'status' => $status,
                    'old_image_urls' => $oldFiles,
                ];
            });
        } catch (Throwable $error) {
            foreach ($newFiles as $url) {
                $this->images->delete($url);
            }
            throw $error;
        }

        foreach ($result['old_image_urls'] as $url) {
            $this->images->delete($url);
        }
        unset($result['old_image_urls']);

        return $result;
    }

    public function archive(int $sellerId, int $productId): array
    {
        return $this->transaction(function () use ($sellerId, $productId): array {
            $product = $this->ownedProduct($sellerId, $productId, true);
            if ($product['listing_status'] === 'Archived') {
                throw new ProductApiException(409, 'Product is already archived.');
            }
            if ($this->products->hasOpenOrders($productId)) {
                throw new ProductApiException(409, 'Product cannot be archived while open orders contain it.');
            }
            $this->products->archive($productId);

            return ['product_id' => $productId, 'status' => 'Archived'];
        });
    }

    private function ownedProduct(int $sellerId, int $productId, bool $lock = false): array
    {
        if ($productId <= 0) {
            throw new ProductApiException(422, 'A valid product id is required.');
        }
        $product = $this->products->product($productId, $sellerId, $lock);
        if ($product === null) {
            throw new ProductApiException(404, 'Product not found.');
        }

        return $product;
    }

    private function productFields(array $input): array
    {
        $name = $input['name'] ?? null;
        $description = $input['description'] ?? null;
        $price = $input['price'] ?? null;
        $categoryId = filter_var(
            $input['category_id'] ?? $input['categoryId'] ?? null,
            FILTER_VALIDATE_INT
        );
        $quantity = filter_var(
            $input['quantity'] ?? $input['initialQty'] ?? 0,
            FILTER_VALIDATE_INT
        );

        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > ProductConfig::MAX_NAME_LENGTH) {
            throw new ProductApiException(422, 'A product name of 1 to 100 characters is required.');
        }
        if (!is_string($description) || trim($description) === '' || mb_strlen(trim($description)) > 5000) {
            throw new ProductApiException(422, 'A product description of 1 to 5000 characters is required.');
        }
        if (!is_numeric($price) || !is_finite((float) $price) || (float) $price <= 0) {
            throw new ProductApiException(422, 'Price must be a positive number.');
        }
        if ($categoryId === false || $categoryId <= 0) {
            throw new ProductApiException(422, 'A valid category_id is required.');
        }
        if ($quantity === false || $quantity < 0) {
            throw new ProductApiException(422, 'quantity must be a non-negative integer.');
        }

        return [
            'name' => trim($name),
            'description' => trim($description),
            'price' => round((float) $price, 2),
            'category_id' => $categoryId,
            'quantity' => $quantity,
            'region' => $this->optionalLocation($input['region'] ?? ''),
            'province' => $this->optionalLocation($input['province'] ?? ''),
            'city_municipality' => $this->optionalLocation(
                $input['city_municipality'] ?? $input['city'] ?? ''
            ),
        ];
    }

    private function optionalLocation(mixed $value): string
    {
        if (!is_string($value) || strlen(trim($value)) > 100) {
            throw new ProductApiException(422, 'Location fields must be 100 characters or fewer.');
        }

        return trim($value);
    }

    private function validateVariants(array $variants): array
    {
        $validated = [];
        $seen = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                throw new ProductApiException(422, 'Each variant must be an object.');
            }
            $size = trim(is_string($variant['size'] ?? null) ? $variant['size'] : '');
            $color = trim(is_string($variant['color'] ?? null) ? $variant['color'] : '');
            if ($size === '' && $color === '') {
                throw new ProductApiException(422, 'Each variant needs a size or color.');
            }
            if (strlen($size) > 50 || strlen($color) > 50) {
                throw new ProductApiException(422, 'Variant size and color must be 50 characters or fewer.');
            }
            $key = strtolower($size . '|' . $color);
            if (isset($seen[$key])) {
                throw new ProductApiException(422, 'Duplicate product variant.');
            }
            $seen[$key] = true;
            $price = $variant['price'] ?? null;
            if ($price !== null && $price !== '') {
                if (!is_numeric($price) || !is_finite((float) $price) || (float) $price <= 0) {
                    throw new ProductApiException(422, 'Variant price must be a positive number.');
                }
                $price = round((float) $price, 2);
            } else {
                $price = null;
            }
            $validated[] = ['size' => $size, 'color' => $color, 'price' => $price];
        }

        return $validated;
    }

    private function validateImage(array $file): void
    {
        $mime = isset($file['tmp_name']) && is_file($file['tmp_name'])
            ? (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])
            : false;
        if (
            ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || (int) ($file['size'] ?? 0) > ProductConfig::MAX_IMAGE_SIZE
            || !is_string($mime)
            || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || @getimagesize($file['tmp_name']) === false
        ) {
            throw new ProductApiException(422, 'Images must be valid JPEG, PNG, or WebP files up to 5 MB.');
        }
    }

    private function normalizeFiles(mixed $files): array
    {
        if (!is_array($files) || !isset($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) {
            return [$files];
        }

        $normalized = [];
        foreach ($files['name'] as $index => $name) {
            $normalized[] = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }

        return $normalized;
    }

    private function fileAt(array $files, array $change): array
    {
        $index = filter_var($change['fileIndex'] ?? null, FILTER_VALIDATE_INT);
        if ($index === false || !isset($files[$index])) {
            throw new ProductApiException(422, 'A valid fileIndex is required for image upload or replacement.');
        }
        $this->validateImage($files[$index]);

        return $files[$index];
    }

    private function assertImageId(array $change, array $imageIds): void
    {
        $imageId = filter_var($change['imageId'] ?? null, FILTER_VALIDATE_INT);
        if ($imageId === false || !in_array($imageId, array_map('intval', $imageIds), true)) {
            throw new ProductApiException(404, 'Image does not belong to this product.');
        }
    }

    private function decodeArray(mixed $value, string $field): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            throw new ProductApiException(422, "{$field} must be a JSON array.");
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || array_is_list($decoded) === false) {
            throw new ProductApiException(422, "{$field} must be a JSON array.");
        }

        return $decoded;
    }

    private function integer(mixed $value, string $field, int $minimum): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < $minimum) {
            throw new ProductApiException(422, "{$field} must be an integer of at least {$minimum}.");
        }

        return $integer;
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
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function handleRequest(): never
{
    try {
        $sessions = new \SessionManager();
        $sessions->start();
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId <= 0) {
            respond(401, ['success' => false, 'message' => 'Authentication required.']);
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        if (!is_string($action)) {
            throw new ProductApiException(400, 'Invalid action.');
        }
        $database = \Database::connect();
        $repository = new ProductRepository($database);
        $sellerId = $repository->sellerIdFor($userId);
        $service = new ProductService($database, $repository, new ProductImageStorage());

        if ($method === 'GET' && $action === 'list') {
            respond(200, ['success' => true, 'data' => $service->list($sellerId, $_GET)]);
        }
        if ($method === 'GET' && $action === 'details') {
            $productId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId <= 0) {
                throw new ProductApiException(422, 'A valid product id is required.');
            }
            respond(200, ['success' => true, 'data' => $service->details($sellerId, $productId)]);
        }
        if ($method === 'POST' && $action === 'create') {
            respond(201, ['success' => true, 'data' => $service->create($sellerId, $_POST, $_FILES)]);
        }
        if ($method === 'POST' && $action === 'update') {
            $productId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId <= 0) {
                throw new ProductApiException(422, 'A valid product id is required.');
            }
            respond(200, ['success' => true, 'data' => $service->update($sellerId, $productId, $_POST, $_FILES)]);
        }
        if ($method === 'POST' && $action === 'archive') {
            $productId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId <= 0) {
                throw new ProductApiException(422, 'A valid product id is required.');
            }
            respond(200, ['success' => true, 'data' => $service->archive($sellerId, $productId)]);
        }

        header('Allow: GET, POST');
        throw new ProductApiException(404, 'Unknown seller product action or method.');
    } catch (ProductApiException $error) {
        respond($error->httpStatus, [
            'success' => false,
            'message' => $error->getMessage(),
            ...($error->details === [] ? [] : ['errors' => $error->details]),
        ]);
    } catch (PDOException $error) {
        error_log('Seller products database error: ' . $error->getMessage());
        respond(503, ['success' => false, 'message' => 'Product management is temporarily unavailable.']);
    } catch (Throwable $error) {
        error_log('Seller products API error: ' . $error->getMessage());
        respond(500, ['success' => false, 'message' => 'The request could not be completed.']);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    handleRequest();
}
