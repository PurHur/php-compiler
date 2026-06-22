--TEST--
ReflectionEnum::getTraitNames() — enum trait list (#9693, ext/reflection/php_reflection.c)
--FILE--
<?php
trait Tr {
    public function x(): int {
        return 1;
    }
}
enum E {
    case A;
    use Tr;
}
$r = new ReflectionEnum(E::class);
echo method_exists($r, 'getTraitNames') ? '1' : '0';
echo "\n";
var_export($r->getTraitNames());
echo "\n";

enum Plain {
    case B;
}
var_export((new ReflectionEnum(Plain::class))->getTraitNames());
echo "\n";
--EXPECT--
1
array (
  0 => 'Tr',
)
array (
)
