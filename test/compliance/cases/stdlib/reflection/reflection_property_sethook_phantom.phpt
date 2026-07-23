--TEST--
ReflectionProperty::setHook phantom vs Zend 8.4+ (#22494, re-#22116, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class T {
    public string $x {
        get => 'a';
        set {}
    }
}
$rp = new ReflectionProperty(T::class, 'x');
echo method_exists($rp, 'setHook') ? "1\n" : "0\n";
echo method_exists($rp, 'getHook') ? "get=1\n" : "get=0\n";
echo method_exists($rp, 'getHooks') ? "hooks=1\n" : "hooks=0\n";
--EXPECT--
0
get=1
hooks=1
