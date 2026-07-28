--TEST--
SPL ArrayObject/ArrayIterator isset null vs offsetExists (#24251, ext/spl/spl_array.c)
--FILE--
<?php
$ao = new ArrayObject(['x' => null, 'y' => 0]);
echo isset($ao['x']) ? 'y' : 'n';
echo $ao->offsetExists('x') ? 'y' : 'n';
echo isset($ao['y']) ? 'y' : 'n';
echo empty($ao['x']) ? 'y' : 'n';
echo ($ao['x'] ?? 'D');
echo "\n";
$ai = new ArrayIterator(['x' => null]);
echo isset($ai['x']) ? 'y' : 'n';
echo $ai->offsetExists('x') ? 'y' : 'n';
echo "\n";
class ArrayObjectOffsetExistsOverride24251 extends ArrayObject
{
    public function offsetExists($key): bool
    {
        return parent::offsetExists($key);
    }
}
$ov = new ArrayObjectOffsetExistsOverride24251(['x' => null]);
echo isset($ov['x']) ? 'y' : 'n';
echo $ov->offsetExists('x') ? 'y' : 'n';
echo "\n";
?>
--EXPECT--
nyyyD
ny
yy
