<?php

declare(strict_types=1);

echo 'x';
$started = session_start();
$status = session_status();

if (true === $started) {
    fwrite(STDERR, "fail: session_start returned true after output\n");
    exit(1);
}
if (PHP_SESSION_NONE !== $status) {
    fwrite(STDERR, "fail: session_status={$status}, expected PHP_SESSION_NONE\n");
    exit(1);
}

echo "ok\n";
