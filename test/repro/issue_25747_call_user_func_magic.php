<?php
// #25747 — call_user_func on missing methods must invoke __call / __callStatic
class C {
    public function __call($n, $a) { return "c:$n:" . count($a) . ':' . ($a[0] ?? ''); }
    public static function __callStatic($n, $a) { return "cs:$n:" . count($a) . ':' . ($a[0] ?? ''); }
}
$c = new C();
var_export(is_callable([$c, 'missing'])); echo "\n";
var_export(is_callable(['C', 'missing'])); echo "\n";
echo call_user_func([$c, 'missing']), "\n";
echo call_user_func(['C', 'missing']), "\n";
echo call_user_func([$c, 'missing'], 'x'), "\n";
echo call_user_func(['C', 'missing'], 'y'), "\n";
echo call_user_func_array([$c, 'missing'], ['a', 'b']), "\n";
echo call_user_func('C::missing', 'z'), "\n";
