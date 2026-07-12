--TEST--
AOT mb_trim() named characters: parameter (#9839, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo mb_trim('--héllo--', characters: '-'), "\n";
echo mb_ltrim('--héllo--', characters: '-'), "\n";
echo mb_rtrim('--héllo--', characters: '-'), "\n";
--EXPECT--
héllo
héllo--
--héllo
