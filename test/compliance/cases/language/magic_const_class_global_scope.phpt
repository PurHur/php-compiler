--TEST--
Language: __CLASS__ in global scope is empty string (#11910)
--FILE--
<?php
$class = __CLASS__;
echo $class === '' ? "ok\n" : "fail\n";
?>
--EXPECT--
ok
