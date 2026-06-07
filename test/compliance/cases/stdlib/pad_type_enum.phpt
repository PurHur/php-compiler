--TEST--
stdlib PadType enum for str_pad() (#7282, ext/standard/string.c)
--FILE--
<?php
var_export(enum_exists('PadType', false));
echo "\n";
var_export(PadType::Right->name);
echo "\n";
var_export(PadType::Left->value);
echo "\n";
var_export(PadType::Both->value);
echo "\n";
echo str_pad('hi', 5, ' ', 1), "\n";
echo str_pad('hi', 5, ' ', PadType::Right), "\n";
echo str_pad('hi', 5, ' ', PadType::Left), "\n";
echo str_pad('hi', 6, '-', PadType::Both), "\n";
enum Es: string { case B = 'hi'; }
try {
    str_pad('hi', 5, ' ', Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
'Right'
1
2
hi   
hi   
   hi
--hi--
str_pad(): Argument #4 ($pad_type) must be of type PadType|int, Es given
