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
