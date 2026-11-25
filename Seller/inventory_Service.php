<?php
declare(strict_types=1);

namespace App\Inventory;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class InventoryException extends RuntimeException
{
}

final class InventoryService
{
    public const MAX_QUANTITY = 1000000;
    public const DEFAULT_LOW_STOCK_THRESHOLD = 5;
    public const MAX_REASON_LENGTH = 255;
    public const MAX_PAGE_SIZE = 100;

    public function __construct(private PDO $database)
    {
    }

    public function listForSeller(
        int $sellerId,
        int $page = 1,
        int $pageSize = 20,
        string $keyword = '',
        string $state = ''
    ): array {
        if ($page < 1 || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException('Invalid page or page size.');
        }
        if ($state !== '' && !in_array($state, ['In Stock', 'Low Stock', 'Out of Stock'], true)) {
            throw new InvalidArgumentException('Invalid stock-state filter.');
        }

        $where = ['p.seller_ID = :seller_id', 'i.is_active = 1'];
        $parameters = ['seller_id' => $sellerId];
        if ($keyword !== '') {
            $where[] = 'p.prod_name LIKE :keyword';
            $parameters['keyword'] = '%' . $keyword . '%';
        }
        $stateSql = "CASE
            WHEN i.inventory_QTY - i.inventory_Reserved <= 0 THEN 'Out of Stock'
            WHEN i.inventory_QTY - i.inventory_Reserved <= i.low_stock_threshold THEN 'Low Stock'
            ELSE 'In Stock'
        END";
        if ($state !== '') {
            $where[] = $stateSql . ' = :stock_state';
            $parameters['stock_state'] = $state;
        }
        $whereSql = implode(' AND ', $where);

        $count = $this->database->prepare("
            SELECT COUNT(*)
            FROM INVENTORY i
            INNER JOIN PRODUCTS p ON p.prod_ID = i.prod_ID
            WHERE {$whereSql}
        ");
        $this->bind($count, $parameters);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $query = $this->database->prepare("
            SELECT i.inventory_ID AS inventory_id,
                i.prod_ID AS product_id,
                p.prod_name AS product_name,
                p.availability_type AS product_availability,
                i.inventory_QTY AS quantity,
                i.inventory_Reserved AS reserved,
                GREATEST(i.inventory_QTY - i.inventory_Reserved, 0) AS available,
                i.low_stock_threshold,
                {$stateSql} AS stock_state,
                i.region, i.province, i.city_municipality,
                i.updated_at
            FROM INVENTORY i
            INNER JOIN PRODUCTS p ON p.prod_ID = i.prod_ID
            WHERE {$whereSql}
            ORDER BY p.prod_name ASC, i.inventory_ID ASC
            LIMIT :row_limit OFFSET :row_offset
        ");
        $this->bind($query, $parameters);
        $query->bindValue(':row_limit', $pageSize, PDO::PARAM_INT);
        $query->bindValue(':row_offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $query->execute();
        $items = $query->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as &$item) {
            foreach (['inventory_id', 'product_id', 'quantity', 'reserved', 'available', 'low_stock_threshold'] as $field) {
                $item[$field] = (int) $item[$field];
            }
        }
        unset($item);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => (int) ceil($total / $pageSize),
            ],
        ];
    }

    public function updateStock(
        int $inventoryId,
        int $sellerId,
        mixed $newQuantity,
        string $reason,
        mixed $newThreshold = null
    ): array {
        $quantity = $this->validateQuantity($newQuantity);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException('A valid adjustment reason (1-255 characters) is required.');
        }
        $threshold = $newThreshold === null || $newThreshold === ''
            ? null
            : $this->validateQuantity($newThreshold);

        return $this->transaction(function () use ($inventoryId, $sellerId, $quantity, $reason, $threshold): array {
            $inventory = $this->ownedInventory($inventoryId, $sellerId, true);
            if ((int) $inventory['is_active'] !== 1) {
                throw new InventoryException('This inventory record is inactive.');
            }
            if ($quantity < (int) $inventory['inventory_Reserved']) {
                throw new InventoryException('Stock cannot be lower than the quantity reserved by orders.');
            }

            $oldState = $this->stockState($inventory);
            $oldQuantity = (int) $inventory['inventory_QTY'];
            $update = $this->database->prepare("
                UPDATE INVENTORY
                SET inventory_QTY = :quantity,
                    low_stock_threshold = COALESCE(:threshold, low_stock_threshold),
                    updated_at = NOW()
                WHERE inventory_ID = :inventory_id
            ");
            $update->execute([
                'quantity' => $quantity,
                'threshold' => $threshold,
                'inventory_id' => $inventoryId,
            ]);
            $this->logAdjustment(
                $inventoryId,
                $oldQuantity,
                $quantity,
                $reason,
                (string) $sellerId
            );
            $this->recordStockChange($inventoryId, $oldState);

            $updated = $this->ownedInventory($inventoryId, $sellerId);
            return [
                'inventory' => $updated,
                'available' => $this->available($updated),
                'stock_state' => $this->stockState($updated),
                'notification_required' => $this->stockState($updated) !== $oldState
                    && in_array($this->stockState($updated), ['Low Stock', 'Out of Stock'], true),
            ];
        });
    }

    public function reserveStock(int $orderId, array $items): void
    {
        if ($orderId <= 0 || $items === []) {
            throw new InvalidArgumentException('A valid order and at least one item are required.');
        }

        $quantities = $this->normalizeItems($items);
        $this->transaction(function () use ($orderId, $quantities): void {
            $order = $this->database->prepare(
                'SELECT order_ID FROM ORDERS WHERE order_ID = :order_id FOR UPDATE'
            );
            $order->execute(['order_id' => $orderId]);
            if ($order->fetchColumn() === false) {
                throw new InventoryException('Order not found.');
            }

            $active = $this->database->prepare("
                SELECT COUNT(*) FROM stock_reservations
                WHERE order_ID = :order_id AND status IN ('Reserved', 'Deducted')
            ");
            $active->execute(['order_id' => $orderId]);
            if ((int) $active->fetchColumn() > 0) {
                throw new InventoryException('This order already has an active or completed stock reservation.');
            }

            foreach ($quantities as $inventoryId => $quantity) {
                $row = $this->database->prepare("
                    SELECT i.inventory_ID, i.inventory_QTY, i.inventory_Reserved,
                           i.is_active, p.prod_ID, p.seller_ID, p.availability_type,
                           COALESCE(ps.listing_Status, 'Active') AS listing_status
                    FROM INVENTORY i
                    INNER JOIN PRODUCTS p ON p.prod_ID = i.prod_ID
                    LEFT JOIN SELLER_PRODUCT_SETTINGS ps ON ps.prod_ID = p.prod_ID
                    WHERE i.inventory_ID = :inventory_id
                    FOR UPDATE
                ");
                $row->execute(['inventory_id' => $inventoryId]);
                $inventory = $row->fetch(PDO::FETCH_ASSOC);
                if (
                    !$inventory
                    || (int) $inventory['is_active'] !== 1
                    || $inventory['listing_status'] !== 'Active'
                    || $inventory['availability_type'] === 'Out of Stock'
                ) {
                    throw new InventoryException('An item is unavailable (inventory ID ' . $inventoryId . ').');
                }

                $update = $this->database->prepare("
                    UPDATE INVENTORY
                    SET inventory_Reserved = inventory_Reserved + :quantity,
                        updated_at = NOW()
                    WHERE inventory_ID = :inventory_id
                      AND is_active = 1
                      AND inventory_QTY - inventory_Reserved >= :required_quantity
                ");
                $update->execute([
                    'quantity' => $quantity,
                    'inventory_id' => $inventoryId,
                    'required_quantity' => $quantity,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new InventoryException('Not enough available stock for inventory ID ' . $inventoryId . '.');
                }

                $reservation = $this->database->prepare("
                    INSERT INTO stock_reservations
                        (order_ID, inventory_ID, quantity, status, created_at, updated_at)
                    VALUES (:order_id, :inventory_id, :quantity, 'Reserved', NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        quantity = VALUES(quantity),
                        status = 'Reserved',
                        release_reason = NULL,
                        updated_at = NOW()
                ");
                $reservation->execute([
                    'order_id' => $orderId,
                    'inventory_id' => $inventoryId,
                    'quantity' => $quantity,
                ]);
                $this->recordStockChange($inventoryId, $this->stockState($inventory));
            }
        });
    }

    public function deductStock(int $orderId, string $reason = 'Order shipped'): void
    {
        $this->transaction(function () use ($orderId, $reason): void {
            $reservations = $this->reservations($orderId, 'Reserved');
            if ($reservations === []) {
                throw new InventoryException('No reserved stock is available to deduct.');
            }

            foreach ($reservations as $reservation) {
                $inventoryId = (int) $reservation['inventory_ID'];
                $quantity = (int) $reservation['quantity'];
                $inventory = $this->lockInventory($inventoryId);
                if (
                    !$inventory
                    || (int) $inventory['inventory_QTY'] < $quantity
                    || (int) $inventory['inventory_Reserved'] < $quantity
                ) {
                    throw new InventoryException('Stock reservation data is inconsistent.');
                }
                $oldState = $this->stockState($inventory);
                $update = $this->database->prepare("
                    UPDATE INVENTORY
                    SET inventory_QTY = inventory_QTY - :quantity,
                        inventory_Reserved = inventory_Reserved - :reserved_quantity,
                        updated_at = NOW()
                    WHERE inventory_ID = :inventory_id
                      AND inventory_QTY >= :required_quantity
                      AND inventory_Reserved >= :required_reserved
                ");
                $update->execute([
                    'quantity' => $quantity,
                    'reserved_quantity' => $quantity,
                    'inventory_id' => $inventoryId,
                    'required_quantity' => $quantity,
                    'required_reserved' => $quantity,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new InventoryException('Could not deduct reserved stock.');
                }
                $this->markReservation((int) $reservation['reservation_ID'], 'Deducted', null);
                $this->logAdjustment(
                    $inventoryId,
                    (int) $inventory['inventory_QTY'],
                    (int) $inventory['inventory_QTY'] - $quantity,
                    $reason,
                    'System',
                    $orderId
                );
                $this->recordStockChange($inventoryId, $oldState);
            }
        });
    }

    public function releaseReservation(int $orderId, string $reason): void
    {
        $reason = trim($reason);
        if ($orderId <= 0 || $reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException('A valid order and release reason are required.');
        }

        $this->transaction(function () use ($orderId, $reason): void {
            $reservations = $this->reservations($orderId, 'Reserved');
            if ($reservations === []) {
                throw new InventoryException('No releasable stock reservations were found.');
            }
            foreach ($reservations as $reservation) {
                $inventoryId = (int) $reservation['inventory_ID'];
                $quantity = (int) $reservation['quantity'];
                $inventory = $this->lockInventory($inventoryId);
                if (!$inventory || (int) $inventory['inventory_Reserved'] < $quantity) {
                    throw new InventoryException('Stock reservation data is inconsistent.');
                }
                $oldState = $this->stockState($inventory);
                $update = $this->database->prepare("
                    UPDATE INVENTORY
                    SET inventory_Reserved = inventory_Reserved - :quantity,
                        updated_at = NOW()
                    WHERE inventory_ID = :inventory_id
                      AND inventory_Reserved >= :required_quantity
                ");
                $update->execute([
                    'quantity' => $quantity,
                    'inventory_id' => $inventoryId,
                    'required_quantity' => $quantity,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new InventoryException('Could not release reserved stock.');
                }
                $this->markReservation((int) $reservation['reservation_ID'], 'Released', $reason);
                $this->logAdjustment(
                    $inventoryId,
                    (int) $inventory['inventory_QTY'],
                    (int) $inventory['inventory_QTY'],
                    $reason,
                    'System',
                    $orderId
                );
                $this->recordStockChange($inventoryId, $oldState);
            }
        });
    }

    public function handleOrderStatusChange(int $orderId, string $newStatus, array $items = []): void
    {
        $status = strtolower(trim($newStatus));
        if (in_array($status, ['pending', 'confirmed'], true)) {
            if ($this->hasReservation($orderId)) {
                return;
            }
            if ($items === []) {
                throw new InvalidArgumentException('Order items are required to reserve stock.');
            }
            $this->reserveStock($orderId, $items);
            return;
        }
        if ($status === 'shipped') {
            $this->deductStock($orderId);
            return;
        }
        if (in_array($status, ['cancelled', 'payment failed', 'payment_failed'], true)) {
            $this->releaseReservation($orderId, 'Order ' . $newStatus);
        }
    }

    public static function stockState(array $inventory): string
    {
        $available = self::available($inventory);
        if ($available === 0) {
            return 'Out of Stock';
        }
        if ($available <= (int) $inventory['low_stock_threshold']) {
            return 'Low Stock';
        }
        return 'In Stock';
    }

    public static function available(array $inventory): int
    {
        return max(0, (int) $inventory['inventory_QTY'] - (int) $inventory['inventory_Reserved']);
    }

    private function validateQuantity(mixed $value): int
    {
        $quantity = filter_var($value, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 0 || $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException(
                'Stock quantity must be a whole number from 0 to ' . self::MAX_QUANTITY . '.'
            );
        }

        return $quantity;
    }

    private function normalizeItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $inventoryId = filter_var($item['inventory_ID'] ?? $item['inventory_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($inventoryId === false || $inventoryId < 1 || $quantity === false || $quantity < 1) {
                throw new InvalidArgumentException('Each item requires an inventory ID and positive quantity.');
            }
            $normalized[$inventoryId] = ($normalized[$inventoryId] ?? 0) + $quantity;
            if ($normalized[$inventoryId] > self::MAX_QUANTITY) {
                throw new InvalidArgumentException('Requested stock quantity is too large.');
            }
        }
        ksort($normalized);

        return $normalized;
    }

    private function ownedInventory(int $inventoryId, int $sellerId, bool $lock = false): array
    {
        $query = $this->database->prepare("
            SELECT i.*, p.prod_name, p.seller_ID
            FROM INVENTORY i
            INNER JOIN PRODUCTS p ON p.prod_ID = i.prod_ID
            WHERE i.inventory_ID = :inventory_id
              AND p.seller_ID = :seller_id" . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute(['inventory_id' => $inventoryId, 'seller_id' => $sellerId]);
        $inventory = $query->fetch(PDO::FETCH_ASSOC);
        if ($inventory === false) {
            throw new InventoryException('Inventory record not found or you do not own this product.');
        }

        return $inventory;
    }

    private function lockInventory(int $inventoryId): array|false
    {
        $query = $this->database->prepare(
            'SELECT * FROM INVENTORY WHERE inventory_ID = :inventory_id FOR UPDATE'
        );
        $query->execute(['inventory_id' => $inventoryId]);

        return $query->fetch(PDO::FETCH_ASSOC);
    }

    private function reservations(int $orderId, string $status): array
    {
        $query = $this->database->prepare("
            SELECT reservation_ID, inventory_ID, quantity
            FROM stock_reservations
            WHERE order_ID = :order_id AND status = :status
            ORDER BY inventory_ID ASC
            FOR UPDATE
        ");
        $query->execute(['order_id' => $orderId, 'status' => $status]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function hasReservation(int $orderId): bool
    {
        $query = $this->database->prepare("
            SELECT 1 FROM stock_reservations
            WHERE order_ID = :order_id AND status IN ('Reserved', 'Deducted')
            LIMIT 1
        ");
        $query->execute(['order_id' => $orderId]);

        return $query->fetchColumn() !== false;
    }

    private function markReservation(int $reservationId, string $status, ?string $reason): void
    {
        $query = $this->database->prepare("
            UPDATE stock_reservations
            SET status = :status, release_reason = :reason, updated_at = NOW()
            WHERE reservation_ID = :reservation_id AND status = 'Reserved'
        ");
        $query->execute([
            'status' => $status,
            'reason' => $reason,
            'reservation_id' => $reservationId,
        ]);
        if ($query->rowCount() !== 1) {
            throw new InventoryException('Reservation was already processed.');
        }
    }

    private function logAdjustment(
        int $inventoryId,
        int $oldQuantity,
        int $newQuantity,
        string $reason,
        string $changedBy,
        ?int $orderId = null
    ): void {
        $query = $this->database->prepare("
            INSERT INTO stock_adjustments
                (inventory_ID, old_quantity, new_quantity, reason, changed_by, order_ID, created_at)
            VALUES
                (:inventory_id, :old_quantity, :new_quantity, :reason, :changed_by, :order_id, NOW())
        ");
        $query->execute([
            'inventory_id' => $inventoryId,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'reason' => $reason,
            'changed_by' => $changedBy,
            'order_id' => $orderId,
        ]);
    }

    private function recordStockChange(int $inventoryId, string $previousState): void
    {
        $query = $this->database->prepare("
            SELECT i.*, p.seller_ID, p.prod_name
            FROM INVENTORY i
            INNER JOIN PRODUCTS p ON p.prod_ID = i.prod_ID
            WHERE i.inventory_ID = :inventory_id
        ");
        $query->execute(['inventory_id' => $inventoryId]);
        $inventory = $query->fetch(PDO::FETCH_ASSOC);
        if ($inventory === false) {
            return;
        }

        $currentState = self::stockState($inventory);
        if (
            $currentState !== $previousState
            && in_array($currentState, ['Low Stock', 'Out of Stock'], true)
        ) {
            $notification = $this->database->prepare("
                INSERT INTO inventory_notifications
                    (seller_ID, inventory_ID, product_ID, notification_type, message, is_read, created_at)
                VALUES
                    (:seller_id, :inventory_id, :product_id, :type, :message, 0, NOW())
            ");
            $notification->execute([
                'seller_id' => $inventory['seller_ID'],
                'inventory_id' => $inventoryId,
                'product_id' => $inventory['prod_ID'],
                'type' => $currentState,
                'message' => $inventory['prod_name'] . ' is now ' . $currentState . '.',
            ]);
        }

        $outbox = $this->database->prepare("
            INSERT INTO inventory_catalog_outbox (product_ID, event_type, created_at)
            VALUES (:product_id, 'STOCK_CHANGED', NOW())
        ");
        $outbox->execute(['product_id' => $inventory['prod_ID']]);
    }

    private function transaction(callable $action): mixed
    {
        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        }
        try {
            $result = $action();
            if ($ownsTransaction) {
                $this->database->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
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
