--TEST--
AOT: unixtojd() matches Zend (#27367)
--FILE--
<?php
echo unixtojd(1754236800), PHP_EOL;
$ts = 1754236800;
echo unixtojd($ts), PHP_EOL;
--EXPECT--
2460891
2460891
