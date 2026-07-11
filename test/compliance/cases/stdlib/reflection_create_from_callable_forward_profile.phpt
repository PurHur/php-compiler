--TEST--
ReflectionFunction::createFromCallable() / ReflectionMethod::createFromClosure() forward profile (#7039, php_reflection.c)
--FILE--
<?php
echo 'createFromCallable=', var_export(method_exists('ReflectionFunction', 'createFromCallable'), true), "\n";
echo 'invoke=', var_export(method_exists('ReflectionFunction', 'invoke'), true), "\n";
echo 'createFromClosure=', var_export(method_exists('ReflectionMethod', 'createFromClosure'), true), "\n";

$fn = fn (): int => 42;
echo ReflectionFunction::createFromCallable($fn)->invoke(), "\n";
echo ReflectionFunction::createFromCallable($fn)->isAnonymous() ? "anon\n" : "named\n";

function named_rf(): int
{
    return 99;
}
echo ReflectionFunction::createFromCallable('named_rf')->invoke(), "\n";
echo ReflectionFunction::createFromCallable('named_rf')->isAnonymous() ? "anon\n" : "named\n";

class C {
    public function mul(int $x): int
    {
        return $x * 3;
    }

    public static function add(int $a, int $b): int
    {
        return $a + $b;
    }
}

$c = new C();
$instanceCl = Closure::fromCallable([$c, 'mul']);
$rm = ReflectionMethod::createFromClosure($instanceCl);
echo $rm->getName(), "\n";
echo $rm->invoke($c, 14), "\n";

$staticCl = Closure::fromCallable([C::class, 'add']);
$rms = ReflectionMethod::createFromClosure($staticCl);
echo $rms->getName(), "\n";
echo $rms->invoke(null, 5, 6), "\n";

try {
    ReflectionMethod::createFromClosure(fn () => 1);
    echo "no_ex\n";
} catch (ReflectionException $e) {
    echo "user_closure_ex\n";
}
--EXPECT--
createFromCallable=true
invoke=true
createFromClosure=true
42
anon
99
named
mul
42
add
11
user_closure_ex
