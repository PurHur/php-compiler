--TEST--
Language: ReflectionClassConstant::isDeprecated() on #[\Deprecated] class constants (#6920, #16820)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    #[\Deprecated(message: 'Old const', since: '8.4')]
    public const X = 1;
    public const Y = 2;
}

$rc = new ReflectionClassConstant(C::class, 'X');
var_export($rc->isDeprecated());
echo "\n";
$control = new ReflectionClassConstant(C::class, 'Y');
var_export($control->isDeprecated());
echo "\n";

ini_set('error_reporting', '32767');
ini_set('display_errors', '0');
echo C::X, "\n";
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "dep\n" : "no\n";
--EXPECT--
true
false
1
Constant C::X is deprecated since 8.4, Old const
dep
