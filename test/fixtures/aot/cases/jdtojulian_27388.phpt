--TEST--
AOT: jdtojulian() matches Zend (#27388)
--FILE--
<?php
echo jdtojulian(2461256), PHP_EOL;
$jd = 2461256;
echo jdtojulian($jd), PHP_EOL;
--EXPECT--
7/21/2026
7/21/2026
