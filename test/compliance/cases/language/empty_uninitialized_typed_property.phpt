--TEST--
Language: empty() on uninitialized typed property returns true (#6787, zend_object_handlers.c)
--FILE--
<?php
class C {
    public int $x;
    public static int $s;
}
$c = new C();
var_export(empty($c->x));
echo "\n";
var_export(isset($c->x));
echo "\n";
var_export(empty(C::$s));
echo "\n";
var_export(isset(C::$s));
echo "\n";
try {
    $_ = $c->x;
    echo "no direct instance throw\n";
} catch (\Error $e) {
    echo "direct instance throw\n";
}
try {
    $_ = C::$s;
    echo "no direct static throw\n";
} catch (\Error $e) {
    echo "direct static throw\n";
}
$c->x = 0;
var_export(empty($c->x));
echo "\n";
$c->x = 1;
var_export(empty($c->x));
echo "\n";
--EXPECT--
true
false
true
false
direct instance throw
direct static throw
true
false
