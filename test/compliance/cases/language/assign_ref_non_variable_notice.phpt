--TEST--
language: `$a =& f()` / `$a =& $obj->m()` when callee does not return by ref — Notice + non-aliasing (#30015)
--FILE--
<?php
error_reporting(E_ALL);

function f() {
    static $x = 1;
    return $x;
}

$a =& f();
$a = 99;
echo 'f static=', f(), ' a=', $a, "\n";

function &g() {
    static $y = 1;
    return $y;
}

$b =& g();
$b = 42;
echo 'g static=', g(), ' b=', $b, "\n";

class A {
    function m() {
        return 1;
    }
    function &mr() {
        static $z = 5;
        return $z;
    }
}

$c =& (new A)->m();
$c = 3;
echo 'm c=', $c, "\n";

$d =& (new A)->mr();
$d = 7;
echo 'mr=', (new A)->mr(), ' d=', $d, "\n";

// Prior alias: notice path value-assigns through the existing reference (Zend).
$e = 1;
$f =& $e;
$f =& f();
$f = 8;
echo 'prior e=', $e, ' f=', $f, "\n";
--EXPECTF--
PHP Notice:  Only variables should be assigned by reference in %s on line %d
f static=1 a=99
g static=42 b=42
PHP Notice:  Only variables should be assigned by reference in %s on line %d
m c=3
mr=7 d=7
PHP Notice:  Only variables should be assigned by reference in %s on line %d
prior e=8 f=8
--CREDITS--
PurHur/php-compiler issue #30015
