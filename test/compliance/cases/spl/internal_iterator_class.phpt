--TEST--
InternalIterator built-in class registered (#11781, php-src ext/spl/spl_iterators.c)
--FILE--
<?php
echo class_exists('InternalIterator') ? '1' : '0', "\n";
try {
    new InternalIterator();
    echo 'instantiable', "\n";
} catch (Error $e) {
    echo str_contains($e->getMessage(), 'private InternalIterator::__construct()') ? 'private_ctor' : 'other', "\n";
}
?>
--EXPECT--
1
private_ctor
