--TEST--
Stdlib: ReflectionProperty::hasType() typed vs untyped (#22063)
--FILE--
<?php
class T {
    public int $a;
    public $b;
}
foreach (['a', 'b'] as $n) {
    $p = new ReflectionProperty(T::class, $n);
    echo $n, ' hasType=', $p->hasType() ? '1' : '0', "\n";
}
$p = new ReflectionProperty(T::class, 'a');
echo $p->getType()->getName(), "\n";
--EXPECT--
a hasType=1
b hasType=0
int
