--TEST--
IteratorIterator / LimitIterator keep temporary Generator receivers (#6138)
--FILE--
<?php
function gen(): Generator
{
    yield 'a';
    yield 'b';
}

$it = new IteratorIterator(gen());
foreach ($it as $v) {
    echo $v;
}
echo "\n";

$limited = new LimitIterator(gen(), 0, 2);
foreach ($limited as $v) {
    echo $v;
}
echo "\n";
?>
--EXPECT--
ab
ab
