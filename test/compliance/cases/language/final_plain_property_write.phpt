--TEST--
Language: final plain property writes allowed; override rejected (#23683, php-src-strict, Zend/zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public final string $x = "a";
}
$o = new C;
echo $o->x, "\n";
$o->x = "b";
echo "WROTE:", $o->x, "\n";
echo "isFinal=", (new ReflectionProperty("C", "x"))->isFinal() ? "1" : "0", "\n";

class D {
    public final string $y;
    public function __construct() {
        $this->y = "c";
    }
}
$d = new D;
echo $d->y, "\n";
$d->y = "d";
echo "WROTE2:", $d->y, "\n";
--EXPECT--
a
WROTE:b
isFinal=1
c
WROTE2:d
