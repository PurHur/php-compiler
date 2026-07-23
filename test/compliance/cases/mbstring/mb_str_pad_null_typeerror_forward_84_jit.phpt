--TEST--
mbstring mb_str_pad() null $string — TypeError JIT/AOT (#19184, #22373, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$ok = false;
try {
    mb_str_pad(null, 5);
} catch (TypeError $e) {
    $ok = ('mb_str_pad(): Argument #1 ($string) must be of type string, null given' === $e->getMessage());
}
echo $ok ? "ok\n" : "bad\n";
--EXPECT--
ok
