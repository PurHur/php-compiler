--TEST--
stdlib parse_str(null) — TypeError on 8.4 forward profile JIT (#20113, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    parse_str(null, $o);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
parse_str('', $empty);
echo var_export($empty, true), "\n";
?>
--EXPECT--
parse_str(): Argument #1 ($string) must be of type string, null given
array (
)
