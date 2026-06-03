--TEST--
language: array spread numeric-string key renumbers like Zend array_merge (#5072, zend_hash.c)
--FILE--
<?php
$b = [...['0' => 's'], 0 => 'i'];
echo count($b), "\n";
echo $b[0], "\n";

class SpreadGen implements IteratorAggregate
{
    public function getIterator(): Traversable
    {
        yield '0' => 'from_gen';
    }
}

$d = [0 => 'keep', ...new SpreadGen()];
echo count($d), "\n";
echo $d[0], ',', $d[1], "\n";
?>
--EXPECT--
1
i
2
keep,from_gen
