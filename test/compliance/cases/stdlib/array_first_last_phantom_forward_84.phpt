--TEST--
stdlib array_first()/array_last() — not advertised on PHP 8.4 forward profile (#21173, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$bad = array_filter(
    ['array_first', 'array_last'],
    static fn (string $fn): bool => function_exists($fn)
);
echo [] === $bad ? "ok\n" : "fail\n";
foreach (['array_find', 'array_all'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'no', "\n";
}
--EXPECT--
ok
array_find=yes
array_all=yes
