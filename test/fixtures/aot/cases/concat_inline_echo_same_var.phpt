--TEST--
AOT: consecutive echo concat from the same local must not corrupt heap (#23798)
--FILE--
<?php
$s = 's';
echo $s . '1', "\n";
echo $s . '2', "\n";
--EXPECT--
s1
s2
