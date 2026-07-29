--TEST--
Language: magic method correct arity smoke (#25024)
--FILE--
<?php
class Good {
    public function __get($n) { return "g:$n"; }
    public function __set($n, $v) { echo "s:$n=$v\n"; }
    public function __isset($n) { return $n === 'yes'; }
    public function __unset($n) { echo "u:$n\n"; }
    public function __call($n, $a) { return "c:$n"; }
    public static function __callStatic($n, $a) { return "cs:$n"; }
    public function __unserialize($d) {}
    public static function __set_state($a) { return new self; }
}
$g = new Good();
echo $g->x, "\n";
$g->y = 1;
var_export(isset($g->yes)); echo "\n";
unset($g->z);
echo $g->m(), "\n";
echo Good::m(), "\n";
echo Good::__set_state([]) instanceof Good ? "ok\n" : "bad\n";
--EXPECT--
g:x
s:y=1
true
u:z
c:m
cs:m
ok
