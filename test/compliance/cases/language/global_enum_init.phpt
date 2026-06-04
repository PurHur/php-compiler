--TEST--
Language: global variable init with enum case preserves singleton (#5752)
--FILE--
<?php
declare(strict_types=1);

enum Color: string { case Red = 'r'; }
$g = Color::Red;
var_dump($g);
function f(): void { global $g; var_dump($g); }
f();
--EXPECT--
enum(Color::Red)
enum(Color::Red)
