<?php

declare(strict_types=1);

if (!enum_exists('SessionStatus', false)) {
    fwrite(STDERR, "SessionStatus enum not registered\n");
    exit(1);
}

echo session_status() === PHP_SESSION_NONE ? "none\n" : "bad\n";
session_start();
echo session_status() === PHP_SESSION_ACTIVE ? "active\n" : "bad\n";
session_write_close();
echo session_status() === PHP_SESSION_NONE ? "closed\n" : "bad\n";
