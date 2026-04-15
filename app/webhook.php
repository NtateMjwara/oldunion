<?php
// ============================================================
// webhook.php — YoCo webhook endpoint
// Register this URL in your YoCo dashboard:
// https://oldunion.co.za/webhook.php
// ============================================================

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/WalletService.php';

// 1. Read raw body and headers BEFORE any output
$rawBody = file_get_contents("php://input");
$headers = getallheaders();

// 2. Webhook secret from config.php (YOCO_WEBHOOK_SECRET constant).
//    Previously this was the literal string "YOUR_YOCO_WEBHOOK_SECRET" —
//    every signature check returned false and the handler bailed at step 4,
//    so WalletService::applyDeposit() was never reached and wallet balances
//    were never credited after payment.
$secret = YOCO_WEBHOOK_SECRET;

// 3. Extract signature header (case-insensitive)
$sigHeader = $headers['X-Yoco-Signature']
    ?? $headers['x-yoco-signature']
    ?? '';

// ---------------------------------------------------------------
// Verify YoCo signature
// YoCo sends: X-Yoco-Signature: t=TIMESTAMP,v1=HMAC_SHA256
// ---------------------------------------------------------------
function verifyYocoSignature(string $payload, string $sigHeader, string $secret): bool
{
    if (empty($sigHeader)) return false;

    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$k, $v] = explode('=', $part, 2);
        $parts[$k] = $v;
    }

    if (empty($parts['t']) || empty($parts['v1'])) return false;

    $signedPayload = $parts['t'] . '.' . $payload;
    $expected      = hash_hmac('sha256', $signedPayload, $secret);

    return hash_equals($expected, $parts['v1']);
}

// 4. Reject invalid signatures
if (!verifyYocoSignature($rawBody, $sigHeader, $secret)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// 5. Decode JSON
$event = json_decode($rawBody, true);
if (!$event || empty($event['type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON or missing event type']);
    exit;
}

// 6. Log every event for debugging (rotate or remove in production)
file_put_contents(
    __DIR__ . '/yoco-log.txt',
    date('Y-m-d H:i:s') . ' ' . $rawBody . PHP_EOL,
    FILE_APPEND
);

// 7. Respond 200 immediately so YoCo does not retry.
//    YoCo considers any non-2xx a failure and retries — applyDeposit()
//    is idempotent (status=pending guard), but early ACK is best practice.
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['received' => true]);

// Flush the response to YoCo before processing the business logic
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request(); // PHP-FPM: release the connection immediately
} else {
    ob_end_flush();
    flush();
}

// ---------------------------------------------------------------
// 8. Process the event
// YoCo event shape:
// {
//   "type": "payment.succeeded",
//   "payload": {
//     "id":       "chr_xxx",
//     "amount":   10000,          <- cents
//     "currency": "ZAR",
//     "status":   "succeeded",
//     "metadata": {
//       "deposit_id":   123,
//       "user_id":      456,
//       "internal_ref": "DEP_20240101_abc123"
//     }
//   }
// }
// ---------------------------------------------------------------
try {
    $type      = $event['type']               ?? '';
    $payload   = $event['payload']            ?? [];
    $metadata  = $payload['metadata']         ?? [];
    $depositId = (int) ($metadata['deposit_id'] ?? 0);

    if ($type === 'payment.succeeded') {

        if (!$depositId) {
            error_log('[webhook] payment.succeeded received but metadata.deposit_id is missing. Raw: ' . $rawBody);
            exit;
        }

        $result = WalletService::applyDeposit($depositId);

        if ($result['success']) {
            error_log('[webhook] Deposit #' . $depositId . ' applied — amount: R'
                . $result['amount'] . ' ref: ' . $result['reference']);
        } else {
            // applyDeposit() returns success=false for already-processed deposits (idempotent)
            error_log('[webhook] Deposit #' . $depositId . ' skipped: ' . ($result['message'] ?? 'already processed'));
        }

    } elseif ($type === 'payment.failed') {

        if ($depositId) {
            Database::getInstance()
                ->prepare('UPDATE wallet_deposits SET status = "failed" WHERE id = ? AND status = "pending"')
                ->execute([$depositId]);
            error_log('[webhook] Deposit #' . $depositId . ' marked as failed.');
        }

    } else {
        error_log('[webhook] Unhandled event type: ' . $type);
    }

} catch (Throwable $e) {
    error_log('[webhook] Exception: ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
}
