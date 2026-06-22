--TEST--
stdlib iterator_count() on exhausted Generator throws Exception (#5132, ext/spl/php_spl.c)
--FILE--
<?php
function gen(): Generator {
    yield 1;
    yield 2;
}
$g = gen();
echo iterator_count($g), "\n";
$g2 = gen();
foreach ($g2 as $_) {
}
try {
    iterator_count($g2);
    echo "no throw\n";
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
2
Cannot traverse an already closed generator
