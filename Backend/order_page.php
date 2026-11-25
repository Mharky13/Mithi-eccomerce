<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

final class OrdersPageConfig
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $settings = [
            'session' => ['idle_timeout' => 1800, 'user_key' => 'user_id'],
            'urls' => ['login' => 'login.php', 'home' => 'index.php'],
        ];

        return $settings[$key] ?? $default;
    }
}

final class OrdersPageSessionManager
{
    public static function userId(): ?int
    {
        return 123;
    }

    public static function active(): bool
    {
        return true;
    }

    public static function token(): string
    {
        return 'mock_csrf_token_abc123';
    }
}

echo "==================================================\n";
echo "          ORDERS PAGE SYSTEM LOGIC ENGINE        \n";
echo "==================================================\n\n";

$userId = OrdersPageSessionManager::userId();
$isSessionActive = OrdersPageSessionManager::active();

if ($userId === null || !$isSessionActive) {
    echo "[FLOWCHART PATH -> NO]: Redirecting to Login Screen.\n";
    echo "DISPLAY: 'Please log in to view your orders'\n";
    exit;
}

echo "[FLOWCHART PATH -> YES]: User Session Verified Active.\n";

$dbError = false;
$orders = [];

try {
    $orders = [
        [
            'order_ID' => 98231,
            'order_Reference' => '#ORD-98231',
            'order_Date' => 'October 22, 2026',
            'total_Amount' => 1250.00,
            'order_Status' => 'Pending',
        ],
    ];
} catch (Throwable $throwable) {
    $dbError = true;
}

if ($dbError) {
    echo "[FLOWCHART PATH -> ERROR]: Display Error Message Screen.\n";
} elseif (empty($orders)) {
    echo "[FLOWCHART PATH -> NO]: Display empty orders message and continue shopping.\n";
} else {
    echo "[FLOWCHART PATH -> YES]: Existing Order List Array Found.\n\n";
    echo "--- RENDERING ORDERS HISTORY LIST ---\n";

    foreach ($orders as $order) {
        echo "Order Reference : " . $order['order_Reference'] . "\n";
        echo "Date Placed     : " . $order['order_Date'] . "\n";
        echo "Total Amount    : ₱" . number_format((float) $order['total_Amount'], 2) . "\n";
        echo "Current Status  : [" . $order['order_Status'] . "]\n";
        echo "-------------------------------------\n";
        echo "\n[USER INTERACTION]: Selected Order ID " . $order['order_ID'] . " to Open Details.\n";

        if (in_array($order['order_Status'], ['Pending', 'Processing'], true)) {
            echo "[ELIGIBILITY CHECK -> YES]: Status is '" . $order['order_Status'] . "'. Rendering Cancellation Action Panel.\n";
            echo "SECURE ACTION TOKEN SECURED: " . OrdersPageSessionManager::token() . "\n";
        } else {
            echo "[ELIGIBILITY CHECK -> NO]: Status is '" . $order['order_Status'] . "'. Disabling Cancellation Buttons.\n";
        }
    }
}

echo "\n==================================================\n";
echo "            LOGIC PIPELINE EXECUTION END          \n";
echo "==================================================\n";
