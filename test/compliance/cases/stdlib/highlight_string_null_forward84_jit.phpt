--TEST--
stdlib highlight_string(null) — TypeError on 8.4 forward profile JIT (#20262, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    highlight_string(null, true);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
highlight_string(): Argument #1 ($string) must be of type string, null given
