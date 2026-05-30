--TEST--
Instance readonly property rejects writes after construction (issue #169)
--FILE--
<?php
class C {
    public readonly string $code;
    public function __construct() {
        $this->code = 'x';
    }
}
$c = new C();
try {
    $c->code = 'y';
    echo "mutated\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot modify readonly property C::$code
