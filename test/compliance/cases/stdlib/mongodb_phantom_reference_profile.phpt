--TEST--
stdlib mongodb withheld on reference profile (#6575 phantom)
--FILE--
<?php
declare(strict_types=1);
echo class_exists('MongoDB\\Driver\\Manager', false) ? '1' : '0';
echo extension_loaded('mongodb') ? '1' : '0';
echo "\n";
?>
--EXPECT--
00
