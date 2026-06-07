--TEST--
stdlib StringTrimMode enum for trim()/ltrim()/rtrim() (#7283, ext/standard/string.c)
--FILE--
<?php
var_export(enum_exists('StringTrimMode', false));
echo "\n";
var_export(StringTrimMode::Both->name);
echo "\n";
var_export(StringTrimMode::Left->value);
echo "\n";
var_export(StringTrimMode::Right->value);
echo "\n";
echo trim('  x  '), "\n";
echo trim('  x  ', StringTrimMode::Both), "\n";
echo ltrim('  x  ', StringTrimMode::Left), "\n";
echo rtrim('  x  ', StringTrimMode::Right), "\n";
echo ltrim('  x  ', StringTrimMode::Both), "\n";
echo trim('xxhelloxx', 'x'), "\n";
enum Es: string { case B = 'hi'; }
try {
    trim('  x  ', Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
'Both'
1
2
x
x
x  
  x
x
hello
trim(): Argument #2 ($characters) must be of type string, Es given
