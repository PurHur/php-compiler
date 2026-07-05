--TEST--
Stdlib: ReflectionClass::getProperties() filter flags + inheritance (#4470, ext/reflection/php_reflection.c)
--FILE--
<?php
class A {
    public int $ap = 1;
    protected int $ar = 2;
    private int $ai = 3;
    public static int $as = 0;
}
class B extends A {
    public int $bp = 4;
    private int $bi = 5;
}

$rc = new ReflectionClass(B::class);

$unfiltered = [];
foreach ($rc->getProperties() as $p) {
    $unfiltered[] = $p->getDeclaringClass()->getName() . '::$' . $p->getName();
}
echo implode("\n", $unfiltered), "\n";

$public = [];
foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
    $public[] = $p->getDeclaringClass()->getName() . '::$' . $p->getName();
}
echo implode("\n", $public), "\n";

$private = [];
foreach ($rc->getProperties(ReflectionProperty::IS_PRIVATE) as $p) {
    $private[] = $p->getDeclaringClass()->getName() . '::$' . $p->getName();
}
echo implode("\n", $private), "\n";
--EXPECT--
B::$bp
B::$bi
A::$ap
A::$ar
A::$as
B::$bp
A::$ap
A::$as
B::$bi
