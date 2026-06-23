--TEST--
stdlib trim/ltrim/rtrim() named characters: parameter (#10027, ext/standard/string.c)
--FILE--
<?php
$s = '--hi--';
echo trim($s, characters: '-'), "\n";
echo ltrim($s, characters: '-'), "\n";
echo rtrim($s, characters: '-'), "\n";
--EXPECT--
hi
hi--
--hi
--DONE--
