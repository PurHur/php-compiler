--TEST--
ReflectionMethod::getDeclaringClass() on inherited method — parent declarer (#15658, ext/reflection/php_reflection.c)
--FILE--
<?php
class ParentClass
{
    public function inherited(): void
    {
    }
}

class ChildClass extends ParentClass
{
}

$viaObject = new ReflectionMethod(new ChildClass(), 'inherited');
$viaName = new ReflectionMethod(ChildClass::class, 'inherited');
echo $viaObject->getDeclaringClass()->getName(), "\n";
echo $viaName->getDeclaringClass()->getName(), "\n";
--EXPECT--
ParentClass
ParentClass
