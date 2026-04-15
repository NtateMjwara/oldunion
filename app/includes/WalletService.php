<?php
// ============================================================
// WalletService.php — core wallet business logic
// All balance mutations go through this class only.
// ============================================================

require_once __DIR__ . '/database.php';

class WalletService
{
    // ----------------------------------------------------------
    // WALLET RETRIEVAL
    // ----------------------------------------------------------

    public static function getByUserId(int $userId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT w.*, u.email, u.email AS user_name
             FROM user_wallets w
             JOIN users u ON u.id = w.user_id
             WHERE w.user_id = ?'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public static function getOrCreate(int $userId): array
    {
        $wallet = self::getByUserId($userId);
        if ($wallet) return $wallet;

        Database::getInstance()->prepare(
            'INSERT INTO user_wallets (user_id, balance, currency, status) VALUES (?,0.00,?,?)'
        )->execute([$userId, CURRENCY, 'active']);

        return self::getByUserId($userId);
    }

    // ----------------------------------------------------------
    // TRANSACTIONS
    // ----------------------------------------------------------

    public static function getTransactions(int $walletId, int $limit = 20, int $offset = 0): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT t.*,
                    CASE t.reference_type
                        WHEN "transfer" THEN
                            CASE t.type
                                WHEN "credit" THEN (SELECT u.email FROM wallet_transfers tr
                                                    JOIN users u ON u.id = tr.sender_user_id
                                                    WHERE tr.id = t.reference_id)
                                WHEN "debit"  THEN (SELECT u.email FROM wallet_transfers tr
                                                    JOIN users u ON u.id = tr.recipient_user_id
                                                    WHERE tr.id = t.reference_id)
                            END
                        WHEN "investment" THEN
                            (SELECT s.name FROM wallet_investments i
                             JOIN startups s ON s.id = i.startup_id
                             WHERE i.id = t.reference_id)
                        ELSE NULL
                    END AS counterparty
             FROM wallet_transactions t
             WHERE t.wallet_id = ?
             ORDER BY t.created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$walletId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public static function getTransactionCount(int $walletId): int
    {
        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM wallet_transactions WHERE wallet_id = ?');
        $stmt->execute([$walletId]);
        return (int) $stmt->fetchColumn();
    }

    // ----------------------------------------------------------
    // INTERNAL: append a ledger entry (atomic, called within TX)
    // ----------------------------------------------------------
    public static function appendLedger(
        PDO    $pdo,
        int    $walletId,
        string $type,           // credit|debit
        float  $amount,
        float  $balanceBefore,
        string $referenceType,  // deposit|investment|transfer|system_adjustment
        ?int   $referenceId,
        string $description,
        string $ref
    ): int {
        $balanceAfter = $type === 'credit'
            ? $balanceBefore + $amount
            : $balanceBefore - $amount;

        $stmt = $pdo->prepare(
            'INSERT INTO wallet_transactions
             (wallet_id, transaction_reference, type, amount,
              balance_before, balance_after, reference_type, reference_id, description)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $walletId, $ref, $type, $amount,
            $balanceBefore, $balanceAfter, $referenceType, $referenceId, $description
        ]);
        return (int) $pdo->lastInsertId();
    }

    // ----------------------------------------------------------
    // DEPOSIT — called by webhook after YoCo confirms payment
    // ----------------------------------------------------------
    public static function applyDeposit(int $depositId): array
    {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            // Lock deposit row
            $dep = $pdo->prepare(
                'SELECT d.*, w.id AS wallet_id, w.balance, w.status AS wallet_status
                 FROM wallet_deposits d
                 JOIN user_wallets w ON w.id = d.wallet_id
                 WHERE d.id = ? FOR UPDATE'
            );
            $dep->execute([$depositId]);
            $deposit = $dep->fetch();

            if (!$deposit) throw new RuntimeException('Deposit not found.');
            if ($deposit['status'] !== 'pending') {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Deposit already processed.'];
            }
            if ($deposit['wallet_status'] !== 'active') {
                throw new RuntimeException('Wallet is frozen.');
            }

            $amount        = (float) $deposit['amount'];
            $walletId      = (int)   $deposit['wallet_id'];
            $balanceBefore = (float) $deposit['balance'];
            $ref           = generateReference('DEP');

            // Credit wallet
            $pdo->prepare(
                'UPDATE user_wallets SET balance = balance + ? WHERE id = ?'
            )->execute([$amount, $walletId]);

            // Ledger entry
            self::appendLedger(
                $pdo, $walletId, 'credit', $amount, $balanceBefore,
                'deposit', $depositId,
                'Wallet deposit via YoCo', $ref
            );

            // Mark deposit complete
            $pdo->prepare(
                'UPDATE wallet_deposits SET status="completed", completed_at=NOW() WHERE id=?'
            )->execute([$depositId]);

            $pdo->commit();
            return ['success' => true, 'amount' => $amount, 'reference' => $ref];

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[WalletService::applyDeposit] ' . $e->getMessage());
            throw $e;
        }
    }

    // ----------------------------------------------------------
    // INVESTMENT — debit wallet, record investment
    // ----------------------------------------------------------
    public static function createInvestment(
        int    $userId,
        int    $startupId,
        float  $amount,
        ?float $equityPercent,
        ?float $valuationAtTime,
        string $note = ''
    ): array {
        if ($amount < 1) throw new InvalidArgumentException('Amount must be positive.');

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            // Lock wallet
            $stmt = $pdo->prepare(
                'SELECT * FROM user_wallets WHERE user_id = ? AND status = "active" FOR UPDATE'
            );
            $stmt->execute([$userId]);
            $wallet = $stmt->fetch();

            if (!$wallet) throw new RuntimeException('Active wallet not found.');

            if ((float)$wallet['balance'] < $amount) {
                throw new RuntimeException('Insufficient balance.');
            }

            $walletId      = (int)   $wallet['id'];
            $balanceBefore = (float) $wallet['balance'];
            $ref           = generateReference('INV');

            // Create investment record
            $pdo->prepare(
                'INSERT INTO wallet_investments
                 (user_id, wallet_id, startup_id, amount, equity_percent, valuation_at_time, note, reference)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $userId, $walletId, $startupId, $amount,
                $equityPercent, $valuationAtTime, $note, $ref,
            ]);
            $investmentId = (int) $pdo->lastInsertId();

            // Debit wallet
            $pdo->prepare(
                'UPDATE user_wallets SET balance = balance - ? WHERE id = ?'
            )->execute([$amount, $walletId]);

            // Ledger entry
            self::appendLedger(
                $pdo, $walletId, 'debit', $amount, $balanceBefore,
                'investment', $investmentId,
                'Investment in startup #' . $startupId, $ref
            );

            $pdo->commit();
            return [
                'success'       => true,
                'investment_id' => $investmentId,
                'reference'     => $ref,
            ];

        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ----------------------------------------------------------
    // TRANSFER — debit sender, credit recipient
    // ----------------------------------------------------------
    public static function createTransfer(
        int    $senderUserId,
        string $recipientEmail,
        float  $amount,
        string $note = ''
    ): array {
        if ($amount < 1) throw new InvalidArgumentException('Amount must be positive.');

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            // ── 1. Resolve recipient ──
            $rStmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ?');
            $rStmt->execute([$recipientEmail]);
            $recipientUser = $rStmt->fetch();

            if (!$recipientUser) throw new RuntimeException('Recipient not found.');
            if ((int)$recipientUser['id'] === $senderUserId) {
                throw new RuntimeException('Cannot transfer to yourself.');
            }

            // ── 2. Ensure recipient has a wallet (create if missing) ──
            $recipientWallet = self::getByUserId((int)$recipientUser['id']);
            if (!$recipientWallet) {
                $pdo->prepare(
                    'INSERT INTO user_wallets (user_id, balance, currency, status)
                     VALUES (?, 0.00, ?, "active")'
                )->execute([$recipientUser['id'], CURRENCY]);
                $recipientWallet = self::getByUserId((int)$recipientUser['id']);
            }

            if ($recipientWallet['status'] !== 'active') {
                throw new RuntimeException('Recipient wallet is unavailable.');
            }

            $rWalletId = (int) $recipientWallet['id'];

            // ── 3. Lock sender wallet ──
            $sStmt = $pdo->prepare(
                'SELECT * FROM user_wallets WHERE user_id = ? AND status = "active" FOR UPDATE'
            );
            $sStmt->execute([$senderUserId]);
            $sender = $sStmt->fetch();

            if (!$sender) throw new RuntimeException('Your wallet is inactive.');
            if ((float)$sender['balance'] < $amount) {
                throw new RuntimeException('Insufficient balance.');
            }

            $sWalletId = (int)   $sender['id'];
            $sBefore   = (float) $sender['balance'];

            // ── 4. Lock recipient wallet and read balance ──
            $rBalStmt = $pdo->prepare('SELECT balance FROM user_wallets WHERE id = ? FOR UPDATE');
            $rBalStmt->execute([$rWalletId]);
            $rBal = (float) $rBalStmt->fetchColumn();

            $ref = generateReference('TRF');

            // ── 5. Create transfer record ──
            $pdo->prepare(
                'INSERT INTO wallet_transfers
                 (sender_user_id, sender_wallet_id, recipient_user_id, recipient_wallet_id, amount, note, reference)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $senderUserId, $sWalletId,
                $recipientUser['id'], $rWalletId,
                $amount, $note, $ref,
            ]);
            $transferId = (int) $pdo->lastInsertId();

            // ── 6. Debit sender / credit recipient ──
            $pdo->prepare('UPDATE user_wallets SET balance = balance - ? WHERE id = ?')
                ->execute([$amount, $sWalletId]);
            $pdo->prepare('UPDATE user_wallets SET balance = balance + ? WHERE id = ?')
                ->execute([$amount, $rWalletId]);

            // ── 7. Ledger entries ──
            self::appendLedger(
                $pdo, $sWalletId, 'debit', $amount, $sBefore,
                'transfer', $transferId,
                "Transfer to {$recipientUser['email']}", $ref . '_S'
            );
            self::appendLedger(
                $pdo, $rWalletId, 'credit', $amount, $rBal,
                'transfer', $transferId,
                "Transfer from user #{$senderUserId}", $ref . '_R'
            );

            $pdo->commit();
            return [
                'success'     => true,
                'transfer_id' => $transferId,
                'reference'   => $ref,
                'recipient'   => $recipientUser['email'],
            ];

        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ----------------------------------------------------------
    // YoCo: initiate checkout session (server-side)
    // BUG FIX: added cURL timeouts; validate YoCo response before
    // accessing fields to avoid silent null storage.
    // BUG FIX: store internal_ref in wallet_deposits so it can be
    // reconciled from the successUrl callback or YoCo metadata.
    // ----------------------------------------------------------
    public static function initiateDeposit(int $userId, float $amount): array
    {
        if ($amount < MIN_DEPOSIT) {
            throw new InvalidArgumentException('Minimum deposit is R ' . MIN_DEPOSIT . '.');
        }
        if ($amount > MAX_DEPOSIT) {
            throw new InvalidArgumentException('Maximum deposit is R ' . number_format(MAX_DEPOSIT, 2) . '.');
        }

        $wallet = self::getOrCreate($userId);
        if ($wallet['status'] !== 'active') throw new RuntimeException('Wallet is frozen.');

        $pdo         = Database::getInstance();
        $internalRef = generateReference('DEP');

        // BUG FIX: internal_ref was generated but never persisted, making
        // reconciliation by ref impossible. Store it alongside the deposit.
        $pdo->prepare(
            'INSERT INTO wallet_deposits (user_id, wallet_id, amount, currency, status, internal_ref)
             VALUES (?,?,?,?,?,?)'
        )->execute([$userId, $wallet['id'], $amount, CURRENCY, 'pending', $internalRef]);
        $depositId = (int) $pdo->lastInsertId();

        // Build YoCo payload (amount in cents)
        $payload = [
            'currency'   => CURRENCY,
            'amount'     => (int) round($amount * 100),
            'successUrl' => APP_URL . '/wallet?deposit=success&ref=' . $internalRef,
            'cancelUrl'  => APP_URL . '/wallet?deposit=cancelled',
            'failureUrl' => APP_URL . '/wallet?deposit=failed',
            'metadata'   => [
                'deposit_id'   => $depositId,
                'user_id'      => $userId,
                'internal_ref' => $internalRef,
                'domain'       => parse_url(APP_URL, PHP_URL_HOST),
            ],
        ];

        // Ensure cURL is available before calling YoCo
        if (!function_exists('curl_init')) {
            error_log('[WalletService::initiateDeposit] cURL extension is not enabled on this server.');
            throw new RuntimeException('Payment gateway is temporarily unavailable. Please contact support.');
        }

        // Call YoCo Checkout API
        $ch = curl_init(YOCO_API_BASE . '/checkouts');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . YOCO_SECRET_KEY,
            ],
        ]);
        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // cURL transport error (not HTTP error)
        if ($response === false) {
            error_log('[YoCo cURL error] ' . $curlErr . ' deposit_id=' . $depositId);
            throw new RuntimeException('Could not reach the payment gateway. Please try again.');
        }

        // HTTP error from YoCo
        if ($httpCode !== 200 && $httpCode !== 201) {
            error_log('[YoCo HTTP ' . $httpCode . '] ' . $response . ' deposit_id=' . $depositId);
            throw new RuntimeException('Payment gateway error (' . $httpCode . '). Please try again.');
        }

        // BUG FIX: validate the JSON response before accessing keys
        $yoco = json_decode($response, true);
        if (!is_array($yoco) || empty($yoco['id'])) {
            error_log('[YoCo bad response] ' . $response . ' deposit_id=' . $depositId);
            throw new RuntimeException('Unexpected response from payment gateway. Please try again.');
        }

        if (empty($yoco['redirectUrl'])) {
            error_log('[YoCo missing redirectUrl] ' . $response . ' deposit_id=' . $depositId);
            throw new RuntimeException('Payment gateway did not return a checkout URL. Please try again.');
        }

        // Store YoCo checkout id
        $pdo->prepare(
            'UPDATE wallet_deposits SET yoco_checkout_id = ? WHERE id = ?'
        )->execute([$yoco['id'], $depositId]);

        return [
            'deposit_id'   => $depositId,
            'checkout_id'  => $yoco['id'],
            'redirect_url' => $yoco['redirectUrl'],
        ];
    }
}
