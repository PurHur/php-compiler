--TEST--
Language: typed class constant with enum type preserves enum case (Zend/zend_compile.c, #9790)
--FILE--
<?php
enum Color: string { case Red = 'r'; case Blue = 'b'; }
class Palette {
    public const Color PRIMARY = Color::Red;
}
var_export(Palette::PRIMARY);
echo "\n";
echo Palette::PRIMARY->name, "\n";
$rc = new ReflectionClassConstant(Palette::class, 'PRIMARY');
var_export($rc->getValue());
echo "\n";
--EXPECT--
\Color::Red
Red
\Color::Red
