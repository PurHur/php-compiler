--TEST--
AOT iconv_substr() encoding-aware substring (#27197)
--FILE--
<?php
echo iconv_substr('abcdef', 1, 3, 'UTF-8'), "\n";
$s = 'abcdef';
echo iconv_substr($s, 1, 3, 'UTF-8'), "\n";
?>
--EXPECT--
bcd
bcd
