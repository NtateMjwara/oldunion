<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/super_admin.php';

// Restrict to super_admin only
//if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
//    redirect(SITE_URL . '/login.php');
//}

$message = null;
$error = null;
$preview = null;
$statements = [];
$statementCount = 0;
$executedCount = 0;

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid CSRF token.';
    } elseif (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload failed.';
    } else {
        $file = $_FILES['sql_file'];
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $maxSize) {
            $error = 'File too large. Maximum 10MB.';
        } elseif (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'sql') {
            $error = 'Only .sql files are allowed.';
        } else {
            $sqlContent = file_get_contents($file['tmp_name']);
            if ($sqlContent === false) {
                $error = 'Failed to read uploaded file.';
            } else {
                $statements = splitSqlStatements($sqlContent);
                $statementCount = count($statements);
                if ($statementCount === 0) {
                    $error = 'No valid SQL statements found in the file.';
                } elseif (isset($_POST['dry_run'])) {
                    $preview = true; // display preview
                } elseif (isset($_POST['import'])) {
                    // Process import with transaction
                    $pdo = Database::getInstance();
                    $transactionStarted = false;
                    try {
                        $pdo->beginTransaction();
                        $transactionStarted = true;
                        foreach ($statements as $statement) {
                            $stmt = trim($statement);
                            if ($stmt === '') continue;
                            $pdo->exec($stmt);
                            $executedCount++;
                        }
                        $pdo->commit();
                        $message = "✅ Import successful! Executed $executedCount SQL statements.";
                        // Log action
                        $userManager = new UserManager();
                        $userManager->logAction('import_sql', [
                            'file' => $file['name'],
                            'size' => $file['size'],
                            'statements' => $statementCount,
                            'executed' => $executedCount
                        ]);
                    } catch (PDOException $e) {
                        if ($transactionStarted) {
                            $pdo->rollBack();
                        }
                        $error = "SQL error: " . $e->getMessage() . " (Statement failed after $executedCount successful statements)";
                    }
                }
            }
        }
    }
}

/**
 * Splits SQL content into individual statements, respecting DELIMITER commands.
 * @param string $sql
 * @return array
 */
function splitSqlStatements(string $sql): array {
    $statements = [];
    $delimiter = ';';
    $buffer = '';
    $lines = preg_split('/\r\n|\r|\n/', $sql);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        // Skip single-line comments
        if (strpos($line, '--') === 0 || strpos($line, '#') === 0) continue;
        // Handle DELIMITER command (MySQL specific)
        if (preg_match('/^DELIMITER\s+([^\s]+)$/i', $line, $matches)) {
            $delimiter = $matches[1];
            continue;
        }
        $buffer .= $line . "\n";
        if (strpos($line, $delimiter) !== false && substr(trim($line), -strlen($delimiter)) === $delimiter) {
            // Remove trailing delimiter
            $buffer = substr($buffer, 0, -strlen($delimiter) - 1);
            $buffer = trim($buffer);
            if ($buffer !== '') {
                $statements[] = $buffer;
            }
            $buffer = '';
        }
    }
    // Add any leftover
    $buffer = trim($buffer);
    if ($buffer !== '') {
        $statements[] = $buffer;
    }
    return $statements;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import SQL - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .preview {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            max-height: 400px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.9rem;
            padding: 10px;
        }
        .statement-item {
            border-bottom: 1px solid #eee;
            margin-bottom: 5px;
            padding-bottom: 5px;
        }
    </style>
</head>
<body>
<div class="container mt-5">
    <h1>SQL Import Tool</h1>
    <p class="text-muted">Upload a <code>.sql</code> file to execute on the database.</p>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="mb-3">
            <label for="sql_file" class="form-label">SQL File</label>
            <input type="file" class="form-control" id="sql_file" name="sql_file" accept=".sql" required>
            <div class="form-text">Max size: 10MB. Only .sql files allowed.</div>
        </div>
        <div class="mb-3">
            <button type="submit" name="dry_run" class="btn btn-info">Preview Only</button>
            <button type="submit" name="import" class="btn btn-primary" onclick="return confirm('WARNING: This will execute SQL statements directly on your database. Ensure you have a backup. Continue?')">Import</button>
        </div>
    </form>

    <?php if ($preview === true && !empty($statements)): ?>
        <hr>
        <h3>Preview (<?= $statementCount ?> statements)</h3>
        <div class="preview">
            <?php foreach ($statements as $index => $stmt): ?>
                <div class="statement-item">
                    <strong>Statement #<?= $index + 1 ?>:</strong>
                    <pre><?= htmlspecialchars($stmt) ?></pre>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>