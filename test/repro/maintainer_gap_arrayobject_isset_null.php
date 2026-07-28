<?php
// Issue #24251 — ArrayObject/ArrayIterator isset null vs offsetExists (spl_array_has_dimension).
$ao = new ArrayObject(['x' => null, 'y' => 0, 'z' => false, 'w' => '']);
echo 'ao';
echo isset($ao['x']) ? 'y' : 'n';
echo $ao->offsetExists('x') ? 'y' : 'n';
echo isset($ao['y']) ? 'y' : 'n';
echo isset($ao['z']) ? 'y' : 'n';
echo isset($ao['w']) ? 'y' : 'n';
echo isset($ao['missing']) ? 'y' : 'n';
echo empty($ao['x']) ? 'y' : 'n';
echo ($ao['x'] ?? 'D');
echo "\n";

$ai = new ArrayIterator(['x' => null]);
echo 'ai';
echo isset($ai['x']) ? 'y' : 'n';
echo $ai->offsetExists('x') ? 'y' : 'n';
echo "\n";

class ArrayObjectOffsetExistsOverride extends ArrayObject
{
    public function offsetExists($key): bool
    {
        return parent::offsetExists($key);
    }
}
$ov = new ArrayObjectOffsetExistsOverride(['x' => null]);
echo 'ov';
echo isset($ov['x']) ? 'y' : 'n';
echo $ov->offsetExists('x') ? 'y' : 'n';
echo "\n";
