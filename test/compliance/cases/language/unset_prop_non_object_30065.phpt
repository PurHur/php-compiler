--TEST--
Language: unset($scalar->prop) silent no-op (#30065, zend_vm_def.h ZEND_UNSET_OBJ)
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $errno, string $msg) use (&$seen): bool {
    $seen[] = [$errno, $msg];
    return true;
});

$f = false;
unset($f->a);
echo (0 === count($seen) && false === $f) ? "false_ok\n" : "false_bad\n";

$t = true;
unset($t->a);
echo (0 === count($seen) && true === $t) ? "true_ok\n" : "true_bad\n";

$n = null;
unset($n->a);
echo (0 === count($seen) && null === $n) ? "null_ok\n" : "null_bad\n";

$i = 1;
unset($i->a);
echo (0 === count($seen) && 1 === $i) ? "int_ok\n" : "int_bad\n";

$fl = 1.5;
unset($fl->a);
echo (0 === count($seen) && 1.5 === $fl) ? "float_ok\n" : "float_bad\n";

$s = 'hi';
unset($s->a);
echo (0 === count($seen) && 'hi' === $s) ? "str_ok\n" : "str_bad\n";

$a = ['a' => 1, 'b' => 2];
unset($a->a);
echo (0 === count($seen) && ['a' => 1, 'b' => 2] === $a) ? "arr_ok\n" : "arr_bad\n";
--EXPECT--
false_ok
true_ok
null_ok
int_ok
float_ok
str_ok
arr_ok
