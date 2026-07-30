--TEST--
Closure::fromCallable / FCC / getClosure ReflectionFunction parameter arity (#25559, Zend/zend_closures.c)
--FILE--
<?php
function add_25559(int $a, int $b = 3): int
{
    return $a + $b;
}

class C25559
{
    public function m(string $s, int $n = 1): string
    {
        return $s . $n;
    }
}

class Inv25559
{
    public function __invoke(int $x): int
    {
        return $x;
    }
}

$dump = static function (string $label, Closure $cl): void {
    $r = new ReflectionFunction($cl);
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $label, '=', $r->getNumberOfParameters(), ',', $r->getNumberOfRequiredParameters(),
        ',', implode('|', $names), ',', $r->getName(), "\n";
};

$c = new C25559();
$dump('fromCallable_fn', Closure::fromCallable('add_25559'));
$dump('fcc_fn', add_25559(...));
$dump('fromCallable_method', Closure::fromCallable([$c, 'm']));
$dump('fcc_method', $c->m(...));
$dump('getClosure', (new ReflectionMethod(C25559::class, 'm'))->getClosure($c));
$dump('fromCallable_invoke', Closure::fromCallable(new Inv25559()));

$anon = function (int $z = 0): int {
    return $z;
};
$dump('user_closure', $anon);

$s = Closure::fromCallable('strlen');
$dump('strlen', $s);
?>
--EXPECT--
fromCallable_fn=2,1,a|b,add_25559
fcc_fn=2,1,a|b,add_25559
fromCallable_method=2,1,s|n,m
fcc_method=2,1,s|n,m
getClosure=2,1,s|n,m
fromCallable_invoke=1,1,x,__invoke
user_closure=1,0,z,{closure}
strlen=1,1,string,strlen
