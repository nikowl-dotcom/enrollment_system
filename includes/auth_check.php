<?php
/**
 * Auth Guard
 * Protects pages from unauthorized access based on user session roles.
 */

/**
 * Start a session with secure cookie parameters.
 * Safe to call multiple times — exits immediately if a session is already active.
 */
function startSecureSession(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $isLocalhost = in_array(
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ['127.0.0.1', '::1'],
        true
    );
    $isHttps = (($_SERVER['HTTPS'] ?? 'off') === 'on')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['HTTP_CF_VISITOR']) && str_contains((string)$_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"'));
    $secure = !$isLocalhost && $isHttps;
    session_set_cookie_params([
        'lifetime' => 0,          // Expire on browser close
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,    // HTTPS-only (skipped on localhost)
        'httponly' => true,       // JS cannot read the cookie
        'samesite' => 'Lax',      // Blocks cross-site POST CSRF
    ]);
    session_start();
}

startSecureSession();

function ensureCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function regenerateCsrfToken(): string {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }

    return is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Resolve a path relative to the current app base so redirects work across nested directories.
 *
 * @param string $relativePath
 * @return string
 */
function resolveAppUrl(string $relativePath): string {
    // Preferred: derive web-accessible base path from DOCUMENT_ROOT and project dir
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $includesDir = realpath(__DIR__);
    // Project root is parent of includes/
    $projectRoot = $includesDir ? dirname($includesDir) : false;

    if ($docRoot && $projectRoot && strpos($projectRoot, $docRoot) === 0) {
        // Compute web path relative to document root
        $webBase = str_replace('\\', '/', substr($projectRoot, strlen($docRoot)));
        // Ensure leading slash (root-relative) or empty string
        if ($webBase === false || $webBase === '') {
            $webBase = '';
        }
        return rtrim($webBase, '/') . '/' . ltrim($relativePath, '/');
    }

    // Fallback: preserve previous behavior if DOCUMENT_ROOT mapping isn't available
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $scriptDir = dirname($scriptName);
    $roleDirs = ['admin', 'registrar', 'cashier', 'teacher', 'student', 'enrollee', 'auth'];

    if (in_array(basename($scriptDir), $roleDirs, true)) {
        $scriptDir = dirname($scriptDir);
    }

    $basePath = rtrim($scriptDir, '/');
    if ($basePath === '' || $basePath === '\\') {
        $basePath = '';
    }

    return $basePath . '/' . ltrim($relativePath, '/');
}

/**
 * Ensures user is authenticated and possesses one of the allowed roles.
 * Redirects to login page if unauthenticated.
 * Redirects to the user's role dashboard if unauthorized.
 *
 * @param array $allowedRoles Array of strings containing allowed roles (e.g. ['admin', 'registrar'])
 */
function checkRole(array $allowedRoles) {
    global $pdo;

    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        $_SESSION['flash_error'] = "Please log in to access this page.";

        header("Location: " . resolveAppUrl('auth/login'));
        exit;
    }

    // Idle session timeout — destroy and redirect after 60 minutes of inactivity.
    $idleLimit = 3600;
    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - (int)$_SESSION['LAST_ACTIVITY']) > $idleLimit) {
        $_SESSION = [];
        session_destroy();
        startSecureSession();
        $_SESSION['flash_error'] = 'Your session has expired due to inactivity. Please log in again.';
        header('Location: ' . resolveAppUrl('auth/login'));
        exit;
    }
    $_SESSION['LAST_ACTIVITY'] = time();

    // Ensure session role matches latest DB role so role changes take effect immediately.
    $userRole = $_SESSION['role'];
    if (isset($_SESSION['user_id'])) {
        try {
            require_once __DIR__ . '/../config/database.php';
            if (isset($pdo) && $pdo instanceof PDO) {
                $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
                $stmt->execute(['id' => (int)$_SESSION['user_id']]);
                $dbRole = $stmt->fetchColumn();
                if ($dbRole !== false && $dbRole !== $_SESSION['role']) {
                    $_SESSION['role'] = (string)$dbRole;
                    $userRole = $_SESSION['role'];
                }
            }
        } catch (Throwable $e) {
            // If DB check fails, fall back to session role to avoid blocking access.
        }
    }

    if (!in_array($userRole, $allowedRoles, true)) {
        $_SESSION['flash_error'] = "Access denied: You do not have permission to view that page.";

        $dashboards = [
            'admin'     => 'admin/dashboard',
            'registrar' => 'registrar/dashboard',
            'cashier'   => 'cashier/dashboard',
            'teacher'   => 'teacher/dashboard',
            'student'   => 'student/dashboard',
            'enrollee'  => 'enrollee/dashboard'
        ];

        $targetFile = $dashboards[$userRole] ?? 'index';
        header("Location: " . resolveAppUrl($targetFile));
        exit;
    }
}
