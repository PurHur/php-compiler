--TEST--
socket_create() — JIT no fatal stub (issue #27394)
--SKIPIF--
<?php if (!function_exists('socket_create')) die('skip socket_create'); ?>
--FILE--
<?php
declare(strict_types=1);
try {
    socket_create();
} catch (ArgumentCountError $e) {
    echo 'argc_ok', "\n";
}
?>
--EXPECT--
argc_ok
