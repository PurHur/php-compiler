--TEST--
stdlib pcntl_sigprocmask blocks signals and pcntl_signal_get_handler returns handler (#6545, ext/pcntl/pcntl.c)
--FILE--
<?php
declare(strict_types=1);

if (!function_exists('pcntl_sigprocmask') || !function_exists('pcntl_signal_get_handler')) {
    echo "skip\n";
    exit(0);
}

$handler = static function (): void {
};
if (!pcntl_signal(SIGCHLD, $handler)) {
    echo "register fail\n";
    exit(0);
}
$got = pcntl_signal_get_handler(SIGCHLD);
if (!($got instanceof Closure)) {
    echo "handler type fail\n";
    exit(0);
}
$old = [];
if (!pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD], $old)) {
    echo "mask fail\n";
    exit(0);
}
echo is_array($old) ? "old ok\n" : "old fail\n";
pcntl_async_signals(true);
echo pcntl_async_signals() ? "async ok\n" : "async fail\n";
try {
    pcntl_signal_get_handler(0);
    echo "invalid fail\n";
} catch (ValueError) {
    echo "invalid ok\n";
}
--EXPECT--
old ok
async ok
invalid ok
