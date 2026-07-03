--TEST--
socket_import_stream() — JIT no fatal stub (issue #9217)
--SKIPIF--
<?php if (!function_exists('socket_import_stream')) die('skip socket_import_stream'); ?>
--FILE--
<?php
declare(strict_types=1);
try {
    socket_import_stream();
} catch (ArgumentCountError $e) {
    echo 'argc_ok', "\n";
}
?>
--EXPECT--
argc_ok
