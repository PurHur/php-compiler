--TEST--
Language: ReflectionProperty::isFinal() for final plain properties under PROFILE=8.4 (#24280, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A {
    public final string $x = 'a';
}
echo 'instance_isFinal=', (new ReflectionProperty('A', 'x'))->isFinal() ? '1' : '0', "\n";

class S {
    public final static string $s = 's';
}
echo 'static_isFinal=', (new ReflectionProperty('S', 's'))->isFinal() ? '1' : '0', "\n";
--EXPECT--
instance_isFinal=1
static_isFinal=1
