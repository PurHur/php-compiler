--TEST--
Stdlib: ReflectionFunctionAbstract::getNumberOfParameters() (#9723, ext/reflection/php_reflection.c)
--FILE--
<?php
function f(int $a, string $b = 'x'): void {}
$r = new ReflectionFunction('f');
echo method_exists($r, 'getNumberOfParameters') ? 'yes' : 'no', "\n";
echo $r->getNumberOfParameters(), "\n";
echo count($r->getParameters()) === $r->getNumberOfParameters() ? 'match' : 'mismatch', "\n";

echo (new ReflectionFunction('strlen'))->getNumberOfParameters(), "\n";

class C {
    public static function m(int $x, float $y = 1.0): void {}
}
$rm = new ReflectionMethod('C', 'm');
echo $rm->getNumberOfParameters(), "\n";
echo count($rm->getParameters()) === $rm->getNumberOfParameters() ? 'match' : 'mismatch', "\n";
--EXPECT--
yes
2
match
1
2
match
