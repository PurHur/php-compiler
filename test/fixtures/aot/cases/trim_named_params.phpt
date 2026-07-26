--TEST--
AOT: trim/ltrim/rtrim() named string:/characters: (#23224)
--FILE--
<?php
echo trim(string: ' x ', characters: ' '), "\n";
echo ltrim(string: ' x ', characters: ' '), "\n";
echo rtrim(string: ' x ', characters: ' '), "\n";
--EXPECT--
x
x 
 x
