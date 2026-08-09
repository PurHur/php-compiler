--TEST--
Reflection::getModifierNames() omits *(set) on PROFILE=8.4 (#29188, php-src #19691)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

class C {
    public private(set) string $x = 'a';
    public protected(set) int $y = 1;
}

foreach (['x', 'y'] as $n) {
    $rp = new ReflectionProperty(C::class, $n);
    echo $n, ':', implode(',', Reflection::getModifierNames($rp->getModifiers())),
         '|raw=', $rp->getModifiers(),
         '|privSet=', $rp->isPrivateSet() ? '1' : '0',
         '|protSet=', $rp->isProtectedSet() ? '1' : '0',
         "\n";
}
echo '2048=', json_encode(Reflection::getModifierNames(2048)), "\n";
echo '4096=', json_encode(Reflection::getModifierNames(4096)), "\n";
echo '4129=', json_encode(Reflection::getModifierNames(4129)), "\n";
?>
--EXPECT--
x:final,public|raw=4129|privSet=1|protSet=0
y:public|raw=2049|privSet=0|protSet=1
2048=[]
4096=[]
4129=["final","public"]
