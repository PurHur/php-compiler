--TEST--
Language: public property post/pre inc/dec (#4926, zend_execute.c)
--FILE--
<?php
class T {
    public int $x = 1;
}

$o = new T();
echo $o->x++, "\n";
var_export($o->x);
echo "\n";

$o->x = 1;
echo ++$o->x, "\n";
var_export($o->x);
echo "\n";
--EXPECT--
1
2
2
2
