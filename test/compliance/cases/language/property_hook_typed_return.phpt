--TEST--
Property hook get return must match declared property type (#7301, zend_property_hooks.c)
--FILE--
<?php
class C {
    public int $x { get => 'not int'; }
}
try {
    $v = (new C())->x;
    var_dump($v);
} catch (TypeError $e) {
    echo "TypeError\n";
}
--EXPECT--
TypeError
