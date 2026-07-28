<?php
// Issue #24398 — ArrayObject ARRAY_AS_PROPS property isset null (spl_array_has_property).
$o = new ArrayObject(['a' => null, 'b' => 0, 'c' => false, 'd' => ''], ArrayObject::ARRAY_AS_PROPS);
foreach (['a', 'b', 'c', 'd', 'e'] as $k) {
    echo $k;
    echo isset($o->$k) ? 'y' : 'n';
    echo isset($o[$k]) ? 'y' : 'n';
    echo $o->offsetExists($k) ? 'y' : 'n';
    echo empty($o->$k) ? 'y' : 'n';
    echo ($o->$k ?? 'D') === null ? 'NULL' : ($o->$k ?? 'D');
    echo "\n";
}

class ArrayObjectAsPropsOverride24398 extends ArrayObject
{
    public function offsetExists($key): bool
    {
        return parent::offsetExists($key);
    }
}
$ov = new ArrayObjectAsPropsOverride24398(['a' => null], ArrayObject::ARRAY_AS_PROPS);
echo 'ov';
echo isset($ov->a) ? 'y' : 'n';
echo isset($ov['a']) ? 'y' : 'n';
echo $ov->offsetExists('a') ? 'y' : 'n';
echo "\n";
