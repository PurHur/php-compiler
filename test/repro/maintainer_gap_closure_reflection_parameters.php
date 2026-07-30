<?php
/**
 * #25559 — Closure::fromCallable / FCC / getClosure Reflection arity mirrors Zend.
 */
function add(int $a, int $b = 3): int
{
    return $a + $b;
}

class C25559
{
    public function m(string $s, int $n = 1): string
    {
        return $s.$n;
    }
}

class Inv25559
{
    public function __invoke(int $x): int
    {
        return $x;
    }
}

$c = new C25559();
$failed = 0;

$check = static function (string $label, $cl, int $n, int $req, array $names) use (&$failed): void {
    if (!$cl instanceof Closure) {
        echo "FAIL $label: expected Closure, got ", get_debug_type($cl), "\n";
        $failed = 1;
        return;
    }
    $r = new ReflectionFunction($cl);
    $gotNames = [];
    foreach ($r->getParameters() as $p) {
        $gotNames[] = $p->getName();
    }
    $ok = $r->getNumberOfParameters() === $n
        && $r->getNumberOfRequiredParameters() === $req
        && $gotNames === $names;
    if (!$ok) {
        echo "FAIL $label: n=", $r->getNumberOfParameters(), ' req=', $r->getNumberOfRequiredParameters(),
            ' names=', implode(',', $gotNames), " expected n=$n req=$req names=", implode(',', $names), "\n";
        $failed = 1;
    } else {
        echo "OK $label\n";
    }
};

$check('fromCallable fn', Closure::fromCallable('add'), 2, 1, ['a', 'b']);
$fccFn = add(...);
$check('FCC fn', $fccFn, 2, 1, ['a', 'b']);
$check('fromCallable method', Closure::fromCallable([$c, 'm']), 2, 1, ['s', 'n']);
// Assign FCC first: mid-argument `$obj->m(...)` currently lowers to a callable array (separate gap).
$fccMethod = $c->m(...);
$check('FCC method', $fccMethod, 2, 1, ['s', 'n']);
$getClosure = (new ReflectionMethod(C25559::class, 'm'))->getClosure($c);
$check('getClosure', $getClosure, 2, 1, ['s', 'n']);
$check('fromCallable invoke', Closure::fromCallable(new Inv25559()), 1, 1, ['x']);

exit($failed);
