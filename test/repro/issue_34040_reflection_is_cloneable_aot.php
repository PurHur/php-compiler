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

interface IFace {}
abstract class Abs
{
    abstract public function f();
}

echo 'D=', (new ReflectionClass(DefaultClone::class))->isCloneable() ? '1' : '0', "\n";
echo 'Pr=', (new ReflectionClass(PrivateClone::class))->isCloneable() ? '1' : '0', "\n";
echo 'Pu=', (new ReflectionClass(PublicClone::class))->isCloneable() ? '1' : '0', "\n";
echo 'Po=', (new ReflectionClass(ProtectedClone::class))->isCloneable() ? '1' : '0', "\n";
echo 'Ch=', (new ReflectionClass(ChildOfPrivateClone::class))->isCloneable() ? '1' : '0', "\n";
echo 'I=', (new ReflectionClass(IFace::class))->isCloneable() ? '1' : '0', "\n";
echo 'A=', (new ReflectionClass(Abs::class))->isCloneable() ? '1' : '0', "\n";
echo 'E=', (new ReflectionClass(Exception::class))->isCloneable() ? '1' : '0', "\n";
