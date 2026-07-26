<?php
/**
 * #23427 residual — AOT: unset($_SESSION[$k]) then use (string)$_SESSION[$k] cast local
 * still flakes free(): invalid pointer on POST in the same function (CFG/layout).
 * SessionsWeb avoids this via $_SESSION['flash'] = '' instead of unset().
 *
 * Manual: php bin/compile.php -o /tmp/c test/repro/issue_23427_session_flash_combo.php
 * Then GET/POST CGI with PHP_COMPILER_SESSION_DIR (expect intermittent exit 134).
 */
declare(strict_types=1);
session_start();
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ('POST' === $method) {
    $_SESSION['flash'] = (string) ($_POST['message'] ?? 'saved');
    session_write_close();
    header('Location: /example.php', true, 303);
    exit;
}
$flash = (string) ($_SESSION['flash'] ?? '');
if ('' !== $flash) {
    unset($_SESSION['flash']);
}
header('Content-Type: text/html; charset=UTF-8');
if ('' !== $flash) {
    echo 'Flash: ', $flash, "\n";
} else {
    echo "No flash\n";
}
session_write_close();
