<?php
class C {
    public int $x {
        get { return $this->x; }
        set => $this->x = $value;
    }
    private int $x; // uninitialized
}
$c = new C();
var_export(isset($c->x));
echo "\n";
