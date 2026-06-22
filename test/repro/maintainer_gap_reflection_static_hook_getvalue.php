<?php
class C {
    public static int $x {
        get => 99;
    }
}
$rc = new ReflectionClass(C::class);
var_export($rc->getStaticPropertyValue('x'));
echo "\n";
