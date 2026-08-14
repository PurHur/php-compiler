<?php
// Repro #31127 — ReflectionMethod modifier/kind/prototype excess argc
class A { function m() {} }
class B extends A { function m() {} }
class T { public function m() {} }
$m = new ReflectionMethod(T::class, 'm');
$bp = new ReflectionMethod(B::class, 'm');
$none = new ReflectionMethod(T::class, 'm');
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
    $none->getPrototype();
    echo "noproto: SILENT\n";
} catch (Throwable $e) {
    echo 'noproto: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    (new ReflectionMethod(T::class, 'm'))->isClosure(1);
    echo "already: SILENT\n";
} catch (Throwable $e) {
    echo 'already: ', get_class($e), ': ', $e->getMessage(), "\n";
}
