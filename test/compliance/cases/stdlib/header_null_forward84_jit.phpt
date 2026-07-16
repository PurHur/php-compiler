--TEST--
stdlib header(null) — TypeError on 8.4 forward profile JIT (#19224, ext/standard/head.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    header(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    header('');
    echo "empty ok\n";
} catch (TypeError $e) {
    echo 'empty: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
header(): Argument #1 ($header) must be of type string, null given
empty ok
