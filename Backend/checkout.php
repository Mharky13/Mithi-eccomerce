<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/Auth.php';

final class CheckoutRejected extends RuntimeException
{
}

final class CheckoutProcessor
{
    public function __construct(private PDO $conn)
    {
    }

    public function process(
        int $customerID,
        string $address,
        string $city,
        string $province,
        string $postalCode,
        string $paymentMethod
    ): array {
        if ($customerID <= 0) {
            return [
                'success' => false,
                'message' => 'Please log in first.',
                'order_ids' => [],
            ];
        }

        if (
            trim($address) === '' ||
            trim($city) === '' ||
            trim($province) === '' ||
            trim($postalCode) === '' ||
            trim($paymentMethod) === ''
        ) {
            return [
                'success' => false,
                'message' => 'Complete all required checkout details.',
                'order_ids' => [],
            ];
        }

        if (!in_array($paymentMethod, ['COD', 'GCash'], true)) {
            return [
                'success' => false,
                'message' => 'Unsupported payment method.',
                'order_ids' => [],
            ];
        }

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare(
                'SELECT customer_ID FROM CUSTOMERS WHERE customer_ID = :customer_id FOR UPDATE'
            );
            $stmt->execute(['customer_id' => $customerID]);
            if (!$stmt->fetch()) {
                throw new CheckoutRejected('Customer account not found.');
            }

            $stmt = $this->conn->prepare("
                SELECT
                    ci.cart_item_ID,
                    ci.item_QTY,
                    ci.inventory_ID,
                    p.prod_ID,
                    p.seller_ID,
                    p.prod_name,
                    p.prod_price,
                    p.discount_price,
                    i.inventory_QTY
                FROM CART c
                JOIN CART_ITEM ci ON ci.cart_ID = c.cart_ID
                JOIN PRODUCTS p ON p.prod_ID = ci.product_ID
                JOIN INVENTORY i ON i.inventory_ID = ci.inventory_ID
                WHERE c.customer_ID = :customer_id
                AND c.status = 'ACTIVE'
                FOR UPDATE
            ");
            $stmt->execute(['customer_id' => $customerID]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($items === []) {
                throw new CheckoutRejected('Your cart is empty.');
            }

            $groups = [];
            foreach ($items as $item) {
                $quantity = (int) $item['item_QTY'];
                $stock = (int) $item['inventory_QTY'];
                if ($quantity <= 0 || $quantity > $stock) {
                    throw new CheckoutRejected(
                        'Insufficient stock for ' . $item['prod_name'] . '. Please review your cart.'
                    );
                }

                $productPrice = (float) $item['prod_price'];
                $discountPrice = $item['discount_price'] === null
                    ? null
                    : (float) $item['discount_price'];
                $price = $discountPrice !== null
                    && $discountPrice > 0
                    && $discountPrice < $productPrice
                        ? $discountPrice
                        : $productPrice;

                if (!is_finite($price) || $price < 0) {
                    throw new CheckoutRejected('Invalid price for ' . $item['prod_name'] . '.');
                }

                $sellerID = (int) $item['seller_ID'];
                $item['unit_price'] = $price;
                $item['subtotal'] = $price * $quantity;
                $groups[$sellerID][] = $item;
            }

            $shippingFee = 0.00;
            $discount = 0.00;
            $orderIDs = [];

            foreach ($groups as $sellerID => $sellerItems) {
                $subtotal = array_sum(array_column($sellerItems, 'subtotal'));
                $total = $subtotal - $discount + $shippingFee;
                if (!is_finite($total) || $total <= 0) {
                    throw new CheckoutRejected('The order total must be greater than zero.');
                }

                $stmt = $this->conn->prepare("
                    INSERT INTO ORDERS (customer_ID, seller_ID, order_Status, total_Amount)
                    VALUES (:customer_id, :seller_id, 'Pending', :total)
                ");
                $stmt->execute([
                    'customer_id' => $customerID,
                    'seller_id' => $sellerID,
                    'total' => $total,
                ]);
                $orderID = (int) $this->conn->lastInsertId();
                $orderIDs[] = $orderID;

                foreach ($sellerItems as $item) {
                    $stmt = $this->conn->prepare("
                        INSERT INTO ORDER_ITEMS
                            (order_ID, product_ID, quantity, unit_Price, subtotal)
                        VALUES (:order_id, :product_id, :quantity, :unit_price, :subtotal)
                    ");
                    $stmt->execute([
                        'order_id' => $orderID,
                        'product_id' => $item['prod_ID'],
                        'quantity' => $item['item_QTY'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $item['subtotal'],
                    ]);

                    $stmt = $this->conn->prepare("
                        UPDATE INVENTORY
                        SET inventory_QTY = inventory_QTY - :quantity
                        WHERE inventory_ID = :inventory_id
                        AND inventory_QTY >= :required_quantity
                    ");
                    $stmt->execute([
                        'quantity' => $item['item_QTY'],
                        'inventory_id' => $item['inventory_ID'],
                        'required_quantity' => $item['item_QTY'],
                    ]);
                    if ($stmt->rowCount() !== 1) {
                        throw new CheckoutRejected(
                            'Stock changed during checkout. Please review your cart.'
                        );
                    }
                }

                $stmt = $this->conn->prepare("
                    INSERT INTO SHIPPING
                        (customer_ID, order_ID, shipping_Address, shipping_City,
                        shipping_Province, shipping_PostalCode, shipping_Status)
                    VALUES
                        (:customer_id, :order_id, :address, :city, :province, :postal_code, 'Pending')
                ");
                $stmt->execute([
                    'customer_id' => $customerID,
                    'order_id' => $orderID,
                    'address' => trim($address),
                    'city' => trim($city),
                    'province' => trim($province),
                    'postal_code' => trim($postalCode),
                ]);

                $stmt = $this->conn->prepare("
                    INSERT INTO PAYMENTS
                        (order_ID, payment_Method, payment_Amount, payment_Status)
                    VALUES (:order_id, :payment_method, :payment_amount, 'Pending')
                ");
                $stmt->execute([
                    'order_id' => $orderID,
                    'payment_method' => $paymentMethod,
                    'payment_amount' => $total,
                ]);
            }

            $stmt = $this->conn->prepare("
                UPDATE CART
                SET status = 'COMPLETED'
                WHERE customer_ID = :customer_id
                AND status = 'ACTIVE'
            ");
            $stmt->execute(['customer_id' => $customerID]);

            $this->conn->commit();
            return [
                'success' => true,
                'message' => 'Checkout successful. Payment is pending.',
                'order_ids' => $orderIDs,
            ];
        } catch (CheckoutRejected $error) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return [
                'success' => false,
                'message' => $error->getMessage(),
                'order_ids' => [],
            ];
        } catch (Throwable $error) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Checkout error: ' . $error->getMessage());
            return [
                'success' => false,
                'message' => 'Checkout failed because of a server or database error. Please try again later.',
                'order_ids' => [],
            ];
        }
    }
}

final class CheckoutRequestException extends RuntimeException
{
}

final class CheckoutRequest
{
    public static function readJson(): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            throw new CheckoutRequestException('Use a POST request.', 405);
        }

        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
            throw new CheckoutRequestException('Request is too large.', 413);
        }

        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType !== 'application/json') {
            throw new CheckoutRequestException('Send a JSON request.', 415);
        }

        $request = json_decode(file_get_contents('php://input'), true);
        if (!is_array($request)) {
            throw new CheckoutRequestException('Malformed JSON request.', 400);
        }

        return $request;
    }
}

final class CheckoutResponse
{
    public static function send(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

final class CheckoutController
{
    public function __construct(private SessionManager $sessions)
    {
    }

    public function handle(): never
    {
        try {
            $request = CheckoutRequest::readJson();
            $this->sessions->start();
            $userID = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);

            if ($userID === false || $userID === null || $userID <= 0) {
                throw new CheckoutRequestException('Please log in first.', 401);
            }

            $checkoutDetails = $this->checkoutDetails($request);
            $pdo = Database::connect();
            $customerID = $this->findCustomerID($pdo, (int) $userID);
            if ($customerID === null) {
                throw new CheckoutRequestException(
                    'A customer account is required to check out.',
                    403
                );
            }

            $processor = new CheckoutProcessor($pdo);
            $result = $processor->process($customerID, ...$checkoutDetails);
            CheckoutResponse::send($result['success'] ? 200 : 422, $result);
        } catch (CheckoutRequestException $error) {
            CheckoutResponse::send($error->getCode(), [
                'success' => false,
                'message' => $error->getMessage(),
                'order_ids' => [],
            ]);
        } catch (Throwable $error) {
            error_log('Checkout endpoint error: ' . $error->getMessage());
            CheckoutResponse::send(500, [
                'success' => false,
                'message' => 'Checkout is temporarily unavailable. Please try again later.',
                'order_ids' => [],
            ]);
        }
    }

    private function checkoutDetails(array $request): array
    {
        $fields = ['address', 'city', 'province', 'postalCode', 'paymentMethod'];
        foreach ($fields as $field) {
            if (!isset($request[$field]) || !is_string($request[$field])) {
                throw new CheckoutRequestException(
                    'Complete all required checkout details.',
                    422
                );
            }
        }

        return [
            $request['address'],
            $request['city'],
            $request['province'],
            $request['postalCode'],
            $request['paymentMethod'],
        ];
    }

    private function findCustomerID(PDO $pdo, int $userID): ?int
    {
        $query = $pdo->prepare(
            'SELECT customer_ID FROM CUSTOMERS WHERE user_ID = :user_id'
        );
        $query->execute(['user_id' => $userID]);
        $customerID = $query->fetchColumn();

        return $customerID === false ? null : (int) $customerID;
    }
}

(new CheckoutController(new SessionManager()))->handle();