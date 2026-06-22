--TEST--
Language: readonly property with hooks — post-construct write Error (#9835, zend_property_hooks.c)
--FILE--
<?php
class C {
    public readonly int $x {
        get => $this->x;
        set { $this->x = $value; }
    }
    public function __construct() {
        $this->x = 0;
    }
}
$c = new C();
try {
    $c->x = 1;
    echo "no-resume\n";
} catch (Error $e) {
    echo 'caught: ', get_class($e), "\n";
}
--EXPECT--
caught: Error
