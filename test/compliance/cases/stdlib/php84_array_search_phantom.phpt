--TEST--
stdlib array_find family — not advertised on PHP 8.2 reference profile (#12796, ext/standard/array.c)
--FILE--
<?php
$bad = array_filter(
    ['array_find', 'array_find_key', 'array_any', 'array_all', 'array_any_key', 'array_all_key'],
    static fn (string $fn): bool => function_exists($fn)
);
echo [] === $bad ? "ok\n" : "fail\n";
--EXPECT--
ok
