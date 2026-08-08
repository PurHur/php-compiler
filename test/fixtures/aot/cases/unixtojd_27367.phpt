--TEST--
AOT: unixtojd() matches Zend (#27367, #28780)
--FILE--
<?php
echo unixtojd(1754236800), PHP_EOL;
$ts = 1754236800;
echo unixtojd($ts), PHP_EOL;
var_export(unixtojd(PHP_INT_MAX));
echo PHP_EOL;
?>
--EXPECT--
2460891
2460891
false
