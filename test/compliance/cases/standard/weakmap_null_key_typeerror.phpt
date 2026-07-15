--TEST--
stdlib WeakMap null offset assignment TypeError (#19198, Zend/zend_weakrefs.c)
--FILE--
<?php
$wm = new WeakMap();
try {
    $wm[null] = 1;
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: WeakMap key must be an object
