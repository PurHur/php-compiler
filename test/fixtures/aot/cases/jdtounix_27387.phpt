--TEST--
AOT: jdtounix() matches Zend (#27387)
--FILE--
<?php
echo jdtounix(2461256), PHP_EOL;
$jd = 2461256;
echo jdtounix($jd), PHP_EOL;
--EXPECT--
1785715200
1785715200
