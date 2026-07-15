--TEST--
mbstring mb_convert_encoding() array|string source operand (#3222, ext/mbstring/mbstring.c)
--FILE--
<?php
$latin1 = ["\xE9", 'foo', 123];
$out = mb_convert_encoding($latin1, 'UTF-8', 'ISO-8859-1');
var_export($out);
echo "\n";

$assoc = ['a' => "\xE9", 'b' => 2];
var_export(mb_convert_encoding($assoc, 'UTF-8', 'ISO-8859-1'));
echo "\n";

enum E: string { case A = 'x'; }
try {
    mb_convert_encoding(E::A, 'UTF-8');
    echo "no_exception\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
array (
  0 => 'é',
  1 => 'foo',
  2 => 123,
)
array (
  'a' => 'é',
  'b' => 2,
)
mb_convert_encoding(): Argument #1 ($string) must be of type array|string, E given
