--TEST--
readonly property: reference assignment write rejected after construction (issue #4273)
--FILE--
<?php
class C {
    public readonly int $x;
    public function __construct() { $this->x = 1; }
}
$c = new C();
$r = &$c->x;
try {
    $r = 99;
    echo "write_ok:", $c->x, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
readonly class RC {
    public int $x;
    public function __construct() { $this->x = 1; }
}
$rc = new RC();
$rr = &$rc->x;
try {
    $rr = 2;
    echo "rc_write_ok\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot modify readonly property C::$x
Error: Cannot modify readonly property RC::$x
