--TEST--
stdlib ssh2_poll registration + type guards + constants (#26735)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
if (!function_exists('ssh2_poll')) {
    echo "skip\n";
    exit(0);
}
echo function_exists('ssh2_poll') ? '1' : '0';
echo defined('SSH2_POLLIN') ? '1' : '0';
echo defined('SSH2_POLLOUT') ? '1' : '0';
echo defined('SSH2_POLLERR') ? '1' : '0';
echo (SSH2_POLLIN === 1) ? '1' : '0';
echo "\n";
try {
    ssh2_poll(null);
    echo "type_fail\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'array') ? "type_ok\n" : "type_msg\n";
}
try {
    ssh2_poll();
    echo "argc_fail\n";
} catch (ArgumentCountError $e) {
    echo "argc_ok\n";
}
$n = @ssh2_poll([]);
echo (0 === $n) ? "empty_ok\n" : "empty_fail\n";
?>
--EXPECT--
11111
type_ok
argc_ok
empty_ok
