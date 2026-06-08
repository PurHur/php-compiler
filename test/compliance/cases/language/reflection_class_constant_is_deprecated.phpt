--TEST--
Language: ReflectionClassConstant::isDeprecated() on #[\Deprecated] class constants (#6920)
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
set_error_handler(function (): bool {
    return true;
});
echo C::X, "\n";
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
--EXPECT--
true
false
1
Constant C::X is deprecated since 8.4, Old const
