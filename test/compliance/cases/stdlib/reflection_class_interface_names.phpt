--TEST--
ReflectionClass::getInterfaceNames() — enum trait/interface introspection (#9692, ext/reflection/php_reflection.c)
--FILE--
<?php
trait Tr {
    public function x(): int {
        return 1;
    }
}
interface Iface {}
enum E implements Iface {
    case A;
    use Tr;
}
$r = new ReflectionClass(E::class);
echo method_exists($r, 'getInterfaceNames') ? '1' : '0';
echo "\n";
var_export($r->getInterfaceNames());
echo "\n";
var_export($r->getTraitNames());
echo "\n";

interface A {}
interface B extends A {}
class C implements B {}
var_export((new ReflectionClass(C::class))->getInterfaceNames());
echo "\n";
--EXPECT--
1
array (
  0 => 'Iface',
  1 => 'UnitEnum',
)
array (
  0 => 'Tr',
)
array (
  0 => 'B',
  1 => 'A',
)
