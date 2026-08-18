--TEST--
Language: (int)/(float) cast as inline call arg (#32293, Zend/zend_vm_def.h ZEND_CAST)
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
$d = (float) 2;
f($d);
--EXPECT--
int(1)
float(2)
int(1)
float(2)
