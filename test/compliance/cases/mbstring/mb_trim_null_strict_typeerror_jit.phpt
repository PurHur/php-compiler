--TEST--
mbstring mb_trim/ltrim/rtrim() null $string JIT — TypeError on 8.4 profile (#17132, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$ok = true;
try {
    mb_trim(null);
} catch (TypeError $e) {
    $ok = ('mb_trim(): Argument #1 ($string) must be of type string, null given' === $e->getMessage());
}
echo 'mb_trim: ', $ok ? 'TypeError' : 'fail', "\n";

$ok = true;
try {
    mb_ltrim(null);
} catch (TypeError $e) {
    $ok = ('mb_ltrim(): Argument #1 ($string) must be of type string, null given' === $e->getMessage());
}
echo 'mb_ltrim: ', $ok ? 'TypeError' : 'fail', "\n";

$ok = true;
try {
    mb_rtrim(null);
} catch (TypeError $e) {
    $ok = ('mb_rtrim(): Argument #1 ($string) must be of type string, null given' === $e->getMessage());
}
echo 'mb_rtrim: ', $ok ? 'TypeError' : 'fail', "\n";
--EXPECT--
mb_trim: TypeError
mb_ltrim: TypeError
mb_rtrim: TypeError
