<?php

declare(strict_types=1);

if (!enum_exists('SessionStatus', false)) {
    fwrite(STDERR, "SessionStatus enum not registered\n");
    exit(1);
}

echo session_status() === SessionStatus::None ? "none\n" : "bad\n";
session_start();
echo session_status() === SessionStatus::Active ? "active\n" : "bad\n";
session_write_close();
echo session_status() === SessionStatus::None ? "closed\n" : "bad\n";
