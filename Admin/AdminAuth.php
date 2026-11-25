<?php
declare(strict_types=1);

require_once __DIR__ . '/../Backend/Auth.php';

final class AdminAuthException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        string $message
    ) {
        parent::__construct($message);
    }
}

abstract class AuthenticatableUser
{
    public function __construct(
        private int $id,
        private string $email,
        private string $passwordHash,
        private string $role,
        private string $status
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function verifyPassword(string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->passwordHash);
    }

    public function isActive(): bool
    {
        return strtoupper(trim($this->status)) === 'ACTIVE';
    }

    abstract public function hasRequiredRole(): bool;
}

final class AdminUser extends AuthenticatableUser
{
    public function hasRequiredRole(): bool
    {
        return strtoupper(trim($this->getRole())) === 'ADMIN';
    }
}

final class AdminAuthService
{
    public function __construct(
        private PDO $database,
        private SessionManager $sessions
    ) {
    }

    public function authenticate(string $email, string $password): array
    {
        $email = AuthValidator::email($email);
        if ($email === null || !AuthValidator::loginPassword($password)) {
            return ['status' => 422, 'message' => 'Enter a valid email and password.'];
        }

        try {
            (new AuthService($this->database, $this->sessions))->beginLogin($email, $password);
        } catch (ApiException $error) {
            return ['status' => $error->status, 'message' => $error->getMessage()];
        }

        try {
            $admin = $this->authorize();
        } catch (AdminAuthException $error) {
            $this->sessions->destroy();
            return ['status' => $error->httpStatus, 'message' => $error->getMessage()];
        }

        return [
            'status' => 200,
            'message' => 'Admin login successful.',
            'admin' => $admin,
        ];
    }

    public function authorize(): array
    {
        $this->sessions->start();
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId <= 0) {
            throw new AdminAuthException(401, 'Please log in first.');
        }

        $query = $this->database->prepare("
            SELECT u.user_ID, u.user_Email, u.user_Password, u.user_Role, u.user_Status,
                   a.admin_ID, a.admin_Status
            FROM USERS u
            INNER JOIN ADMINS a ON a.user_ID = u.user_ID
            WHERE u.user_ID = :user_id
            LIMIT 1
        ");
        $query->execute(['user_id' => $userId]);
        $row = $query->fetch();
        if ($row === false) {
            throw new AdminAuthException(403, 'Admin access is required.');
        }

        $user = new AdminUser(
            (int) $row['user_ID'],
            (string) $row['user_Email'],
            (string) $row['user_Password'],
            (string) $row['user_Role'],
            (string) $row['user_Status']
        );
        if (
            !$user->hasRequiredRole()
            || !$user->isActive()
            || strtoupper(trim((string) $row['admin_Status'])) !== 'ACTIVE'
        ) {
            throw new AdminAuthException(403, 'Admin privileges are inactive or restricted.');
        }

        return [
            'user_ID' => $user->getId(),
            'user_Email' => $user->getEmail(),
            'user_Role' => strtolower($user->getRole()),
            'admin_ID' => (int) $row['admin_ID'],
        ];
    }

    public function logout(): array
    {
        $this->sessions->destroy();

        return ['status' => 200, 'message' => 'Logged out securely.'];
    }
}

final class AdminAuthMiddleware
{
    public function __construct(private AdminAuthService $authService)
    {
    }

    public function handle(callable $next): mixed
    {
        return $next($this->authService->authorize());
    }
}
