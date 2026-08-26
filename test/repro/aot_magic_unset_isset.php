<?php
// AOT: undeclared unset/isset must call __unset/__isset (zend_std_unset_property / has_property).
class C
{
    public function __isset($k)
    {
        return $k === 'x';
    }

    public function __unset($k)
    {
        echo "u:$k\n";
    }
}
$o = new C();
var_dump(isset($o->x), isset($o->y));
unset($o->x);
