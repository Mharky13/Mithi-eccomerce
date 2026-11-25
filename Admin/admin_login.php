<?php
declare(strict_types=1);

require_once __DIR__ . '/AdminAuth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$sessions = new SessionManager();
$sessions->start();

try {
    $db = Database::connect();
    $auth = new AdminAuthService($db, $sessions);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET' && ($_GET['action'] ?? '') === 'csrf') {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        respond(200, ['success' => true, 'data' => ['csrf_token' => $_SESSION['csrf_token']]]);
    }

    if ($method === 'GET' && ($_GET['action'] ?? '') === 'session') {
        respond(200, ['success' => true, 'data' => $auth->authorize()]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }

    $input = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($input)) {
        respond(400, ['success' => false, 'message' => 'Send a valid JSON request.']);
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
        respond(403, ['success' => false, 'message' => 'Invalid or missing CSRF token.']);
    }

    if (($input['action'] ?? '') === 'logout') {
        $sessions->destroy();
        respond(200, ['success' => true]);
    }

    if (($input['action'] ?? '') !== 'login') {
        respond(400, ['success' => false, 'message' => 'Invalid action.']);
    }

    $result = $auth->authenticate(
        is_string($input['identifier'] ?? null) ? $input['identifier'] : '',
        is_string($input['password'] ?? null) ? $input['password'] : ''
    );

    if ($result['status'] !== 200) {
        respond($result['status'], ['success' => false, 'message' => $result['message']]);
    }

    respond(200, ['success' => true, 'data' => $result['admin']]);
} catch (AdminAuthException $error) {
    respond($error->httpStatus, ['success' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Admin login API error: ' . $error->getMessage());
    respond(500, ['success' => false, 'message' => 'Admin sign-in is temporarily unavailable.']);
}