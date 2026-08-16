--TEST--
PadType enum never registered on PROFILE=8.4 (#28201, re-#7282); str_pad uses STR_PAD_*
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo enum_exists('PadType', false) ? "enum=1
" : "enum=0
";
echo class_exists('PadType', false) ? "class=1
" : "class=0
";
echo str_pad('hi', 5, ' ', STR_PAD_RIGHT), "\n";
echo str_pad('hi', 5, ' ', STR_PAD_LEFT), "\n";
echo str_pad('hi', 6, '-', STR_PAD_BOTH), "\n";
enum Es: string { case B = 'hi'; }
try {
    str_pad('hi', 5, ' ', Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
enum=0
class=0
hi   
   hi
--hi--
str_pad(): Argument #4 ($pad_type) must be of type int, Es given
