--TEST--
Language: ReflectionClassConstant::isEnumCase() — enum case vs user const (#9824)
--FILE--
<?php
enum E: string {
    case A = 'a';
    public const META = 'meta';
}
enum U {
    case B;
}
$case = new ReflectionClassConstant(E::class, 'A');
echo $case->isEnumCase() ? "1" : "0";
echo "\n";
$userConst = new ReflectionClassConstant(E::class, 'META');
echo $userConst->isEnumCase() ? "1" : "0";
echo "\n";
class C {
    public const X = 1;
}
$ordinary = new ReflectionClassConstant(C::class, 'X');
echo $ordinary->isEnumCase() ? "1" : "0";
echo "\n";
$unitCase = new ReflectionClassConstant(U::class, 'B');
echo $unitCase->isEnumCase() ? "1" : "0";
echo "\n";
--EXPECT--
1
0
0
1
