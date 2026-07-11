--TEST--
socket_atmark() — JIT no fatal stub (issue #9215)
--SKIPIF--
<?php if (!function_exists('socket_atmark')) die('skip socket_atmark'); ?>
--FILE--
<?php
declare(strict_types=1);
try {
    socket_atmark();
} catch (ArgumentCountError $e) {
    echo 'argc_ok', "\n";
}
?>
--EXPECT--
argc_ok
