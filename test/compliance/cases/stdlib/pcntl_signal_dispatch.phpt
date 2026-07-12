--TEST--
stdlib pcntl_signal_dispatch delivers pending handler after posix_kill (#6680, ext/pcntl/pcntl.c)
--FILE--
<?php
declare(strict_types=1);

if (!function_exists('pcntl_signal_dispatch') || !function_exists('posix_kill')) {
    echo "skip\n";
    exit(0);
}

$flag = 0;
if (!pcntl_signal(SIGUSR1, function () use (&$flag) {
    $flag = 1;
})) {
    echo "register fail\n";
    exit(0);
}
if (!posix_kill(getmypid(), SIGUSR1)) {
    echo "kill fail\n";
    exit(0);
}
if (!pcntl_signal_dispatch()) {
    echo "dispatch fail\n";
    exit(0);
}
echo $flag, "\n";
echo pcntl_signal_dispatch() ? "noop ok\n" : "noop fail\n";
--EXPECT--
1
noop ok
