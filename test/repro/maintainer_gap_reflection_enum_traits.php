<?php
declare(strict_types=1);

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

$rc = new ReflectionClass(E::class);
var_export($rc->getTraitNames());
echo "\n";

enum Plain {
    case B;
}
var_export((new ReflectionEnum(Plain::class))->getTraitNames());
echo "\n";
