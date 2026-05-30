--TEST--
Language: (bool)/(int)/(float) object cast — Zend cast_object (#3451)
--FILE--
<?php
class B {
    public function __toString(): string { return '42'; }
}
$o = new B();
echo (bool) $o ? "true\n" : "false\n";
echo (int) $o, "\n";
echo (float) $o, "\n";

class EmptyToString {
    public function __toString(): string { return ''; }
}
$empty = new EmptyToString();
echo (bool) $empty ? "true\n" : "false\n";

class NoMagic {}
$plain = new NoMagic();
echo (bool) $plain ? "true\n" : "false\n";

$intMsg = 'Object of class NoMagic could not be converted to int';
try {
    (int) $plain;
    echo "no int error\n";
} catch (TypeError $e) {
    echo $e->getMessage() === $intMsg ? "TypeError: int\n" : "wrong int\n";
} catch (Throwable $e) {
    echo "Throwable: int\n";
}

$floatMsg = 'Object of class NoMagic could not be converted to float';
try {
    (float) $plain;
    echo "no float error\n";
} catch (TypeError $e) {
    echo $e->getMessage() === $floatMsg ? "TypeError: float\n" : "wrong float\n";
} catch (Throwable $e) {
    echo "Throwable: float\n";
}
?>
--EXPECT--
true
42
42
false
true
TypeError: int
TypeError: float
