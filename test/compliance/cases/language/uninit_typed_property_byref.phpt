--TEST--
Language: &$obj->typed on uninitialized non-nullable property throws Error (#31771, zend_object_handlers.c)
--FILE--
<?php
class C {
    public int $x;
    public ?int $y;
}
class S {
    public static int $s;
    public static ?int $t;
}
$o = new C;
try {
    $r = &$o->x;
    echo "ref_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo "after\n";
$r2 = &$o->y;
var_dump($r2);
var_dump($o->y);
try {
    $rs = &S::$s;
    echo "static_ref_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$rt = &S::$t;
var_dump($rt);
var_dump(S::$t);
--EXPECT--
Cannot access uninitialized non-nullable property C::$x by reference
after
NULL
NULL
Cannot access uninitialized non-nullable property S::$s by reference
NULL
NULL
