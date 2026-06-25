--TEST--
AOT: spl_classes() SPL registry map (issue #11802)
--FILE--
<?php
$map = spl_classes();
echo isset($map['ArrayIterator']) ? '1' : '0', "\n";
echo $map['ArrayIterator'] ?? '', "\n";
?>
--EXPECT--
1
ArrayIterator
