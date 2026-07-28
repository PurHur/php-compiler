--TEST--
SPL ArrayObject ARRAY_AS_PROPS isset null property (#24398, ext/spl/spl_array.c)
--FILE--
<?php
$o = new ArrayObject(['a' => null, 'b' => 0], ArrayObject::ARRAY_AS_PROPS);
echo isset($o->a) ? 'y' : 'n';
echo isset($o['a']) ? 'y' : 'n';
echo $o->offsetExists('a') ? 'y' : 'n';
echo empty($o->a) ? 'y' : 'n';
echo ($o->a ?? 'D');
echo isset($o->b) ? 'y' : 'n';
echo "\n";
class ArrayObjectAsPropsOverride24398 extends ArrayObject
{
    public function offsetExists($key): bool
    {
        return parent::offsetExists($key);
    }
}
$ov = new ArrayObjectAsPropsOverride24398(['a' => null], ArrayObject::ARRAY_AS_PROPS);
echo isset($ov->a) ? 'y' : 'n';
echo isset($ov['a']) ? 'y' : 'n';
echo $ov->offsetExists('a') ? 'y' : 'n';
echo "\n";
?>
--EXPECT--
nnyyDy
yyy
