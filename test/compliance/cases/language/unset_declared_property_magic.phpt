--TEST--
Language: unset() declared public property then __get/__isset/__set (zend_object_handlers.c, #25810)
--FILE--
<?php
error_reporting(E_ALL);
class A {
    public $x = 1;
    public function __get($n) { return "get:$n"; }
    public function __isset($n) { return true; }
    public function __set($n, $v) { echo "set:$n\n"; $this->$n = $v; }
}
$a = new A();
unset($a->x);
echo "read=", var_export($a->x, true), "\n";
echo "isset=", var_export(isset($a->x), true), "\n";
$a->x = 9;
echo "after_set=", var_export($a->x, true), "\n";

// No-magic path stays Warning + NULL (#22021).
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');
class B {
    public $y = 1;
}
$b = new B();
unset($b->y);
echo "bare=", var_export($b->y, true), "\n";
echo "bare_isset=", var_export(isset($b->y), true), "\n";
--EXPECT--
read='get:x'
isset=true
set:x
after_set=9
bare=W:Undefined property: B::$y
NULL
bare_isset=false
