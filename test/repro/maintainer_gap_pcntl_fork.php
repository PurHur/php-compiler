<?php

declare(strict_types=1);

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "skip: pcntl_fork unavailable\n");
    exit(0);
}

$pid = pcntl_fork();
if (-1 === $pid) {
    fwrite(STDERR, "fork fail\n");
    exit(1);
}
if (0 === $pid) {
    echo "child\n";
    exit(0);
}
$status = 0;
if (pcntl_waitpid($pid, $status) <= 0) {
    fwrite(STDERR, "wait fail\n");
    exit(1);
}
if (!pcntl_wifexited($status)) {
    fwrite(STDERR, "not exited\n");
    exit(1);
}
echo "parent\n";
echo pcntl_wexitstatus($status), "\n";
