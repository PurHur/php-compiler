--TEST--
AOT: preg_replace() empty // pattern (#11024)
--FILE--
<?php
echo preg_replace('//', 'X', 'abc'), "\n";
echo preg_replace('//u', 'Y', 'ab'), "\n";
?>
--EXPECT--
XaXbXcX
YaYbY
