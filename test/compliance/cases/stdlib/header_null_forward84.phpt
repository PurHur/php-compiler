--TEST--
stdlib header(null) — TypeError on 8.4 forward profile (#19224, ext/standard/head.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
