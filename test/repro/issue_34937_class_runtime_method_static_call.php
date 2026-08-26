<?php
/** AOT: NamedClass::$runtimeMethod() must compile and match Zend (#34937). */
class C
{
    public static function f()
    {
        return 7;
    }

    public static function g()
    {
        return 'G';
    }
}

$m = 'f';
var_export(C::$m());
echo "\n";
$m2 = 'g';
var_export(C::$m2());
echo "\n";
