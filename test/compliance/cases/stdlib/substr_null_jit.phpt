--TEST--
stdlib substr(null) — TypeError on 8.4 forward profile JIT (#18980, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    substr(null, 0);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo var_export(substr('hello', 1, 3), true), "\n";
?>
--EXPECT--
substr(): Argument #1 ($string) must be of type string, null given
'ell'
