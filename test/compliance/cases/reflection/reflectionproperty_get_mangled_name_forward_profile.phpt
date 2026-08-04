--TEST--
ReflectionProperty::getMangledName() on PROFILE=8.5 (#27592, ext/reflection/php_reflection.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
class C {
    private $x = 1;
    protected $y = 2;
    public $z = 3;
}
echo method_exists(ReflectionProperty::class, 'getMangledName') ? "Y\n" : "missing\n";
foreach ((new ReflectionClass(C::class))->getProperties() as $p) {
    $m = $p->getMangledName();
    $expect = match ($p->getName()) {
        'x' => "\0C\0x",
        'y' => "\0*\0y",
        'z' => 'z',
    };
    echo $p->getName(), '=', ($m === $expect ? 'ok' : 'bad:'.strlen($m)), "\n";
}
$rm = new ReflectionMethod(ReflectionProperty::class, 'getMangledName');
echo 'ret=', (string) $rm->getReturnType(), "\n";
--EXPECT--
Y
x=ok
y=ok
z=ok
ret=string
