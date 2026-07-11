--TEST--
stdlib pcntl_fork/waitpid parent reaps child exit status (#3327, ext/pcntl/pcntl.c)
--FILE--
<?php
declare(strict_types=1);

if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
    echo "skip\n";
    exit(0);
}

$pid = pcntl_fork();
if (-1 === $pid) {
    echo "fork fail\n";
    exit(0);
}
if (0 === $pid) {
    echo "child\n";
    exit(0);
}
$status = 0;
$waitRc = pcntl_waitpid($pid, $status);
if ($waitRc <= 0) {
    echo "wait fail\n";
    exit(0);
}
if (!pcntl_wifexited($status)) {
    echo "not exited\n";
    exit(0);
}
echo "parent\n";
echo pcntl_wexitstatus($status), "\n";
--EXPECT--
child
parent
0
