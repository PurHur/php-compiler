--TEST--
Language: magic method correct param types smoke (#26500)
--FILE--
<?php
class Good {
    public function __get(string $n) { return "g:$n"; }
    public function __set(string $n, $v): void {}
    public function __isset(string $n): bool { return false; }
    public function __unset(string $n): void {}
    public function __call(string $n, array $a) { return "c:$n"; }
    public static function __callStatic(string $n, array $a) { return "s:$n"; }
}
$g = new Good();
echo $g->x, "\n";
echo $g->y(), "\n";
echo Good::z(), "\n";
--EXPECT--
g:x
c:y
s:z
