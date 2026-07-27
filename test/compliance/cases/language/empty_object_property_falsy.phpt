--TEST--
Language: empty() on falsy object/static properties matches Zend (#23983)
--FILE--
<?php
class C {
    public $zero = 0;
    public $empty = "";
    public $zerostr = "0";
    public $false = false;
    public $arr = [];
    public $fzero = 0.0;
    public $null = null;
    public $one = 1;
}
$o = new C();
foreach (["zero", "empty", "zerostr", "false", "arr", "fzero", "null", "one"] as $p) {
    var_export(empty($o->$p));
    echo " $p\n";
}
class S {
    public static $z = 0;
    public static $one = 1;
}
var_export(empty(S::$z));
echo " static0\n";
var_export(empty(S::$one));
echo " static1\n";
class M {
    public function __isset($n) { return true; }
    public function __get($n) { return 0; }
}
var_export(empty((new M)->x));
echo " magic\n";
$a = ["x" => 0];
var_export(empty($a["x"]));
echo " dim\n";
class Priv {
    private $x = 1;
}
var_export(empty((new Priv)->x));
echo " priv\n";
--EXPECT--
true zero
true empty
true zerostr
true false
true arr
true fzero
true null
false one
true static0
false static1
true magic
true dim
true priv
