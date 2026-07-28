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
