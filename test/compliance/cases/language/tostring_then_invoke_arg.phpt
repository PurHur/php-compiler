--TEST--
Language: (string)$obj then $obj($arg) passes $arg to __invoke (#22894, Zend/zend_object_handlers.c)
--FILE--
<?php
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
?>
--EXPECT--
first=42
str=M
after=42
concat=M-14
str=M
method=30
