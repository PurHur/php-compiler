--TEST--
Language: typed class constant with enum type preserves enum case (#9790, Zend/zend_compile.c)
--FILE--
<?php
enum Color: string {
    case Red = 'r';
    case Blue = 'b';
}
class Palette {
    public const Color PRIMARY = Color::Red;
}
echo get_debug_type(Palette::PRIMARY), "\n";
echo (Palette::PRIMARY === Color::Red) ? "same\n" : "diff\n";
echo Palette::PRIMARY->name, "\n";
$rc = new ReflectionClassConstant(Palette::class, 'PRIMARY');
$v = $rc->getValue();
echo get_debug_type($v), "\n";
echo $v->name, "\n";
--EXPECT--
Color
same
Red
Color
Red
