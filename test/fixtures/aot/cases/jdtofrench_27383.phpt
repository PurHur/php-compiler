--TEST--
AOT: jdtofrench() matches Zend (#27383)
--FILE--
<?php
echo jdtofrench(2379867), PHP_EOL;
$jd = 2379867;
echo jdtofrench($jd), PHP_EOL;
--EXPECT--
1/10/12
1/10/12
