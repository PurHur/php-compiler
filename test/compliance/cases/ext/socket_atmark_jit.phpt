--TEST--
socket_atmark() — JIT no fatal stub under PHP 8.3+ profile (issue #9215, #25874)
--ENV--
PHP_COMPILER_PROFILE=8.3
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsSocketAtmark()) {
    die('skip socket_atmark requires PHP 8.3+ profile');
}
?>
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
