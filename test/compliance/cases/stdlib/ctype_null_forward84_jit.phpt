--TEST--
stdlib ctype_*(null) TypeError on 8.4 forward JIT (#20252, ext/ctype/ctype.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach (['ctype_alnum', 'ctype_digit', 'ctype_space', 'ctype_blank'] as $fn) {
    try {
        $r = $fn(null);
        echo $fn, ': COERCED ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
echo 'ok_digit=', (int) ctype_digit('9'), "\n";
?>
--EXPECT--
ctype_alnum: ctype_alnum(): Argument #1 ($text) must be of type string, null given
ctype_digit: ctype_digit(): Argument #1 ($text) must be of type string, null given
ctype_space: ctype_space(): Argument #1 ($text) must be of type string, null given
ctype_blank: ctype_blank(): Argument #1 ($text) must be of type string, null given
ok_digit=1
