<?php

declare(strict_types=1);

/**
 * Maintainer repro: session_start() when session active — true + E_NOTICE (#12114).
 *
 * php-src: ext/session/session.c — php_session_start() active-session guard.
 */

error_reporting(E_ALL);

if (!session_start()) {
    echo "fail: first session_start\n";
    exit(1);
}

if (PHP_SESSION_ACTIVE !== session_status()) {
    echo "fail: status not active\n";
    exit(1);
}

$second = session_start();
$last = error_get_last();

if (true !== $second) {
    echo 'fail: return_bad '.var_export($second, true)."\n";
    exit(1);
}

if (!is_array($last) || E_NOTICE !== ($last['type'] ?? null)) {
    echo 'fail: notice_bad '.var_export($last, true)."\n";
    exit(1);
}

if (!str_contains((string) ($last['message'] ?? ''), 'session is already active')) {
    echo 'fail: message_bad '.($last['message'] ?? '')."\n";
    exit(1);
}

if (PHP_SESSION_ACTIVE !== session_status()) {
    echo "fail: status changed\n";
    exit(1);
}

echo "ok\n";
