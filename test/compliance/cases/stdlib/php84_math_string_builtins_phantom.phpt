--TEST--
stdlib str_increment/str_decrement/fpow — not advertised on PHP 8.2 reference (#11846)
--FILE--
<?php
$bad = array_filter(
    ['str_increment', 'str_decrement', 'fpow'],
    static fn (string $fn): bool => function_exists($fn)
);
echo [] === $bad ? "ok\n" : "fail\n";
--EXPECT--
ok
