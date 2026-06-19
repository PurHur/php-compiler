--TEST--
stdlib mb_trim() named characters:/encoding: parameters (#9839, ext/mbstring/mbstring.c)
--FILE--
<?php
$s = '--héllo--';
echo mb_trim($s, '-'), "\n";
echo mb_trim($s, characters: '-'), "\n";
echo mb_ltrim($s, characters: '-'), "\n";
echo mb_rtrim($s, characters: '-'), "\n";
echo mb_trim($s, encoding: 'UTF-8'), "\n";
--EXPECT--
héllo
héllo
héllo--
--héllo
--héllo--
