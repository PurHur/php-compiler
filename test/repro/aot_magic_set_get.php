<?php
// AOT: undeclared $obj->prop = v must call __set (zend_std_write_property).
class A {
    private $d = [];
    function __get($k)
    {
        return $this->d[$k] ?? null;
    }
    function __set($k, $v)
    {
        $this->d[$k] = $v;
    }
}
$a = new A;
$a->x = 5;
echo $a->x, "\n";
