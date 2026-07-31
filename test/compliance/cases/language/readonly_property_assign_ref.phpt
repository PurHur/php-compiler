--TEST--
readonly property: reference fetch rejected (issue #4273 / #25620, zend_readonly.c)
--FILE--
<?php
class C {
    public readonly int $x;
    public function __construct() { $this->x = 1; }
}
$c = new C();
try {
    $r = &$c->x;
    echo "ref_ok\n";
} catch (Error $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}
readonly class RC {
    public int $x;
    public function __construct() { $this->x = 1; }
}
$rc = new RC();
try {
    $rr = &$rc->x;
    echo "rc_ref_ok\n";
} catch (Error $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot modify readonly property C::$x
Error: Cannot modify readonly property RC::$x
