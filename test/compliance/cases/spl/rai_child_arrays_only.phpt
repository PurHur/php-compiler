--TEST--
SPL RecursiveArrayIterator::CHILD_ARRAYS_ONLY + hasChildren (#22321, ext/spl/spl_array.c)
--FILE--
<?php
echo RecursiveArrayIterator::CHILD_ARRAYS_ONLY, "\n";
echo defined('RecursiveArrayIterator::CHILD_ARRAYS_ONLY') ? 'Y' : 'N', "\n";
$r = new ReflectionClass('RecursiveArrayIterator');
echo $r->hasConstant('CHILD_ARRAYS_ONLY') ? 'Y' : 'N', "\n";
echo $r->getConstant('CHILD_ARRAYS_ONLY'), "\n";
$data = [0 => [1, 2], 1 => (object) ['x' => 1], 2 => 's'];
foreach ([0, RecursiveArrayIterator::CHILD_ARRAYS_ONLY] as $flags) {
    echo 'flags=', $flags, "\n";
    $it = new RecursiveArrayIterator($data, $flags);
    foreach ($it as $k => $v) {
        echo 'k=', $k, ' type=', gettype($v), ' has=', $it->hasChildren() ? 'Y' : 'N', "\n";
    }
}
?>
--EXPECT--
4
Y
Y
4
flags=0
k=0 type=array has=Y
k=1 type=object has=Y
k=2 type=string has=N
flags=4
k=0 type=array has=Y
k=1 type=object has=N
k=2 type=string has=N
