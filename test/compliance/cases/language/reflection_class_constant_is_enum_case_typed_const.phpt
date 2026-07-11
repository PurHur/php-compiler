--TEST--
Language: ReflectionClassConstant::isEnumCase() on enum-valued typed class constants (#17361)
--FILE--
<?php
enum Color {
    case Red;
}
class D {
    public const Color SWATCH = Color::Red;
    public const int PLAIN = 1;
}
enum E {
    case A;
    public const META = 1;
}
$enumValued = new ReflectionClassConstant(D::class, 'SWATCH');
$plain = new ReflectionClassConstant(D::class, 'PLAIN');
echo $enumValued->isEnumCase() ? "1" : "0";
echo "\n";
echo $plain->isEnumCase() ? "1" : "0";
echo "\n";
$case = new ReflectionClassConstant(E::class, 'A');
echo $case->isEnumCase() ? "1" : "0";
echo "\n";
--EXPECT--
1
0
1
