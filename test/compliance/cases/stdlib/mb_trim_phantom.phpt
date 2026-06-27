--TEST--
stdlib mb_trim/ltrim/rtrim — not advertised on PHP 8.2 reference profile (#12797, ext/mbstring/mbstring.c)
--FILE--
<?php
$bad = array_filter(
    ['mb_trim', 'mb_ltrim', 'mb_rtrim'],
    static fn (string $fn): bool => function_exists($fn)
);
echo [] === $bad ? "ok\n" : "fail\n";
--EXPECT--
ok
