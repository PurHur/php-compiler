--TEST--
stdlib array_sum()/array_product() skip backed enum case elements (#5578, php-src array.c)
--FILE--
<?php
enum E: int
{
    case A = 1;
    case B = 2;
}

$a = [E::A, E::B];
var_export(array_sum($a));
echo "\n";
var_export(array_product($a));
echo "\n";
--EXPECT--
0
1
