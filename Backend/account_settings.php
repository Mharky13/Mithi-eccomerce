<?php
declare(strict_types=1);

namespace App\AccountSettings;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/Auth.php';

final class AccountSettingsException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

final class CustomerAccountRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function profile(int $userId): ?array
    {
        $query = $this->database->prepare("
            SELECT c.customer_ID AS customer_id,
            c.customer_FN AS first_name,
            c.customer_LN AS last_name,
            c.customer_Phone AS phone,
            c.customer_Address AS address,
            c.customer_RegDate AS registered_at,
            u.user_ID AS user_id,
            u.user_Email AS email
            FROM CUSTOMERS c
            INNER JOIN USERS u ON u.user_ID = c.user_ID
            WHERE u.user_ID = :user_id
            AND LOWER(TRIM(u.user_Role)) = 'customer'
            AND LOWER(TRIM(u.user_Status)) = 'active'
            LIMIT 1
        ");
        $query->execute(['user_id' => $userId]);
        $profile = $query->fetch(PDO::FETCH_ASSOC);

        return $profile === false ? null : $profile;
    }

    public function emailExists(string $email, int $userId): bool
    {
        $query = $this->database->prepare(
            'SELECT 1 FROM USERS WHERE LOWER(user_Email) = LOWER(:email) AND user_ID <> :user_id LIMIT 1'
        );
        $query->execute(['email' => $email, 'user_id' => $userId]);

        return $query->fetchColumn() !== false;
    }

    public function phoneExists(string $phone, int $customerId): bool
    {
        $query = $this->database->prepare(
            'SELECT 1 FROM CUSTOMERS WHERE customer_Phone = :phone AND customer_ID <> :customer_id LIMIT 1'
        );
        $query->execute(['phone' => $phone, 'customer_id' => $customerId]);

        return $query->fetchColumn() !== false;
    }

    public function updateProfile(int $userId, int $customerId, array $data): void
    {
        $this->database->beginTransaction();
        try {
            $user = $this->database->prepare(
                'UPDATE USERS SET user_Email = :email WHERE user_ID = :user_id'
            );
            $user->execute(['email' => $data['email'], 'user_id' => $userId]);

            $customer = $this->database->prepare("
                UPDATE CUSTOMERS
                SET customer_FN = :first_name,
                    customer_LN = :last_name,
                    customer_Phone = :phone
                WHERE customer_ID = :customer_id AND user_ID = :user_id
            ");
            $customer->execute([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'],
                'customer_id' => $customerId,
                'user_id' => $userId,
            ]);
            if ($customer->rowCount() > 1) {
                throw new RuntimeException('Unexpected customer profile update count.');
            }

            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    public function updateAddress(int $userId, int $customerId, string $address): void
    {
        $query = $this->database->prepare("
            UPDATE CUSTOMERS
            SET customer_Address = :address
            WHERE customer_ID = :customer_id AND user_ID = :user_id
        ");
        $query->execute([
            'address' => $address,
            'customer_id' => $customerId,
            'user_id' => $userId,
        ]);
        if ($query->rowCount() > 1) {
            throw new RuntimeException('Unexpected customer address update count.');
        }
    }

    public function passwordHash(int $userId): ?string
    {
        $query = $this->database->prepare(
            "SELECT user_Password FROM USERS WHERE user_ID = :user_id AND LOWER(TRIM(user_Role)) = 'customer' AND LOWER(TRIM(user_Status)) = 'active' LIMIT 1"
        );
        $query->execute(['user_id' => $userId]);
        $hash = $query->fetchColumn();

        return $hash === false ? null : (string) $hash;
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        $query = $this->database->prepare("
            UPDATE USERS
            SET user_Password = :password_hash
            WHERE user_ID = :user_id
              AND LOWER(TRIM(user_Role)) = 'customer'
              AND LOWER(TRIM(user_Status)) = 'active'
        ");
        $query->execute(['password_hash' => $passwordHash, 'user_id' => $userId]);
        if ($query->rowCount() !== 1) {
            throw new RuntimeException('Customer account could not be updated.');
        }
    }
}

final class CustomerAccountService
{
    private const IDLE_TIMEOUT = 1800;
    private const MAX_PASSWORD_ATTEMPTS = 5;
    private const PHONE_PATTERN = '/^(09\d{9}|\+639\d{9})$/';

    public function __construct(
        private CustomerAccountRepository $repository,
        private \SessionManager $sessions
    ) {
    }

    public function getProfile(int $userId): array
    {
        $profile = $this->loadProfile($userId);
        unset($profile['user_id']);

        return ['section' => 'profile', 'profile' => $profile];
    }

    public function editProfile(int $userId, array $input): array
    {
        $this->requireCsrf();
        $profile = $this->loadProfile($userId);
        $data = $this->validateProfile($input, $profile, $userId);
        $this->repository->updateProfile($userId, (int) $profile['customer_id'], $data);
        $updated = $this->loadProfile($userId);
        unset($updated['user_id']);

        return [
            'section' => 'profile',
            'message' => 'Profile updated successfully.',
            'profile' => $updated,
        ];
    }

    public function manageAddress(int $userId, array $input): array
    {
        $this->requireCsrf();
        $profile = $this->loadProfile($userId);
        $address = $input['address'] ?? null;
        if (!is_string($address) || trim($address) === '' || mb_strlen(trim($address)) > 500) {
            throw new AccountSettingsException(
                422,
                'Address is required and must be 500 characters or fewer.',
                ['address' => 'Enter an address up to 500 characters.']
            );
        }
        $address = trim($address);
        $this->repository->updateAddress($userId, (int) $profile['customer_id'], $address);

        return [
            'section' => 'address',
            'message' => 'Address updated successfully.',
            'address' => $address,
        ];
    }

    public function changePassword(int $userId, array $input): array
    {
        $this->requireCsrf();
        $currentHash = $this->repository->passwordHash($userId);
        $currentPassword = is_string($input['current_password'] ?? null)
            ? $input['current_password']
            : '';
        if ($currentHash === null || !password_verify($currentPassword, $currentHash)) {
            $attempts = (int) ($_SESSION['account_password_attempts'] ?? 0) + 1;
            $_SESSION['account_password_attempts'] = $attempts;
            if ($attempts >= self::MAX_PASSWORD_ATTEMPTS) {
                $this->sessions->destroy();
                throw new AccountSettingsException(
                    401,
                    'Too many incorrect password attempts. Please log in again.'
                );
            }
            throw new AccountSettingsException(422, 'Current password is incorrect.');
        }
        unset($_SESSION['account_password_attempts']);

        $newPassword = is_string($input['new_password'] ?? null) ? $input['new_password'] : '';
        $confirmation = is_string($input['confirm_password'] ?? null) ? $input['confirm_password'] : '';
        $errors = [];
        if (
            strlen($newPassword) < 8
            || strlen($newPassword) > 72
            || !preg_match('/[A-Z]/', $newPassword)
            || !preg_match('/[a-z]/', $newPassword)
            || !preg_match('/\d/', $newPassword)
        ) {
            $errors['new_password'] = 'Use 8 to 72 characters with uppercase, lowercase, and a number.';
        } elseif (password_verify($newPassword, $currentHash)) {
            $errors['new_password'] = 'New password must be different from the current password.';
        }
        if ($newPassword !== $confirmation) {
            $errors['confirm_password'] = 'Password confirmation does not match.';
        }
        if ($errors !== []) {
            throw new AccountSettingsException(422, 'Please correct the password fields.', $errors);
        }

        $this->repository->updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
        unset($_SESSION['account_password_attempts']);

        return ['section' => 'password', 'message' => 'Password changed successfully.'];
    }

    public function logout(): array
    {
        $this->requireCsrf();
        $this->sessions->destroy();

        return ['section' => 'logout', 'message' => 'Logged out successfully.'];
    }

    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }

    private function loadProfile(int $userId): array
    {
        $profile = $this->repository->profile($userId);
        if ($profile === null) {
            throw new AccountSettingsException(403, 'An active customer account is required.');
        }

        return $profile;
    }

    private function validateProfile(array $input, array $profile, int $userId): array
    {
        $firstName = \AuthValidator::name($input['first_name'] ?? null);
        $lastName = \AuthValidator::name($input['last_name'] ?? null);
        $email = \AuthValidator::email($input['email'] ?? null);
        $phone = is_string($input['phone'] ?? null) ? trim($input['phone']) : '';
        $errors = [];

        if ($firstName === null) {
            $errors['first_name'] = 'Enter a valid first name.';
        }
        if ($lastName === null) {
            $errors['last_name'] = 'Enter a valid last name.';
        }
        if ($email === null) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (strtolower($email) !== strtolower((string) $profile['email'])
            && $this->repository->emailExists($email, $userId)) {
            $errors['email'] = 'This email address is already in use.';
        }
        if ($phone !== '' && !preg_match(self::PHONE_PATTERN, $phone)) {
            $errors['phone'] = 'Enter a valid Philippine mobile number.';
        } elseif ($phone !== '' && $phone !== (string) ($profile['phone'] ?? '')
            && $this->repository->phoneExists($phone, (int) $profile['customer_id'])) {
            $errors['phone'] = 'This phone number is already in use.';
        }

        if ($errors !== []) {
            throw new AccountSettingsException(422, 'Please correct the profile fields.', $errors);
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone === '' ? null : $phone,
        ];
    }

    private function requireCsrf(): void
    {
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (
            !is_string($provided)
            || !is_string($expected)
            || $expected === ''
            || !hash_equals($expected, $provided)
        ) {
            throw new AccountSettingsException(403, 'Invalid or missing CSRF token.');
        }
    }
}

final class AccountSettingsController
{
    public function __construct(
        private CustomerAccountService $service,
        private \SessionManager $sessions
    ) {
    }

    public function handle(): never
    {
        header('Cache-Control: no-store');
        try {
            $this->sessions->start();
            $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
            if ($userId === false || $userId <= 0) {
                throw new AccountSettingsException(401, 'Authentication required.');
            }
            $this->assertSessionActive();

            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $action = $method === 'GET'
                ? ($_GET['action'] ?? 'GET_PROFILE')
                : $this->readInput();

            if ($method === 'GET' && $action === 'CSRF') {
                $this->respond(200, [
                    'success' => true,
                    'data' => ['csrf_token' => $this->service->csrfToken()],
                    'error' => null,
                ]);
            }
            if ($method !== 'POST' && $method !== 'GET') {
                header('Allow: GET, POST');
                throw new AccountSettingsException(405, 'Method not allowed.');
            }
            if ($method === 'GET' && $action !== 'GET_PROFILE') {
                throw new AccountSettingsException(400, 'Unsupported account action.');
            }

            if ($method === 'GET') {
                $result = $this->service->getProfile((int) $userId);
            } else {
                $input = $action;
                $result = match ($input['action'] ?? '') {
                    'EDIT_PROFILE' => $this->service->editProfile((int) $userId, $input),
                    'MANAGE_ADDRESS' => $this->service->manageAddress((int) $userId, $input),
                    'CHANGE_PASSWORD' => $this->service->changePassword((int) $userId, $input),
                    'LOGOUT' => $this->service->logout(),
                    default => throw new AccountSettingsException(400, 'Invalid account action.'),
                };
            }

            $this->respond(200, ['success' => true, 'data' => $result, 'error' => null]);
        } catch (AccountSettingsException $error) {
            $this->respond($error->httpStatus, [
                'success' => false,
                'data' => null,
                'error' => [
                    'message' => $error->getMessage(),
                    'details' => $error->details,
                ],
            ]);
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                $this->respond(409, [
                    'success' => false,
                    'data' => null,
                    'error' => ['message' => 'Email or phone number is already in use.', 'details' => []],
                ]);
            }
            error_log('Customer account settings database error: ' . $error->getMessage());
            $this->respond(503, [
                'success' => false,
                'data' => null,
                'error' => ['message' => 'Account settings are temporarily unavailable.', 'details' => []],
            ]);
        } catch (Throwable $error) {
            error_log('Customer account settings API error: ' . $error->getMessage());
            $this->respond(500, [
                'success' => false,
                'data' => null,
                'error' => ['message' => 'Something went wrong. Please try again.', 'details' => []],
            ]);
        }
    }

    private function readInput(): array
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 16384) {
            throw new AccountSettingsException(413, 'Request is too large.');
        }
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType === 'application/json') {
            $input = json_decode(file_get_contents('php://input') ?: '', true);
            if (!is_array($input)) {
                throw new AccountSettingsException(400, 'Malformed JSON request.');
            }

            return $input;
        }
        if ($contentType === 'application/x-www-form-urlencoded') {
            return $_POST;
        }

        throw new AccountSettingsException(415, 'Send a JSON request.');
    }

    private function assertSessionActive(): void
    {
        $now = time();
        $lastActivity = filter_var($_SESSION['last_activity'] ?? $now, FILTER_VALIDATE_INT);
        if ($lastActivity === false || $lastActivity > $now) {
            $this->sessions->destroy();
            throw new AccountSettingsException(401, 'Session expired. Please log in again.');
        }
        if ($now - $lastActivity > 1800) {
            $this->sessions->destroy();
            throw new AccountSettingsException(401, 'Session expired. Please log in again.');
        }
        $_SESSION['last_activity'] = $now;
    }

    private function respond(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $sessions = new \SessionManager();
        $sessions->start();
        $repository = new CustomerAccountRepository(\Database::connect());
        (new AccountSettingsController(
            new CustomerAccountService($repository, $sessions),
            $sessions
        ))->handle();
    } catch (Throwable $error) {
        error_log('Customer account settings bootstrap error: ' . $error->getMessage());
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'success' => false,
            'data' => null,
            'error' => [
                'message' => 'Account settings are temporarily unavailable.',
                'details' => [],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }
}
