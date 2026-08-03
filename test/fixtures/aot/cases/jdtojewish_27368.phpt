--TEST--
AOT: jdtojewish() matches Zend (#27368)
--FILE--
<?php
echo jdtojewish(2460890), PHP_EOL;
$jd = 2460890;
echo jdtojewish($jd), PHP_EOL;
--EXPECT--
12/8/5785
12/8/5785
