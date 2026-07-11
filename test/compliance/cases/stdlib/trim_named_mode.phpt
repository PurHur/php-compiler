--TEST--
stdlib trim/ltrim/rtrim() PHP 8.4 named characters + mode (#13045, ext/standard/string.c)
--FILE--
<?php
$s = '  a  ';
echo trim($s, characters: ' ', mode: StringTrimMode::Both), "\n";
echo ltrim($s, characters: ' ', mode: StringTrimMode::Left), "\n";
echo rtrim($s, characters: ' ', mode: StringTrimMode::Right), "\n";
echo trim('  a  ', ' ', StringTrimMode::Both), "\n";
--EXPECT--
a
a  
  a
a
