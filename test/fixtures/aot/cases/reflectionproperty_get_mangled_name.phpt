--TEST--
AOT ReflectionProperty::getMangledName() matches Zend (#27592)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
class C {
    private $x = 1;
    protected $y = 2;
    public $z = 3;
}
foreach (['x', 'y', 'z'] as $n) {
    $m = (new ReflectionProperty(C::class, $n))->getMangledName();
    $expect = match ($n) {
        'x' => "\0C\0x",
        'y' => "\0*\0y",
        'z' => 'z',
    };
    echo $n, '=', ($m === $expect ? 'ok' : 'bad'), "\n";
}
--EXPECT--
x=ok
y=ok
z=ok
