--TEST--
Stdlib: ReflectionClassConstant::isFinal() on final class constants (#6516)
--FILE--
<?php
class Config {
    final public const VERSION = 1;
    public const NAME = 'app';
}
$final = new ReflectionClassConstant(Config::class, 'VERSION');
$plain = new ReflectionClassConstant(Config::class, 'NAME');
var_export($final->isFinal());
echo "\n";
var_export($plain->isFinal());
echo "\n";
--EXPECT--
true
false
