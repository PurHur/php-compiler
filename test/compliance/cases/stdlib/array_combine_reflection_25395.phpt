--TEST--
stdlib array_combine() Reflection return array matches Zend stub (#25395)
--FILE--
<?php
$r = new ReflectionFunction('array_combine');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
try {
    array_combine([1, 2], [1]);
} catch (ValueError $e) {
    echo 'mismatch=ValueError', "\n";
}
?>
--EXPECT--
ret=array
mismatch=ValueError
