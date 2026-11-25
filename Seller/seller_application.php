<?php
declare(strict_types=1);

require_once __DIR__ . '/../Backend/database.php';
require_once __DIR__ . '/../Backend/Auth.php';

final class SellerApplicationApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

final class SellerApplicationStatus
{
    public const PENDING = 'Pending';
    public const REVISION_REQUESTED = 'Revision Requested';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';
}

final class SellerApplicationRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function findLatestForUser(int $userId): ?array
    {
        $query = $this->database->prepare(
            'SELECT * FROM SELLER_APPLICATIONS WHERE user_ID = :user_id ORDER BY application_Date DESC, application_ID DESC LIMIT 1'
        );
        $query->execute(['user_id' => $userId]);
        $application = $query->fetch();

        return $application === false ? null : $application;
    }

    public function findById(int $applicationId, bool $lock = false): ?array
    {
        $query = $this->database->prepare(
            'SELECT * FROM SELLER_APPLICATIONS WHERE application_ID = :application_id' . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute(['application_id' => $applicationId]);
        $application = $query->fetch();

        return $application === false ? null : $application;
    }

    public function create(int $userId, array $info): int
    {
        $query = $this->database->prepare("
            INSERT INTO SELLER_APPLICATIONS
                (user_ID, store_Name, tax_ID, business_Address, application_Status)
            VALUES
                (:user_id, :store_name, :tax_id, :business_address, 'Pending')
        ");
        $query->execute([
            'user_id' => $userId,
            'store_name' => $info['store_name'],
            'tax_id' => $info['tax_id'],
            'business_address' => $info['address'],
        ]);

        return (int) $this->database->lastInsertId();
    }

    public function saveDocument(int $applicationId, string $type, string $path): void
    {
        $query = $this->database->prepare(
            'INSERT INTO SELLER_APPLICATION_DOCUMENTS (application_ID, document_Type, file_Path) VALUES (:application_id, :document_type, :file_path)'
        );
        $query->execute([
            'application_id' => $applicationId,
            'document_type' => $type,
            'file_path' => $path,
        ]);
    }

    public function documentsForApplication(int $applicationId): array
    {
        $query = $this->database->prepare(
            'SELECT document_ID, document_Type FROM SELLER_APPLICATION_DOCUMENTS WHERE application_ID = :application_id ORDER BY document_ID'
        );
        $query->execute(['application_id' => $applicationId]);

        return $query->fetchAll();
    }

    public function findDocument(int $applicationId, string $type): ?array
    {
        $query = $this->database->prepare(
            'SELECT file_Path FROM SELLER_APPLICATION_DOCUMENTS WHERE application_ID = :application_id AND document_Type = :document_type LIMIT 1'
        );
        $query->execute([
            'application_id' => $applicationId,
            'document_type' => $type,
        ]);
        $document = $query->fetch();

        return $document === false ? null : $document;
    }

    public function all(?string $status): array
    {
        if ($status === null) {
            return $this->database->query(
                'SELECT * FROM SELLER_APPLICATIONS ORDER BY application_Date DESC, application_ID DESC'
            )->fetchAll();
        }

        $query = $this->database->prepare(
            'SELECT * FROM SELLER_APPLICATIONS WHERE application_Status = :status ORDER BY application_Date DESC, application_ID DESC'
        );
        $query->execute(['status' => $status]);

        return $query->fetchAll();
    }

    public function updateDecision(int $applicationId, int $adminId, string $status): void
    {
        $query = $this->database->prepare(
            'UPDATE SELLER_APPLICATIONS SET application_Status = :status, admin_ID = :admin_id, application_ReviewedAt = NOW() WHERE application_ID = :application_id'
        );
        $query->execute([
            'status' => $status,
            'admin_id' => $adminId,
            'application_id' => $applicationId,
        ]);
    }

    public function logAudit(int $applicationId, int $adminId, string $action, ?string $reason): void
    {
        $query = $this->database->prepare(
            'INSERT INTO SELLER_APPLICATION_AUDIT_LOGS (application_ID, admin_ID, action, reason) VALUES (:application_id, :admin_id, :action, :reason)'
        );
        $query->execute([
            'application_id' => $applicationId,
            'admin_id' => $adminId,
            'action' => $action,
            'reason' => $reason,
        ]);
    }
}

final class SellerApplicationUserRepository
{
    public function __construct(private PDO $database)
    {
    }

    public function findActiveUser(int $userId): ?array
    {
        $query = $this->database->prepare(
            "SELECT user_ID, user_Email, user_Role FROM USERS WHERE user_ID = :user_id AND LOWER(TRIM(user_Status)) = 'active' LIMIT 1"
        );
        $query->execute(['user_id' => $userId]);
        $user = $query->fetch();

        return $user === false ? null : $user;
    }

    public function findCustomer(int $userId): ?array
    {
        $query = $this->database->prepare(
            'SELECT customer_ID, customer_Phone, customer_FN, customer_LN FROM CUSTOMERS WHERE user_ID = :user_id LIMIT 1'
        );
        $query->execute(['user_id' => $userId]);
        $customer = $query->fetch();

        return $customer === false ? null : $customer;
    }

    public function findAdminId(int $userId): ?int
    {
        $query = $this->database->prepare(
            'SELECT admin_ID FROM ADMINS WHERE user_ID = :user_id LIMIT 1'
        );
        $query->execute(['user_id' => $userId]);
        $adminId = $query->fetchColumn();

        return $adminId === false ? null : (int) $adminId;
    }

    public function promoteToSeller(int $userId, array $customer, array $user, string $storeName): void
    {
        $update = $this->database->prepare(
            "UPDATE USERS SET user_Role = 'Seller' WHERE user_ID = :user_id"
        );
        $update->execute(['user_id' => $userId]);

        $insert = $this->database->prepare("
            INSERT INTO SELLERS
                (user_ID, sellerPhone, sellerEmail, sellerFN, sellerLN, sellerStoreName)
            VALUES
                (:user_id, :phone, :email, :first_name, :last_name, :store_name)
        ");
        $insert->execute([
            'user_id' => $userId,
            'phone' => $customer['customer_Phone'] ?? '',
            'email' => $user['user_Email'],
            'first_name' => $customer['customer_FN'] ?? '',
            'last_name' => $customer['customer_LN'] ?? '',
            'store_name' => $storeName,
        ]);
    }
}

final class SellerApplicationDocumentStorage
{
    private const MAX_FILE_SIZE = 5242880;
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private string $directory = ''
    ) {
        if ($this->directory === '') {
            $this->directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'mithi-seller-documents';
        }
    }

    public function store(array $file, string $type): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new SellerApplicationApiException(422, "The {$type} upload failed.");
        }
        if (!isset($file['tmp_name'], $file['size']) || (int) $file['size'] > self::MAX_FILE_SIZE) {
            throw new SellerApplicationApiException(422, "The {$type} file must not exceed 5 MB.");
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new SellerApplicationApiException(422, "The {$type} upload is invalid.");
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!is_string($mime) || !isset(self::ALLOWED_MIME_TYPES[$mime])) {
            throw new SellerApplicationApiException(422, "The {$type} must be a JPEG, PNG, or PDF file.");
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Could not create private document storage.');
        }

        $path = $this->directory . DIRECTORY_SEPARATOR
            . $type . '_' . bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME_TYPES[$mime];
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            throw new RuntimeException("Could not store the {$type} document.");
        }

        return $path;
    }
}

final class SellerApplicationService
{
    public function __construct(
        private PDO $database,
        private SellerApplicationRepository $applications,
        private SellerApplicationUserRepository $users,
        private SellerApplicationDocumentStorage $documents
    ) {
    }

    public function currentUser(): array
    {
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId <= 0) {
            throw new SellerApplicationApiException(401, 'Please log in to continue.');
        }

        $user = $this->users->findActiveUser($userId);
        if ($user === null) {
            throw new SellerApplicationApiException(401, 'Your account is unavailable. Please log in again.');
        }

        $user['user_Role'] = strtolower(trim((string) $user['user_Role']));
        return $user;
    }

    public function submit(array $user, array $info, array $files): array
    {
        if ($user['user_Role'] !== 'customer') {
            throw new SellerApplicationApiException(403, 'Only customer accounts may apply to become a seller.');
        }

        $existing = $this->applications->findLatestForUser((int) $user['user_ID']);
        if ($existing !== null) {
            if ($existing['application_Status'] === SellerApplicationStatus::PENDING) {
                throw new SellerApplicationApiException(409, 'Your application is already under review.');
            }
            if ($existing['application_Status'] === SellerApplicationStatus::APPROVED) {
                throw new SellerApplicationApiException(409, 'Your account is already an active seller.');
            }
        }

        $validated = $this->validateInfo($info);
        foreach (['government_id', 'business_permit'] as $requiredFile) {
            if (!isset($files[$requiredFile]) || !is_array($files[$requiredFile])) {
                throw new SellerApplicationApiException(422, "The {$requiredFile} document is required.");
            }
        }

        $storedPaths = [];
        $this->database->beginTransaction();
        try {
            foreach (['government_id', 'business_permit'] as $type) {
                $storedPaths[$type] = $this->documents->store($files[$type], $type);
            }
            $applicationId = $this->applications->create((int) $user['user_ID'], $validated);
            foreach ($storedPaths as $type => $path) {
                $this->applications->saveDocument($applicationId, $type, $path);
            }
            $this->notify((int) $user['user_ID'], 'Application received', 'Your seller application has been received.');
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            foreach ($storedPaths as $path) {
                if (is_file($path) && !unlink($path)) {
                    error_log('Unable to remove uncommitted seller application document: ' . $path);
                }
            }
            throw $error;
        }

        return [
            'status' => 201,
            'message' => 'Seller application submitted successfully.',
            'applicationId' => $applicationId,
        ];
    }

    public function status(array $user, int $applicationId): array
    {
        if ($applicationId <= 0) {
            throw new SellerApplicationApiException(422, 'A valid application id is required.');
        }

        $application = $this->applications->findById($applicationId);
        if ($application === null) {
            throw new SellerApplicationApiException(404, 'Application not found.');
        }
        if ((int) $application['user_ID'] !== (int) $user['user_ID'] && $user['user_Role'] !== 'admin') {
            throw new SellerApplicationApiException(403, 'You are not allowed to view this application.');
        }

        return [
            'status' => 200,
            'data' => [
                'applicationId' => (int) $application['application_ID'],
                'storeName' => $application['store_Name'],
                'taxId' => $application['tax_ID'],
                'address' => $application['business_Address'],
                'applicationStatus' => $application['application_Status'],
                'submittedAt' => $application['application_Date'],
                'reviewedAt' => $application['application_ReviewedAt'],
            ],
        ];
    }

    public function listForAdmin(array $admin, ?string $status): array
    {
        $this->assertAdmin($admin);
        $allowedStatuses = [
            SellerApplicationStatus::PENDING,
            SellerApplicationStatus::REVISION_REQUESTED,
            SellerApplicationStatus::APPROVED,
            SellerApplicationStatus::REJECTED,
        ];
        if ($status !== null && !in_array($status, $allowedStatuses, true)) {
            throw new SellerApplicationApiException(422, 'Invalid application status filter.');
        }

        $applications = $this->applications->all($status);
        foreach ($applications as &$application) {
            $application['documents'] = $this->applications->documentsForApplication(
                (int) $application['application_ID']
            );
        }
        unset($application);

        return ['status' => 200, 'data' => $applications];
    }

    public function documentForAdmin(array $admin, int $applicationId, string $type): string
    {
        $this->assertAdmin($admin);
        if ($applicationId <= 0 || !in_array($type, ['government_id', 'business_permit'], true)) {
            throw new SellerApplicationApiException(422, 'Invalid document request.');
        }

        if ($this->applications->findById($applicationId) === null) {
            throw new SellerApplicationApiException(404, 'Application not found.');
        }
        $document = $this->applications->findDocument($applicationId, $type);
        if ($document === null || !is_file($document['file_Path'])) {
            throw new SellerApplicationApiException(404, 'Document not found.');
        }

        return $document['file_Path'];
    }

    public function decide(array $admin, int $applicationId, string $decision, ?string $reason): array
    {
        $this->assertAdmin($admin);
        $adminId = $this->users->findAdminId((int) $admin['user_ID']);
        if ($adminId === null) {
            throw new SellerApplicationApiException(403, 'Admin account record not found.');
        }
        if ($applicationId <= 0) {
            throw new SellerApplicationApiException(422, 'A valid application id is required.');
        }

        $decision = strtoupper(trim($decision));
        $reason = $reason === null ? null : trim($reason);
        if ($reason !== null && strlen($reason) > 2000) {
            throw new SellerApplicationApiException(422, 'The reason must be 2000 characters or fewer.');
        }

        $this->database->beginTransaction();
        try {
            $application = $this->applications->findById($applicationId, true);
            if ($application === null) {
                throw new SellerApplicationApiException(404, 'Application not found.');
            }
            if (!in_array($application['application_Status'], [
                SellerApplicationStatus::PENDING,
                SellerApplicationStatus::REVISION_REQUESTED,
            ], true)) {
                throw new SellerApplicationApiException(409, 'A final decision has already been recorded.');
            }

            if ($decision === 'APPROVE') {
                $customer = $this->users->findCustomer((int) $application['user_ID']);
                $user = $this->users->findActiveUser((int) $application['user_ID']);
                if ($customer === null || $user === null) {
                    throw new SellerApplicationApiException(409, 'The applicant account is incomplete.');
                }
                $this->users->promoteToSeller(
                    (int) $application['user_ID'],
                    $customer,
                    $user,
                    (string) $application['store_Name']
                );
                $status = SellerApplicationStatus::APPROVED;
                $auditReason = $reason ?? 'Approved by administrator.';
            } elseif ($decision === 'REJECT') {
                if ($reason === null || $reason === '') {
                    throw new SellerApplicationApiException(422, 'A reason is required to reject an application.');
                }
                $status = SellerApplicationStatus::REJECTED;
                $auditReason = $reason;
            } elseif ($decision === 'REQUEST_REVISION') {
                if ($reason === null || $reason === '') {
                    throw new SellerApplicationApiException(422, 'Revision details are required.');
                }
                $status = SellerApplicationStatus::REVISION_REQUESTED;
                $auditReason = $reason;
            } else {
                throw new SellerApplicationApiException(422, 'Choose approve, reject, or request_revision.');
            }

            $this->applications->updateDecision($applicationId, $adminId, $status);
            $this->applications->logAudit($applicationId, $adminId, $decision, $auditReason);
            $this->notify(
                (int) $application['user_ID'],
                'Seller application update',
                "Your seller application status is {$status}. {$auditReason}"
            );
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }

        return ['status' => 200, 'message' => 'Application decision recorded.'];
    }

    private function validateInfo(array $info): array
    {
        $validated = [];
        foreach ([
            'store_name' => ['Store name', 150],
            'tax_id' => ['Tax ID', 100],
            'address' => ['Business address', 500],
        ] as $key => [$label, $maximumLength]) {
            $value = $info[$key] ?? null;
            if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $maximumLength) {
                throw new SellerApplicationApiException(
                    422,
                    "{$label} is required and must be no longer than {$maximumLength} characters."
                );
            }
            $validated[$key] = trim($value);
        }

        return $validated;
    }

    private function assertAdmin(array $user): void
    {
        if ($user['user_Role'] !== 'admin') {
            throw new SellerApplicationApiException(403, 'Admin access is required.');
        }
    }

    private function notify(int $userId, string $title, string $message): void
    {
        $query = $this->database->prepare(
            'INSERT INTO NOTIFICATIONS (user_ID, notificationTitle, notificationMessage) VALUES (:user_id, :title, :message)'
        );
        $query->execute([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
        ]);
    }
}

final class SellerApplicationController
{
    private SellerApplicationService $service;
    private SessionManager $sessions;

    public function __construct()
    {
        $this->sessions = new SessionManager();
    }

    public function handle(): never
    {
        header('Cache-Control: no-store');
        try {
            $this->sessions->start();
            $database = Database::connect();
            $this->service = new SellerApplicationService(
                $database,
                new SellerApplicationRepository($database),
                new SellerApplicationUserRepository($database),
                new SellerApplicationDocumentStorage(getenv('SELLER_DOCUMENT_DIR') ?: '')
            );
            $user = $this->service->currentUser();
            $action = $_GET['action'] ?? $_POST['action'] ?? '';
            if (!is_string($action)) {
                throw new SellerApplicationApiException(400, 'Invalid action.');
            }

            if ($action === 'submit') {
                $this->requireMethod('POST');
                $this->sendJson($this->service->submit($user, $_POST, $_FILES));
            } elseif ($action === 'status') {
                $this->requireMethod('GET');
                $this->sendJson($this->service->status(
                    $user,
                    filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0
                ));
            } elseif ($action === 'admin_list') {
                $this->requireMethod('GET');
                $status = $_GET['status'] ?? null;
                if ($status !== null && !is_string($status)) {
                    throw new SellerApplicationApiException(422, 'Invalid application status filter.');
                }
                $this->sendJson($this->service->listForAdmin($user, $status));
            } elseif ($action === 'admin_document') {
                $this->requireMethod('GET');
                $applicationId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
                $type = $_GET['type'] ?? '';
                if (!is_string($type)) {
                    throw new SellerApplicationApiException(422, 'Invalid document request.');
                }
                $path = $this->service->documentForAdmin($user, $applicationId, $type);
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
                if (!is_string($mime)) {
                    throw new RuntimeException('Could not determine document type.');
                }
                header('Content-Type: ' . $mime);
                header('Content-Disposition: attachment; filename="' . basename($path) . '"');
                header('X-Content-Type-Options: nosniff');
                readfile($path);
                exit;
            } elseif ($action === 'admin_decision') {
                $this->requireMethod('POST');
                $input = $this->readDecisionInput();
                $this->sendJson($this->service->decide(
                    $user,
                    filter_var($input['application_id'] ?? null, FILTER_VALIDATE_INT) ?: 0,
                    is_string($input['decision'] ?? null) ? $input['decision'] : '',
                    is_string($input['reason'] ?? null) ? $input['reason'] : null
                ));
            } else {
                throw new SellerApplicationApiException(404, 'Unknown seller application action.');
            }
        } catch (SellerApplicationApiException $error) {
            $this->sendJson([
                'status' => $error->httpStatus,
                'message' => $error->getMessage(),
                ...($error->details === [] ? [] : ['errors' => $error->details]),
            ], $error->httpStatus);
        } catch (Throwable $error) {
            error_log('Seller applications API error: ' . $error->getMessage());
            $this->sendJson([
                'status' => 500,
                'message' => 'The request could not be completed. Please try again later.',
            ], 500);
        }
    }

    private function readDecisionInput(): array
    {
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType === 'application/json') {
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                throw new SellerApplicationApiException(400, 'Malformed JSON request.');
            }
            return $input;
        }

        if ($contentType === 'application/x-www-form-urlencoded' || $contentType === 'multipart/form-data') {
            return $_POST;
        }

        throw new SellerApplicationApiException(415, 'Send JSON or form data.');
    }

    private function requireMethod(string $expected): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $expected) {
            header('Allow: ' . $expected);
            throw new SellerApplicationApiException(405, "Use a {$expected} request.");
        }
    }

    private function sendJson(array $body, ?int $httpStatus = null): never
    {
        $status = $httpStatus ?? (int) ($body['status'] ?? 200);
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
        exit;
    }
}

(new SellerApplicationController())->handle();
