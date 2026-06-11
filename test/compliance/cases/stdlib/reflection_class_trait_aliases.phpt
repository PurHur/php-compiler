--TEST--
ReflectionClass::getTraitAliases() — trait use alias map (#6661, ext/reflection/php_reflection.c)
--FILE--
<?php
trait T {
    public function f(): int {
        return 1;
    }
}
class C {
    use T {
        f as g;
    }
}
$rc = new ReflectionClass(C::class);
echo method_exists($rc, 'getTraitAliases') ? '1' : '0';
echo "\n";
var_export($rc->getTraitAliases());
echo "\n";
--EXPECT--
1
array (
  'g' => 'T::f',
)
