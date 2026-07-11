--TEST--
AOT: spl_classes() full registry + InternalIterator (#11802, #11817, #11781)
--FILE--
<?php
$map = spl_classes();
echo count($map) >= 50 ? '1' : '0', "\n";
echo isset($map['AppendIterator']) ? '1' : '0', "\n";
echo class_exists('InternalIterator') ? '1' : '0', "\n";
?>
--EXPECT--
1
1
1
