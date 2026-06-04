--TEST--
Language: (unset) cast — compile-time fatal on PHP 8+ (#5324)
--FILE--
<?php
$a = 1;
$b = (unset) $a;
var_export(isset($a));
echo "\n";
var_export($b);
echo "\n";
--EXPECT_EXIT--
255
