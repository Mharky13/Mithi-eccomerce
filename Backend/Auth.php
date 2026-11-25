<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

final class ApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

final class ApiResult
{
    public function __construct(public readonly int $status, public readonly array $body)
    {
    }
}

final class ApiRequest
{
    public static function readJson(): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new ApiException(405, 'Use a POST request.');
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
            throw new ApiException(413, 'Request is too large.');
        }
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType !== 'application/json') {
            throw new ApiException(415, 'Send a JSON request.');
        }
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            throw new ApiException(400, 'Malformed JSON request.');
        }
        return $body;
    }
}

final class JsonResponse
{
    public static function send(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
        exit;
    }
}

final class AuthValidator
{
    public static function identifier(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 254) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return strtolower($value);
        }
        return preg_match('/^[A-Za-z0-9._-]{3,32}$/', $value) ? strtolower($value) : null;
    }

    public static function loginPassword(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 72;
    }

    public static function signupPassword(mixed $value): bool
    {
        return is_string($value) && strlen($value) >= 8 && strlen($value) <= 72;
    }

    public static function name(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value !== '' && strlen($value) <= 50 && preg_match("/^[\p{L} '-]+$/u", $value)
            ? $value
            : null;
    }

    public static function email(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        return strlen($value) <= 100 && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }
}

final class SessionManager
{
    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('mithi_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    public function completeLogin(int $userId, string $role): void
    {
        $this->start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = $role;
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => $cookie['samesite'] ?? 'Strict',
            ]);
        }

        session_destroy();
    }
}

final class AuthService
{
    public function __construct(
        private PDO $database,
        private SessionManager $sessions
    ) {
    }

    public function beginLogin(string $identifier, string $password): ApiResult
    {
        $keys = $this->throttleKeys($identifier);
        $this->assertNotRateLimited($keys);
        $query = $this->database->prepare(
            'SELECT u.user_ID AS id, u.user_Email AS email, COALESCE(c.customer_FN, \'\') AS first_name, COALESCE(c.customer_LN, \'\') AS last_name, u.user_Password AS password_hash, LOWER(u.user_Role) AS role, LOWER(u.user_Status) AS account_status FROM USERS u LEFT JOIN CUSTOMERS c ON c.user_ID = u.user_ID WHERE u.user_Email = :email LIMIT 1'
        );
        $query->execute(['email' => $identifier]);
        $user = $query->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->recordFailedLogin($keys);
            throw new ApiException(401, 'Invalid email/username or password.');
        }
        if ($user['account_status'] !== 'active') {
            throw new ApiException(403, 'This account is not available for login.');
        }

        $this->clearLoginAttempts($keys);
        $this->sessions->completeLogin((int) $user['id'], $user['role']);
        return new ApiResult(200, ['success' => true, 'user' => $this->publicUser($user)]);
    }

    public function signup(string $firstName, string $lastName, string $email, string $password): ApiResult
    {
        try {
            $this->database->beginTransaction();
            $insertUser = $this->database->prepare(
                "INSERT INTO USERS (user_Email, user_Password, user_Role, user_Status) VALUES (:email, :password_hash, 'Customer', 'Active')"
            );
            $insertUser->execute([
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $this->database->lastInsertId();
            $insertCustomer = $this->database->prepare(
                'INSERT INTO CUSTOMERS (user_ID, customer_FN, customer_LN) VALUES (:user_id, :first_name, :last_name)'
            );
            $insertCustomer->execute([
                'user_id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            if (!$error instanceof PDOException) {
                throw $error;
            }
            if ((string) $error->getCode() === '23000') {
                throw new ApiException(409, 'An account with this email already exists.');
            }
            throw $error;
        }
        return new ApiResult(201, ['success' => true, 'message' => 'Account created.']);
    }

    private function throttleKeys(string $identifier): array
    {
        $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return [hash('sha256', 'identifier:' . strtolower($identifier) . ':' . $address), hash('sha256', 'address:' . $address)];
    }

    private function assertNotRateLimited(array $keys): void
    {
        $query = $this->database->prepare('SELECT locked_until FROM login_attempts WHERE bucket_key IN (:first_key, :second_key)');
        $query->execute(['first_key' => $keys[0], 'second_key' => $keys[1]]);
        foreach ($query->fetchAll() as $row) {
            if ($row['locked_until'] !== null && strtotime($row['locked_until'] . ' UTC') > time()) {
                throw new ApiException(429, 'Too many attempts. Try again in 15 minutes.');
            }
        }
    }

    private function recordFailedLogin(array $keys): void
    {
        $this->database->beginTransaction();
        try {
            foreach ($keys as $key) {
                $insert = $this->database->prepare(
                    'INSERT INTO login_attempts (bucket_key, attempts, window_started_at) VALUES (:key, 0, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE bucket_key = VALUES(bucket_key)'
                );
                $insert->execute(['key' => $key]);
                $select = $this->database->prepare('SELECT attempts, window_started_at FROM login_attempts WHERE bucket_key = :key FOR UPDATE');
                $select->execute(['key' => $key]);
                $row = $select->fetch();
                $expired = strtotime($row['window_started_at'] . ' UTC') < time() - 900;
                $attempts = $expired ? 1 : (int) $row['attempts'] + 1;
                $update = $this->database->prepare(
                    'UPDATE login_attempts SET attempts = :attempts, window_started_at = :started_at, locked_until = :locked_until WHERE bucket_key = :key'
                );
                $update->execute([
                    'attempts' => $attempts,
                    'started_at' => $expired ? gmdate('Y-m-d H:i:s') : $row['window_started_at'],
                    'locked_until' => $attempts >= 5 ? gmdate('Y-m-d H:i:s', time() + 900) : null,
                    'key' => $key,
                ]);
            }
            $this->database->commit();
        } catch (Throwable $error) {
            $this->database->rollBack();
            throw $error;
        }
    }

    private function clearLoginAttempts(array $keys): void
    {
        $delete = $this->database->prepare('DELETE FROM login_attempts WHERE bucket_key IN (:first_key, :second_key)');
        $delete->execute(['first_key' => $keys[0], 'second_key' => $keys[1]]);
    }

    private function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }
}

abstract class ApiController
{
    private ?AuthService $service = null;

    final public function handle(): never
    {
        try {
            $result = $this->process(ApiRequest::readJson());
            JsonResponse::send($result->status, $result->body);
        } catch (ApiException $error) {
            JsonResponse::send($error->status, ['success' => false, 'message' => $error->getMessage()]);
        } catch (Throwable $error) {
            error_log(static::class . ' error: ' . $error->getMessage());
            JsonResponse::send(500, ['success' => false, 'message' => 'The request is temporarily unavailable. Please try again.']);
        }
    }

    abstract protected function process(array $request): ApiResult;

    protected function authService(): AuthService
    {
        if ($this->service === null) {
            $this->service = new AuthService(Database::connect(), new SessionManager());
        }
        return $this->service;
    }
}

final class LoginController extends ApiController
{
    protected function process(array $request): ApiResult
    {
        if (($request['action'] ?? 'login') !== 'login') {
            throw new ApiException(400, 'Unsupported login action.');
        }

        $identifier = AuthValidator::email($request['identifier'] ?? $request['email'] ?? null);
        $password = $request['password'] ?? null;
        if ($identifier === null || !AuthValidator::loginPassword($password)) {
            throw new ApiException(422, 'Enter a valid email and password.');
        }
        return $this->authService()->beginLogin($identifier, $password);
    }
}

final class SignupController extends ApiController
{
    protected function process(array $request): ApiResult
    {
        $action = $request['action'] ?? '';
        if ($action !== 'signup') {
            throw new ApiException(400, 'Unsupported account action.');
        }
        $firstName = AuthValidator::name($request['firstName'] ?? null);
        $lastName = AuthValidator::name($request['lastName'] ?? null);
        $email = AuthValidator::email($request['email'] ?? null);
        $password = $request['password'] ?? null;
        if ($firstName === null || $lastName === null || $email === null || !AuthValidator::signupPassword($password)) {
            throw new ApiException(422, 'Check your name, email, and password. Passwords must be 8 to 72 characters.');
        }
        return $this->authService()->signup($firstName, $lastName, $email, $password);
    }
}