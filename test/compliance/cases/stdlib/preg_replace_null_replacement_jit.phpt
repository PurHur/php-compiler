--TEST--
stdlib preg_replace() null $replacement JIT delete-match (#17871, ext/pcre/php_pcre.c)
--JIT--
--FILE--
<?php
echo preg_replace('/a/', null, 'abc'), "\n";
$count = 0;
echo preg_replace('/a/', null, 'abc', -1, $count), ' count=', $count, "\n";
?>
--EXPECT--
bc
bc count=1
