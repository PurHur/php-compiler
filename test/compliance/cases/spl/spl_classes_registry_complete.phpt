--TEST--
spl_classes() registry complete — AppendIterator, SplFileInfo (#11817, php-src ext/spl/php_spl.c)
--FILE--
<?php
$map = spl_classes();
echo count($map) >= 50 ? '1' : '0', "\n";
echo isset($map['AppendIterator']) ? '1' : '0', "\n";
echo isset($map['SplFileInfo']) ? '1' : '0', "\n";
echo isset($map['ArrayIterator']) ? '1' : '0', "\n";
?>
--EXPECT--
1
1
1
1
