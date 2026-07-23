--TEST--
Stdlib: get_object_vars() includes current-class private when parent has same-name private (#22547, ext/standard/var.c)
--FILE--
<?php
class P22547 {
    private $p = 1;
    protected $r = 2;
    public $u = 3;
    public function fromP()
    {
        return get_object_vars($this);
    }
}
class C22547 extends P22547 {
    private $p = 4;
    public function fromC()
    {
        return get_object_vars($this);
    }
}
$c = new C22547();
echo 'fromC=';
var_export($c->fromC());
echo "\nfromP=";
var_export($c->fromP());
echo "\nglobal=";
var_export(get_object_vars($c));
echo "\n";
--EXPECT--
fromC=array (
  'r' => 2,
  'u' => 3,
  'p' => 4,
)
fromP=array (
  'p' => 1,
  'r' => 2,
  'u' => 3,
)
global=array (
  'u' => 3,
)
