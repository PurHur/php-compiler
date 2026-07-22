--TEST--
ReflectionClass::isCloneable — __clone visibility (ext/reflection/php_reflection.c, #22109)
--FILE--
<?php
class DefaultClone {}

class PrivateClone
{
    private function __clone() {}
}

class PublicClone
{
    public function __clone() {}
}

class ProtectedClone
{
    protected function __clone() {}
}

class ChildOfPrivateClone extends PrivateClone {}

echo (new ReflectionClass(DefaultClone::class))->isCloneable() ? '1' : '0';
echo "\n";
echo (new ReflectionClass(PrivateClone::class))->isCloneable() ? '1' : '0';
echo "\n";
echo (new ReflectionClass(PublicClone::class))->isCloneable() ? '1' : '0';
echo "\n";
echo (new ReflectionClass(ProtectedClone::class))->isCloneable() ? '1' : '0';
echo "\n";
echo (new ReflectionClass(ChildOfPrivateClone::class))->isCloneable() ? '1' : '0';
echo "\n";
--EXPECT--
1
0
1
0
0
