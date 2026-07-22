--TEST--
Stdlib: ReflectionProperty::isStatic/isDefault/getModifiers (#22143, php_reflection.c)
--FILE--
<?php
class T
{
    public static $a = 1;
    public $b = 2;
    private $c;
    protected static $d;
    public readonly int $ro;

    public function __construct()
    {
        $this->ro = 1;
    }
}

echo method_exists(ReflectionProperty::class, 'isStatic') ? 'Y' : 'N', "\n";
foreach (['a', 'b', 'c', 'd', 'ro'] as $name) {
    $p = new ReflectionProperty(T::class, $name);
    echo $name, '=', $p->isStatic() ? '1' : '0', ',', $p->isDefault() ? '1' : '0', ',', $p->getModifiers(), "\n";
}
$o = new T();
$o->dyn = 1;
$dyn = new ReflectionProperty($o, 'dyn');
echo 'dyn=', $dyn->isStatic() ? '1' : '0', ',', $dyn->isDefault() ? '1' : '0', ',', $dyn->getModifiers(), "\n";
--EXPECT--
Y
a=1,1,17
b=0,1,1
c=0,1,4
d=1,1,18
ro=0,1,129
dyn=0,0,1
