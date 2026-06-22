--TEST--
Language: readonly property with hooks — constructor init via set hook (#9835, zend_property_hooks.c)
--FILE--
<?php
class C {
    public readonly string $name {
        get => $this->name;
        set (string $value) {
            $this->name = strtoupper($value);
        }
    }
    public function __construct() {
        $this->name = 'hello';
    }
}
$c = new C();
echo $c->name, "\n";
try {
    $c->name = 'after';
    echo "no-resume\n";
} catch (Error $e) {
    echo 'caught: ', get_class($e), "\n";
}
--EXPECT--
HELLO
caught: Error
