<?php
// Issue #23742 — var_export() object __set_state property indent (3 spaces vs array 2)
class O {
    public $a = 1;
    private $b = 2;
}
$object = var_export(new O(), true);
$array = var_export([1, 2], true);
assert(str_contains($object, "   'a' => 1,"), 'object indent: '.$object);
assert(str_contains($array, "  0 => 1,"), 'array indent: '.$array);
echo "OK\n";
