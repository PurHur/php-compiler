<?php
/**
 * Issue #22894 — after (string)$obj / concat coercion, $obj($arg) must pass $arg to __invoke
 * (Zend/zend_object_handlers.c), not the __toString return value.
 */
class M {
    public function __toString() {
        return 'M';
    }

    public function __invoke($x) {
        return is_int($x) ? ($x * 2) : ('got:'.gettype($x).':'.var_export($x, true));
    }

    public function f($x) {
        return $x * 3;
    }
}

$m = new M;
echo 'first=', $m(21), "\n";
$m2 = new M;
echo 'str=', (string) $m2, "\n";
echo 'after=', $m2(21), "\n";
$m3 = new M;
echo 'concat=', $m3.'-'.$m3(7), "\n";
$m4 = new M;
echo 'str=', (string) $m4, "\n";
echo 'method=', $m4->f(10), "\n";
