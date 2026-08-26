--TEST--
foreach over IteratorAggregate whose getIterator() yields (#34980)
--FILE--
<?php
class A implements IteratorAggregate
{
    public function getIterator(): Traversable
    {
        yield 'k' => 1;
        yield 'm' => 2;
    }
}
foreach (new A() as $k => $v) {
    echo $k, $v, '|';
}
echo "\n";
?>
--EXPECT--
k1|m2|
