--TEST--
AOT mb_trim() named characters: parameter (#9839, ext/mbstring/mbstring.c)
--FILE--
<?php
echo mb_trim('--héllo--', characters: '-'), "\n";
echo mb_ltrim('--héllo--', characters: '-'), "\n";
echo mb_rtrim('--héllo--', characters: '-'), "\n";
--EXPECT--
héllo
héllo--
--héllo
