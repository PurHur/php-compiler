<?php
declare(strict_types=1);
enum Color: string { case Red = 'r'; case Blue = 'b'; }
class Palette {
    public const Color PRIMARY = Color::Red;
}
var_export(Palette::PRIMARY);
echo "\n";
echo Palette::PRIMARY->name, "\n";
