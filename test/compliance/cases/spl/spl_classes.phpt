--TEST--
spl_classes() returns SPL class map with ArrayIterator (issue #11802, php-src ext/spl/php_spl.c)
--FILE--
<?php
echo function_exists('spl_classes') ? '1' : '0', "\n";
$map = spl_classes();
echo isset($map['ArrayIterator']) ? '1' : '0', "\n";
echo $map['ArrayIterator'] ?? '', "\n";
echo isset($map['ArrayObject']) ? '1' : '0', "\n";
echo count($map) >= 5 ? '1' : '0', "\n";
?>
--EXPECT--
1
1
ArrayIterator
1
1
