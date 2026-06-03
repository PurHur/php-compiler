--TEST--
Static property hooks get/set dispatch (issue #4751, Zend/zend_property_hooks.c)
--FILE--
<?php
class Box {
    public static string $label {
        get => 'static:' . self::$label;
        set => strtoupper($value);
    }
}
Box::$label = 'hi';
echo Box::$label, "\n";
--EXPECT--
static:HI
