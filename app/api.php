<?php
// ============================================================
// api.php — REST API for the wallet frontend
// ============================================================

// ── Step 1: suppress ALL PHP error output before anything else.
ini_set('display_errors', 1);
error_reporting(E_ALL); // still log, never print

// ── Step 2: buffer everything so stray output never corrupts JSON.
ob_start();

// ── Step 3: Register shutdown handler FIRST — before any require_once.
//    BUG FIX: previously this was registered AFTER the requires, meaning
//    a fatal error during require (e.g. syntax error in WalletService.php,
//    extension not loaded) would never be caught and the hosting server
//    would return its own HTML error page → "Unexpected token '<'" in JS.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Internal server error (fatal)']);
    }
});


require_once __DIR__ . '/includes/config.php';
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_secure',   1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime',  SESSION_TIMEOUT);
ini_set('session.cookie_lifetime', 0);
session_start();
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/WalletService.php';
ob_clean(); // discard any output produced during the require phase
header('Content-Type: application/json');

// ---------------------------------------------------------------
// Auth guard — replace with your own session/JWT check
// ---------------------------------------------------------------
function requireAuth(): int
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        jsonResponse(['error' => 'Unauthenticated'], 401);
    }
    return (int) $userId;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch ("{$method}:{$action}") {

        // ---- GET wallet balance & info ----------------------
        case 'GET:wallet':
            $userId = requireAuth();
            $wallet = WalletService::getOrCreate($userId);
            jsonResponse([
                'balance'  => (float) $wallet['balance'],
                'currency' => $wallet['currency'],
                'status'   => $wallet['status'],
            ]);

        // ---- GET transactions (paginated) -------------------
        case 'GET:transactions':
            $userId = requireAuth();
            $wallet = WalletService::getOrCreate($userId);
            $limit  = min((int)($_GET['limit'] ?? 20), 200);
            $offset = (int)($_GET['offset'] ?? 0);
            $txns   = WalletService::getTransactions((int)$wallet['id'], $limit, $offset);
            $total  = WalletService::getTransactionCount((int)$wallet['id']);
            jsonResponse(['transactions' => $txns, 'total' => $total]);

        // ---- POST: initiate deposit (create YoCo checkout) --
        case 'POST:deposit/initiate':
            $userId = requireAuth();
            $amount = (float)($body['amount'] ?? 0);
            $result = WalletService::initiateDeposit($userId, $amount);
            jsonResponse($result);

        // ---- POST: investment --------------------------------
        case 'POST:invest':
            $userId    = requireAuth();
            $startupId = (int)($body['startup_id'] ?? 0);
            $amount    = (float)($body['amount'] ?? 0);
            $equity    = isset($body['equity_percent']) ? (float)$body['equity_percent'] : null;
            $valuation = isset($body['valuation'])      ? (float)$body['valuation']      : null;
            $note      = trim($body['note'] ?? '');

            if (!$startupId) jsonResponse(['error' => 'startup_id required'], 400);

            $result = WalletService::createInvestment(
                $userId, $startupId, $amount, $equity, $valuation, $note
            );
            jsonResponse($result);

        // ---- POST: transfer ----------------------------------
        case 'POST:transfer':
            $userId    = requireAuth();
            $recipient = trim($body['recipient'] ?? '');
            $amount    = (float)($body['amount'] ?? 0);
            $note      = trim($body['note'] ?? '');

            if (!$recipient) jsonResponse(['error' => 'recipient required'], 400);

            $result = WalletService::createTransfer($userId, $recipient, $amount, $note);
            jsonResponse($result);

        // ---- GET: lookup recipient by email ------------------
        // BUG FIX: previously used INNER JOIN user_wallets, so any registered
        // user who hadn't visited the wallet page yet (no wallet row) was
        // invisible. Changed to query users table directly — wallet is created
        // automatically inside createTransfer() if needed.
        case 'GET:users/lookup':
            requireAuth();
            $email = trim($_GET['email'] ?? '');
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(['error' => 'Valid email required'], 400);
            }
            $stmt = Database::getInstance()->prepare(
                'SELECT id, email
                 FROM users
                 WHERE email = ?
                 LIMIT 1'
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user) jsonResponse(['error' => 'User not found'], 404);
            jsonResponse([
                'user' => [
                    'id'    => $user['id'],
                    'name'  => $user['email'],
                    'email' => $user['email'],
                ],
            ]);

        // ---- GET: startups list (for invest modal) -----------
        case 'GET:startups':
            requireAuth();
            $stmt = Database::getInstance()->query(
                'SELECT id, name, sector, valuation, equity_available, logo_url, tagline
                 FROM startups
                 WHERE status = "active"
                 ORDER BY created_at DESC
                 LIMIT 50'
            );
            jsonResponse(['startups' => $stmt->fetchAll()]);

        default:
            jsonResponse(['error' => 'Not found'], 404);
    }
} catch (InvalidArgumentException $e) {
    jsonResponse(['error' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('[API Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse(['error' => 'Internal server error'], 500);
}
