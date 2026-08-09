<?php
// get_object_vars must still invoke get (not part of DEBUG purpose)
class C {
    public $x = 1 {
        get => $this->x + 100;
    }
}
var_export(get_object_vars(new C));
echo "\n";
var_export(new C); // var_export also invokes get on Zend
echo "\n";
