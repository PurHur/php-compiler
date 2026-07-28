--TEST--
IteratorIterator/CachingIterator undefined method names inner class (#24287)
--FILE--
<?php
$a = new ArrayIterator([1]);
try {
    (new CachingIterator($a))->nope();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    (new IteratorIterator($a))->missing();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
Call to undefined method ArrayIterator::nope()
Call to undefined method ArrayIterator::missing()
