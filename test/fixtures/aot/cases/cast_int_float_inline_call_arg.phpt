--TEST--
AOT: (int)/(float) cast as inline call arg (#32293)
--FILE--
<?php
function f($x)
{
    var_dump($x);
}
f((int) 1.9);
f((float) 2);
$n = (int) 1.9;
f($n);
--EXPECT--
int(1)
float(2)
int(1)
