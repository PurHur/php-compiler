--TEST--
stdlib mb_scrub() — invalid-byte scrubbing (ext/mbstring/mbstring.c, #6050)
--FILE--
<?php
echo function_exists('mb_scrub') ? 'yes' : 'no', "\n";
echo mb_scrub("\xFF", 'UTF-8'), "\n";
echo mb_scrub('café', 'UTF-8'), "\n";
echo mb_scrub("\xFF"), "\n";
echo bin2hex(mb_scrub("\xFF", '8BIT')), "\n";
echo bin2hex(mb_scrub("\xC0\x80", 'UTF-8')), "\n";
echo bin2hex(mb_scrub("\xE2\x28", 'UTF-8')), "\n";
--EXPECT--
yes
?
café
?
ff
3f3f
3f28
