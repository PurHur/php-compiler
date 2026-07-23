<?php
// #22547 — get_object_vars method-scope must include current-class private (ext/standard/var.c)
class P {
    private $p = 1;
    protected $r = 2;
    public $u = 3;
    public function fromP()
    {
        return get_object_vars($this);
    }
}
class C extends P {
    private $p = 4;
    public function fromC()
    {
        return get_object_vars($this);
    }
}
$c = new C();
echo 'fromC=';
var_export($c->fromC());
echo "\nfromP=";
var_export($c->fromP());
echo "\nglobal=";
var_export(get_object_vars($c));
echo "\n";
