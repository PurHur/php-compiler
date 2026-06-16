--TEST--
stdlib preg_match_all() PREG_OFFSET_CAPTURE with subject offset (#4560, ext/pcre/php_pcre.c)
--FILE--
<?php
$s = 'a1b22';
preg_match_all('/(\d+)/', $s, $m, PREG_SET_ORDER);
echo count($m), ':', $m[0][0], ':', $m[1][0], "\n";
preg_match_all('/(\d+)/', $s, $m2, PREG_OFFSET_CAPTURE, 1);
echo count($m2), ':', $m2[0][0][0], ':', $m2[0][0][1], ':', $m2[1][0][0], ':', $m2[1][0][1], "\n";
--EXPECT--
2:1:22
2:1:1:1:1
