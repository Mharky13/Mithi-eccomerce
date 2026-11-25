<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/Auth.php';

final class CartApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        string $message,
        public readonly mixed $data = null
    ) {
        parent::__construct($message);
    }
}

final class CartRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function findActiveCustomerId(int $userId): ?int
    {
        $query = $this->database->prepare("
            SELECT c.customer_ID
            FROM CUSTOMERS c
            INNER JOIN USERS u ON u.user_ID = c.user_ID
            WHERE c.user_ID = :user_id
            AND LOWER(TRIM(u.user_Status)) = 'active'
            AND LOWER(TRIM(u.user_Role)) = 'customer'
            LIMIT 1
        ");
        $query->execute(['user_id' => $userId]);
        $customerId = $query->fetchColumn();

        return $customerId === false ? null : (int) $customerId;
    }

    public function findOrCreateActiveCartId(int $customerId): int
    {
        $query = $this->database->prepare("
            SELECT cart_ID
            FROM CART
            WHERE customer_ID = :customer_id AND status = 'ACTIVE'
            ORDER BY cart_ID DESC
            LIMIT 1
        ");
        $query->execute(['customer_id' => $customerId]);
        $cartId = $query->fetchColumn();
        if ($cartId !== false) {
            return (int) $cartId;
        }

        $insert = $this->database->prepare("
            INSERT INTO CART (customer_ID, status)
            VALUES (:customer_id, 'ACTIVE')
        ");
        $insert->execute(['customer_id' => $customerId]);

        return (int) $this->database->lastInsertId();
    }

    public function getCart(int $cartId): array
    {
        $query = $this->database->prepare("
            SELECT
                ci.cart_item_ID,
                ci.product_ID,
                ci.inventory_ID,
                p.prod_name AS product_name,
                (
                    SELECT pi.img_URL
                    FROM PRODUCT_IMAGES pi
                    WHERE pi.prod_ID = p.prod_ID
                    ORDER BY pi.img_ID ASC
                    LIMIT 1
                ) AS image_url,
                p.prod_price AS price,
                i.inventory_QTY AS stock,
                ci.item_QTY AS quantity,
                ROUND(p.prod_price * ci.item_QTY, 2) AS subtotal
            FROM CART_ITEM ci
            INNER JOIN PRODUCTS p ON p.prod_ID = ci.product_ID
            INNER JOIN INVENTORY i
                ON i.inventory_ID = ci.inventory_ID
            AND i.prod_ID = ci.product_ID
            WHERE ci.cart_ID = :cart_id
            ORDER BY ci.cart_item_ID DESC
        ");
        $query->execute(['cart_id' => $cartId]);
        $items = $query->fetchAll();
        $total = 0.0;

        foreach ($items as &$item) {
            $item['price'] = (float) $item['price'];
            $item['quantity'] = (int) $item['quantity'];
            $item['stock'] = (int) $item['stock'];
            $item['subtotal'] = round($item['price'] * $item['quantity'], 2);
            $total += $item['subtotal'];
        }
        unset($item);

        return [
            'items' => $items,
            'item_count' => count($items),
            'total' => round($total, 2),
        ];
    }

    public function updateQuantity(
        int $cartId,
        int $productId,
        int $inventoryId,
        int $quantity
    ): array {
        $this->database->beginTransaction();
        try {
            $query = $this->database->prepare("
                SELECT ci.cart_item_ID, i.inventory_QTY, p.prod_price
                FROM CART_ITEM ci
                INNER JOIN INVENTORY i
                    ON i.inventory_ID = ci.inventory_ID
                AND i.prod_ID = ci.product_ID
                INNER JOIN PRODUCTS p ON p.prod_ID = ci.product_ID
                WHERE ci.cart_ID = :cart_id
                AND ci.product_ID = :product_id
                AND ci.inventory_ID = :inventory_id
                FOR UPDATE
            ");
            $query->execute([
                'cart_id' => $cartId,
                'product_id' => $productId,
                'inventory_id' => $inventoryId,
            ]);
            $item = $query->fetch();
            if (!$item) {
                throw new CartApiException(404, 'Item not found in your cart.');
            }

            $availableStock = (int) $item['inventory_QTY'];
            if ($quantity > $availableStock) {
                throw new CartApiException(
                    409,
                    'Not enough stock available.',
                    ['available_stock' => $availableStock]
                );
            }

            $update = $this->database->prepare("
                UPDATE CART_ITEM
                SET item_QTY = :quantity, price = :price
                WHERE cart_item_ID = :cart_item_id
                AND cart_ID = :cart_id
            ");
            $update->execute([
                'quantity' => $quantity,
                'price' => $item['prod_price'],
                'cart_item_id' => $item['cart_item_ID'],
                'cart_id' => $cartId,
            ]);
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }

        return $this->getCart($cartId);
    }

    public function removeItem(int $cartId, int $cartItemId): array
    {
        $query = $this->database->prepare("
            DELETE FROM CART_ITEM
            WHERE cart_item_ID = :cart_item_id AND cart_ID = :cart_id
        ");
        $query->execute([
            'cart_item_id' => $cartItemId,
            'cart_id' => $cartId,
        ]);
        if ($query->rowCount() !== 1) {
            throw new CartApiException(404, 'Cart item not found.');
        }

        return $this->getCart($cartId);
    }
}

final class CartController
{
    private ?CartRepository $repository = null;

    public function __construct(
        private SessionManager $sessions
    ) {
    }

    public function handle(): never
    {
        try {
            $input = $this->readInput();
            $action = $input['action'] ?? $_GET['action'] ?? 'get';
            if (!is_string($action)) {
                throw new CartApiException(400, 'Invalid action.');
            }
            $action = trim($action);

            if (in_array($action, ['update_quantity', 'remove_item'], true)
                && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            ) {
                header('Allow: POST');
                throw new CartApiException(405, 'Use a POST request for cart changes.');
            }

            $this->sessions->start();
            $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
            if ($userId === false || $userId === null || $userId <= 0) {
                throw new CartApiException(401, 'Please log in first.');
            }

            $customerId = $this->repository->findActiveCustomerId((int) $userId);
            if ($customerId === null) {
                throw new CartApiException(403, 'Active customer account required.');
            }
            $this->repository = new CartRepository(Database::connect());
            $cartId = $this->repository->findOrCreateActiveCartId($customerId);

            $this->dispatch($action, $input, $cartId);
        } catch (CartApiException $error) {
            self::respond(false, $error->getMessage(), $error->data, $error->httpStatus);
        } catch (Throwable $error) {
            error_log('Shopping cart error: ' . $error->getMessage());
            self::respond(
                false,
                'Cart operation failed because of a server or database error. Please try again later.',
                null,
                500
            );
        }
    }

    private function readInput(): array
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 8192) {
            throw new CartApiException(413, 'Request is too large.');
        }

        $rawBody = file_get_contents('php://input');
        $json = json_decode($rawBody, true);
        if (is_array($json)) {
            return $json;
        }

        if ($rawBody !== '' && str_contains(
            strtolower($_SERVER['CONTENT_TYPE'] ?? ''),
            'application/json'
        )) {
            throw new CartApiException(400, 'Malformed JSON request.');
        }

        return $_POST;
    }

    private function dispatch(string $action, array $input, int $cartId): never
    {
        switch ($action) {
            case 'get':
                $cart = $this->repository->getCart($cartId);
                self::respond(
                    true,
                    $cart['items'] === [] ? 'Your cart is empty.' : 'Cart retrieved successfully.',
                    $cart
                );

            case 'update_quantity':
                $productId = $this->positiveInteger($input['product_ID'] ?? null);
                $inventoryId = $this->positiveInteger($input['inventory_ID'] ?? null);
                $quantity = $this->positiveInteger($input['quantity'] ?? null);
                if ($productId === null || $inventoryId === null || $quantity === null) {
                    throw new CartApiException(
                        422,
                        'Invalid product or quantity. Quantity must be at least 1.'
                    );
                }
                $cart = $this->repository->updateQuantity(
                    $cartId,
                    $productId,
                    $inventoryId,
                    $quantity
                );
                self::respond(true, 'Cart updated.', $cart);

            case 'remove_item':
                $cartItemId = $this->positiveInteger($input['cart_item_ID'] ?? null);
                if ($cartItemId === null) {
                    throw new CartApiException(422, 'Invalid cart item ID.');
                }
                self::respond(
                    true,
                    'Item removed.',
                    $this->repository->removeItem($cartId, $cartItemId)
                );

            case 'continue_shopping':
                self::respond(true, 'Continue shopping.', ['next_page' => 'products.php']);

            case 'checkout':
                $cart = $this->repository->getCart($cartId);
                if ($cart['items'] === []) {
                    throw new CartApiException(422, 'Your cart is empty.');
                }
                foreach ($cart['items'] as $item) {
                    if ($item['stock'] < $item['quantity']) {
                        throw new CartApiException(409, 'Stock has changed. Please update your cart.', [
                            'product_ID' => $item['product_ID'],
                            'available_stock' => $item['stock'],
                        ]);
                    }
                }
                self::respond(true, 'Cart validated for checkout.', [
                    'next_page' => 'checkout.php',
                    'cart' => $cart,
                ]);

            default:
                throw new CartApiException(400, 'Invalid action.');
        }
    }

    private function positiveInteger(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer !== false && $integer !== null && $integer > 0
            ? $integer
            : null;
    }

    private static function respond(
        bool $success,
        string $message,
        mixed $data = null,
        int $status = 200
    ): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

(new CartController(new SessionManager()))->handle();
