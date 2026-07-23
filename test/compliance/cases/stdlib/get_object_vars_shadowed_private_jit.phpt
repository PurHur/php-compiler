--TEST--
Stdlib: get_object_vars() shadowed private — JIT (#22547, ext/standard/var.c)
--FILE--
<?php
class P22547Jit {
    private $p = 1;
    protected $r = 2;
    public $u = 3;
    public function fromP()
    {
        return get_object_vars($this);
    }
}
class C22547Jit extends P22547Jit {
    private $p = 4;
    public function fromC()
    {
        return get_object_vars($this);
    }
}
$c = new C22547Jit();
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
