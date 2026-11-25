<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

final class DeliveryTrackingSessionManager
{
    public static function userId(): ?int
    {
        return 123;
    }

    public static function active(): bool
    {
        return true;
    }
}

final class DeliveryTrackingOrderRepository
{
    public static bool $simulateDbError = false;

    public static function find(string $orderRef, int $customerId): ?array
    {
        if (self::$simulateDbError) {
            throw new RuntimeException('Database connection failed');
        }

        $orders = [
            ['id' => 98231, 'reference' => '#ORD-98231', 'customer_id' => 123, 'shipped' => true],
            ['id' => 98232, 'reference' => '#ORD-98232', 'customer_id' => 123, 'shipped' => false],
            ['id' => 98233, 'reference' => '#ORD-98233', 'customer_id' => 123, 'shipped' => true],
        ];

        foreach ($orders as $order) {
            if ($order['reference'] === $orderRef && (int) $order['customer_id'] === $customerId) {
                return $order;
            }
        }

        return null;
    }
}

final class DeliveryTrackingShippingRepository
{
    public static bool $simulateDbError = false;

    public static function findByOrderId(int $orderId): ?array
    {
        if (self::$simulateDbError) {
            throw new RuntimeException('Database connection failed');
        }

        $shipping = [
            98231 => [
                'courier' => 'J&T Express',
                'tracking_number' => 'JT1234567890PH',
                'shipped_date' => 'October 23, 2026',
                'delivered_date' => '',
                'status' => 'In Transit',
            ],
            98233 => [
                'courier' => '',
                'tracking_number' => '',
                'shipped_date' => 'October 24, 2026',
                'delivered_date' => '',
                'status' => 'Unknown Status',
            ],

        ];

        return $shipping[$orderId] ?? null;
    }
}

function backLinks(bool $withDetails = true): void
{
    echo "[LINK]: Back to Orders\n";

    if ($withDetails) {
        echo "[LINK]: Back to Order Details\n";
    }
}

$userId = DeliveryTrackingSessionManager::userId();
$isSessionActive = DeliveryTrackingSessionManager::active();

if ($userId === null || !$isSessionActive) {
    echo "[FLOWCHART PATH -> NO]: Customer is not logged in.\n";
    echo "DISPLAY: 'Please log in to track your order'\n";
    echo "REDIRECT: Login Page (return target: DeliveryTrackingPage)\n";
    exit;
}

echo "[FLOWCHART PATH -> YES]: Customer Session Verified Active.\n";

$loggedInCustomerId = $userId;


$orderRef = '#ORD-98231';

if ($orderRef === '' || !preg_match('/^#ORD-\d+$/', $orderRef)) {
    echo "[FLOWCHART PATH -> NO]: Order reference is empty or invalid.\n";
    echo "DISPLAY: 'No order selected.'\n";
    backLinks(false);
    exit;
}

echo "[FLOWCHART PATH -> YES]: Order reference received: $orderRef\n";

try {
    $order = DeliveryTrackingOrderRepository::find($orderRef, $loggedInCustomerId);
} catch (Throwable $throwable) {
    error_log($throwable->getMessage());

    echo "[FLOWCHART PATH -> ERROR]: Order query failed.\n";
    echo "DISPLAY: 'Unable to retrieve order information. Please try again later.'\n";
    backLinks(false);
    exit;
}

if ($order === null) {
    echo "[FLOWCHART PATH -> NO]: Order does not exist for this customer.\n";
    echo "DISPLAY: 'Order not found or unavailable.'\n";
    backLinks(false);
    exit;
}

echo "[FLOWCHART PATH -> YES]: Order found and belongs to customer.\n";

try {
    $shipping = DeliveryTrackingShippingRepository::findByOrderId((int) $order['id']);
} catch (Throwable $throwable) {
    error_log($throwable->getMessage());

    echo "[FLOWCHART PATH -> ERROR]: Shipping query failed.\n";
    echo "DISPLAY Order Reference: " . $order['reference'] . "\n";
    echo "DISPLAY: 'Unable to retrieve shipping information. Please try again later.'\n";
    backLinks();
    exit;
}

echo "\n--- RENDERING DELIVERY TRACKING ---\n";
echo "Order Reference : " . $order['reference'] . "\n";

if ($shipping === null) {
    echo "[FLOWCHART PATH -> NO]: No shipping record found.\n";
    echo "DISPLAY: 'Shipping information is not yet available.'\n";

    if (!$order['shipped']) {
        echo "Delivery Status : Pending\n";
    } else {
        echo "DISPLAY: 'Delivery status is not yet available.'\n";
    }
} else {
    echo "[FLOWCHART PATH -> YES]: Shipping record found.\n";

    if (!empty($shipping['courier'])) {
        echo "Courier         : " . $shipping['courier'] . "\n";
    } else {
        echo "DISPLAY: 'Courier information is not yet available.'\n";
    }

    if (!empty($shipping['tracking_number'])) {
        echo "Tracking Number : " . $shipping['tracking_number'] . "\n";
    } else {
        echo "DISPLAY: 'Tracking number is not yet available.'\n";
    }

    if (!empty($shipping['shipped_date'])) {
        echo "Shipped Date    : " . $shipping['shipped_date'] . "\n";
    }

    if (!empty($shipping['delivered_date'])) {
        echo "Delivered Date  : " . $shipping['delivered_date'] . "\n";
    }

    $validStatuses = ['Pending', 'Preparing', 'Shipped', 'In Transit', 'Delivered', 'Failed', 'Returned', 'Cancelled'];

    if (in_array($shipping['status'], $validStatuses, true)) {
        echo "Delivery Status : [" . $shipping['status'] . "]\n";
    } else {
        echo "DISPLAY: 'Delivery status is currently unavailable.'\n";
    }
}

if ($shipping === null || empty($shipping['courier']) || empty($shipping['tracking_number'])) {
    echo "\nDISPLAY: 'Some shipping or tracking information is not yet available.'\n";
}

echo "\n";
backLinks();

$customerAction = 'details';

echo "\n[USER INTERACTION]: Customer selected '" . $customerAction . "'.\n";

if ($customerAction === 'orders') {
    echo "REDIRECT: OrdersPage\n";
} elseif ($customerAction === 'details') {
    echo "REDIRECT: OrderDetailsPage (using verified order reference " . $order['reference'] . ")\n";
}
