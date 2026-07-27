--TEST--
ReflectionProperty::isFinal() true for final plain properties (#23818, re-#23683, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class F
{
    public final string $x = 'a';
}
echo 'isFinal=', (new ReflectionProperty('F', 'x'))->isFinal() ? '1' : '0', "\n";
--EXPECT--
isFinal=1
