--TEST--
stdlib preg_filter() count by-ref argument (#12904, ext/pcre/php_pcre.c)
--FILE--
<?php
$count = 0;
$out = preg_filter('/(\d+)/', '[$1]', ['a1', 'b2'], -1, $count);
echo 'count=', $count, "\n";
var_export($out);
?>
--EXPECT--
count=2
array (
  0 => 'a[1]',
  1 => 'b[2]',
)
