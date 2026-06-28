--TEST--
AOT: trim/ltrim/rtrim() named characters + mode (#13045)
--FILE--
<?php
$s = '  a  ';
echo trim($s, characters: ' ', mode: StringTrimMode::Both), "\n";
echo trim('  a  ', ' ', StringTrimMode::Both), "\n";
--EXPECT--
a
a
