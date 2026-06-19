<?php

declare(strict_types=1);

enum E: string {
    case A = 'a';
}

$rc = new ReflectionClassConstant(E::class, 'A');
var_export(method_exists($rc, 'isEnumCase') ? $rc->isEnumCase() : 'missing');
echo "\n";

class C {
    public const X = 1;
}
$ordinary = new ReflectionClassConstant(C::class, 'X');
var_export($ordinary->isEnumCase());
echo "\n";

enum U {
    case B;
}
$unit = new ReflectionClassConstant(U::class, 'B');
var_export($unit->isEnumCase());
echo "\n";
