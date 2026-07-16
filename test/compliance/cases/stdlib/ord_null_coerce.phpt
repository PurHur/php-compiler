--TEST--
stdlib ord(null) — TypeError on 8.4 forward profile (#19319, was #19161 coerce)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    echo ord(null), "\n";
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
ord(): Argument #1 ($character) must be of type string, null given
