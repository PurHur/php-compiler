<?php
class C {
    public string $p {
        get { return $this->p; }
        set (string $value) { $this->p = $value; }
    }
}
$c = new C();
$c->p = 'a';
unset($c->p);
var_export(isset($c->p));
echo "\n";
$c->p = 'b';
echo $c->p, "\n";
