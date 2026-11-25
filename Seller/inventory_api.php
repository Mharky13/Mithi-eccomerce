<?php
declare(strict_types=1);

require_once __DIR__ . '/../Backend/database.php';
require_once __DIR__ . '/../Backend/Auth.php';
require_once __DIR__ . '/inventory_Service.php';

use App\Inventory\InventoryService;

function inventoryRespond(bool $success, string $message, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['success' => $success, 'message' => $message] + $data);
    exit;
}

function inventoryInput(): array
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 16384) {
        inventoryRespond(false, 'Request is too large.', [], 413);
    }

    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType === 'application/json') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            inventoryRespond(false, 'Malformed JSON request.', [], 400);
        }
        return $input;
    }

    return $_POST;
}

function inventorySellerId(PDO $database, int $userId): int
{
    $query = $database->prepare("
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
        inventoryRespond(false, 'An active seller account is required.', [], 403);
    }

    return (int) $sellerId;
}

function inventoryRequireCsrf(array $input): void
{
    $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';
    if (
        !is_string($submitted)
        || !is_string($stored)
        || $stored === ''
        || $submitted === ''
        || !hash_equals($stored, $submitted)
    ) {
        inventoryRespond(false, 'Invalid or missing CSRF token.', [], 403);
    }
}

function handleInventoryApi(): never
{
    try {
        $sessions = new SessionManager();
        $sessions->start();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method === 'GET' && ($_GET['action'] ?? '') === 'csrf') {
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            inventoryRespond(true, 'CSRF token generated.', [
                'csrf_token' => $_SESSION['csrf_token'],
            ]);
        }

        $input = $method === 'POST' ? inventoryInput() : $_GET;
        $action = $input['action'] ?? $_GET['action'] ?? '';
        if (!is_string($action)) {
            inventoryRespond(false, 'Invalid action.', [], 400);
        }

        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId <= 0) {
            inventoryRespond(false, 'Please log in first.', [], 401);
        }

        $database = Database::connect();
        $sellerId = inventorySellerId($database, (int) $userId);
        $service = new InventoryService($database);

        if ($method === 'GET' && $action === 'list') {
            $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT);
            $pageSize = filter_var($input['page_size'] ?? 20, FILTER_VALIDATE_INT);
            if ($page === false || $pageSize === false) {
                inventoryRespond(false, 'Page and page_size must be integers.', [], 422);
            }
            $keyword = $input['keyword'] ?? '';
            $state = $input['state'] ?? '';
            if (!is_string($keyword) || !is_string($state)) {
                inventoryRespond(false, 'Invalid inventory filters.', [], 422);
            }
            $result = $service->listForSeller(
                $sellerId,
                $page,
                $pageSize,
                trim($keyword),
                trim($state)
            );
            inventoryRespond(true, 'Inventory retrieved successfully.', $result);
        }

        if ($method === 'POST' && $action === 'update') {
            inventoryRequireCsrf($input);
            $inventoryId = filter_var($input['inventory_id'] ?? null, FILTER_VALIDATE_INT);
            $reason = $input['reason'] ?? '';
            if ($inventoryId === false || $inventoryId <= 0 || !is_string($reason)) {
                inventoryRespond(false, 'Invalid inventory update.', [], 422);
            }
            $result = $service->updateStock(
                $inventoryId,
                $sellerId,
                $input['quantity'] ?? null,
                $reason,
                $input['low_stock_threshold'] ?? null
            );
            inventoryRespond(true, 'Stock updated successfully.', ['data' => $result]);
        }

        header('Allow: GET, POST');
        inventoryRespond(false, 'Invalid action or HTTP method.', [], 405);
    } catch (InvalidArgumentException $error) {
        inventoryRespond(false, $error->getMessage(), [], 422);
    } catch (\App\Inventory\InventoryException $error) {
        inventoryRespond(false, $error->getMessage(), [], 409);
    } catch (PDOException $error) {
        error_log('Inventory API database error: ' . $error->getMessage());
        inventoryRespond(false, 'Inventory data is temporarily unavailable.', [], 503);
    } catch (Throwable $error) {
        error_log('Inventory API error: ' . $error->getMessage());
        inventoryRespond(false, 'Something went wrong. Please try again.', [], 500);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    handleInventoryApi();
}
