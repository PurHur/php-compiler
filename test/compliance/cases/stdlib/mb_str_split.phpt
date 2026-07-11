--TEST--
stdlib mb_str_split() UTF-8 and ASCII chunking (VM)
--FILE--
<?php
var_export(mb_str_split('αβγ', 1));
echo "\n";
var_export(mb_str_split('ab', 2));
echo "\n";
var_export(mb_str_split('hello', 2, 'ASCII'));
echo "\n";
var_export(mb_str_split('', 1));
echo "\n";
try {
    mb_str_split('x', 0);
    echo "no_exception\n";
} catch (ValueError) {
    echo "length_zero=ValueError\n";
}
--EXPECT--
array (
  0 => 'α',
  1 => 'β',
  2 => 'γ',
)
array (
  0 => 'ab',
)
array (
  0 => 'he',
  1 => 'll',
  2 => 'o',
)
array (
)
length_zero=ValueError
