--TEST--
mbstring float int params under strict_types (#13849, ext/mbstring/mbstring.c)
--FILE--
<?php
declare(strict_types=1);
$fail = 0;
function expectTypeError(string $label, callable $fn): void {
    global $fail;
    try { $fn(); echo "FAIL $label\n"; ++$fail; } catch (TypeError) {}
}
expectTypeError('mb_substr', static fn () => mb_substr('hello', 1.9, 2.7));
expectTypeError('mb_strimwidth', static fn () => mb_strimwidth('hello world', 1.9, 4.7));
expectTypeError('mb_strcut', static fn () => mb_strcut('hello', 1.9, 2.7));
expectTypeError('mb_strpos', static fn () => mb_strpos('hello', 'l', 1.9));
echo $fail === 0 ? "ok\n" : "fail\n";
--EXPECT--
ok
