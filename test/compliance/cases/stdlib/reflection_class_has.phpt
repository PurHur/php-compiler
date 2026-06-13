--TEST--
stdlib ReflectionClass::hasMethod/hasProperty/hasConstant (#6301, php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

class Parent_ {
    public function foo(): void {}
    private function bar(): void {}
    public const C = 1;
    public int $p = 0;
}
class Child extends Parent_ {
    public int $q = 1;
}
$r = new ReflectionClass(stdClass::class);
foreach (['hasMethod', 'hasProperty', 'hasConstant'] as $m) {
    echo $m, '=', method_exists($r, $m) ? 'yes' : 'no', "\n";
}
echo 'std_toString=', $r->hasMethod('__toString') ? 'yes' : 'no', "\n";
$c = new ReflectionClass(Child::class);
echo 'has_foo=', $c->hasMethod('foo') ? 'yes' : 'no', "\n";
echo 'has_bar=', $c->hasMethod('bar') ? 'yes' : 'no', "\n";
echo 'has_q=', $c->hasProperty('q') ? 'yes' : 'no', "\n";
echo 'has_p=', $c->hasProperty('p') ? 'yes' : 'no', "\n";
echo 'has_C=', $c->hasConstant('C') ? 'yes' : 'no', "\n";
echo 'has_missing=', $c->hasConstant('MISSING') ? 'yes' : 'no', "\n";
--EXPECT--
hasMethod=yes
hasProperty=yes
hasConstant=yes
std_toString=no
has_foo=yes
has_bar=no
has_q=yes
has_p=yes
has_C=yes
has_missing=no
