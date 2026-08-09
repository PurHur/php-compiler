--TEST--
Reflection::getModifierNames() includes *(set) on PROFILE=8.5 (#29188, php-src GH-19697)
--ENV--
PHP_COMPILER_PROFILE=8.5
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
x:final,public,private(set)|raw=4129|privSet=1|protSet=0
y:public,protected(set)|raw=2049|privSet=0|protSet=1
2048=["protected(set)"]
4096=["private(set)"]
4129=["final","public","private(set)"]
