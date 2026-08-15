--TEST--
language: ReflectionMethod kind/query excess argc → ArgumentCountError JIT (#31127, php_reflection.c)
--FILE--
<?php
class A { function m() {} }
class B extends A { function m() {} }
class T { public function m() {} }
$m = new ReflectionMethod(T::class, 'm');
$bp = new ReflectionMethod(B::class, 'm');
foreach ([
    'isPublic' => fn () => $m->isPublic(1),
    'isPrivate' => fn () => $m->isPrivate(1),
    'isProtected' => fn () => $m->isProtected(1),
    'isStatic' => fn () => $m->isStatic(1),
    'isFinal' => fn () => $m->isFinal(1),
    'isAbstract' => fn () => $m->isAbstract(1),
    'isConstructor' => fn () => $m->isConstructor(1),
    'isDestructor' => fn () => $m->isDestructor(1),
    'getModifiers' => fn () => $m->getModifiers(1),
    'getDeclaringClass' => fn () => $m->getDeclaringClass(1)->getName(),
    'getPrototype' => fn () => $bp->getPrototype(1)->class.'::'.$bp->getPrototype(1)->name,
] as $n => $fn) {
    try {
        var_export($fn());
        echo " $n: SILENT\n";
    } catch (Throwable $e) {
        echo "$n: ", get_class($e), ": ", $e->getMessage(), "\n";
    }
}
echo 'ok=', $m->isPublic() ? '1' : '0', ',',
    $m->isPrivate() ? '1' : '0', ',',
    $m->isProtected() ? '1' : '0', ',',
    $m->isStatic() ? '1' : '0', ',',
    $m->isFinal() ? '1' : '0', ',',
    $m->isAbstract() ? '1' : '0', ',',
    $m->isConstructor() ? '1' : '0', ',',
    $m->isDestructor() ? '1' : '0', ',',
    $m->getModifiers(), ',',
    $m->getDeclaringClass()->getName(), ',',
    $bp->getPrototype()->class.'::'.$bp->getPrototype()->name, "\n";
try {
    $m->getPrototype();
    echo "noproto: SILENT\n";
} catch (Throwable $e) {
    echo 'noproto: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
isPublic: ArgumentCountError: ReflectionMethod::isPublic() expects exactly 0 arguments, 1 given
isPrivate: ArgumentCountError: ReflectionMethod::isPrivate() expects exactly 0 arguments, 1 given
isProtected: ArgumentCountError: ReflectionMethod::isProtected() expects exactly 0 arguments, 1 given
isStatic: ArgumentCountError: ReflectionFunctionAbstract::isStatic() expects exactly 0 arguments, 1 given
isFinal: ArgumentCountError: ReflectionMethod::isFinal() expects exactly 0 arguments, 1 given
isAbstract: ArgumentCountError: ReflectionMethod::isAbstract() expects exactly 0 arguments, 1 given
isConstructor: ArgumentCountError: ReflectionMethod::isConstructor() expects exactly 0 arguments, 1 given
isDestructor: ArgumentCountError: ReflectionMethod::isDestructor() expects exactly 0 arguments, 1 given
getModifiers: ArgumentCountError: ReflectionMethod::getModifiers() expects exactly 0 arguments, 1 given
getDeclaringClass: ArgumentCountError: ReflectionMethod::getDeclaringClass() expects exactly 0 arguments, 1 given
getPrototype: ArgumentCountError: ReflectionMethod::getPrototype() expects exactly 0 arguments, 1 given
ok=1,0,0,0,0,0,0,0,1,T,A::m
noproto: ReflectionException: Method T::m does not have a prototype
