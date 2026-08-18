<?php
foreach (['forward_static_call', 'forward_static_call_array'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' names=';
    foreach ($r->getParameters() as $p) {
        echo $p->getName(), $p->isVariadic() ? '...' : '', ';';
    }
    echo "\n";
}
class A
{
    public static function f(int $x = 2, int $y = 3): int
    {
        return $x + $y;
    }
}
class B extends A
{
    public static function run(): void
    {
        try {
            echo 'fsca=', forward_static_call_array(callback: [A::class, 'f'], args: [2, 3]), "\n";
        } catch (Throwable $e) {
            echo 'fsca_err=', $e->getMessage(), "\n";
        }
        try {
            echo 'fsc_named=', forward_static_call(callback: [A::class, 'f']), "\n";
        } catch (Throwable $e) {
            echo 'fsc_named_err=', $e->getMessage(), "\n";
        }
        try {
            echo 'fsc=', forward_static_call([A::class, 'f'], 2, 3), "\n";
        } catch (Throwable $e) {
            echo 'fsc_err=', $e->getMessage(), "\n";
        }
    }
}
B::run();
