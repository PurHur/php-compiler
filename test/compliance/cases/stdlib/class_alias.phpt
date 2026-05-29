--TEST--
Stdlib: class_alias() — alternate class names (#3095)
--FILE--
<?php
class Real {
    public static function tag(): string {
        return 'real';
    }
}
var_export(class_alias(Real::class, 'Alias'));
echo "\n";
var_export(class_exists('Alias', false));
echo "\n";
$obj = new Alias();
echo get_class($obj), "\n";
echo Real::tag(), "\n";
echo Alias::tag(), "\n";
var_export(class_alias(Real::class, 'Alias'));
echo "\n";
--EXPECT--
true
true
Real
real
real
false
