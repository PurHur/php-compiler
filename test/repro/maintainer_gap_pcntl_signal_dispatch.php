<?php

declare(strict_types=1);

$flag = 0;
if (!pcntl_signal(SIGUSR1, function () use (&$flag) {
    $flag = 1;
})) {
    fwrite(STDERR, "register fail\n");
    exit(1);
}
if (!posix_kill(getmypid(), SIGUSR1)) {
    fwrite(STDERR, "kill fail\n");
    exit(1);
}
if (!pcntl_signal_dispatch()) {
    fwrite(STDERR, "dispatch fail\n");
    exit(1);
}
if (1 !== $flag) {
    fwrite(STDERR, "FAIL: handler did not run\n");
    exit(1);
}
if (!pcntl_signal_dispatch()) {
    fwrite(STDERR, "noop dispatch fail\n");
    exit(1);
}

echo "ok\n";
