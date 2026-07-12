--TEST--
stdlib preg_replace() null replacement deletes matches (#17871, ext/pcre/php_pcre.c)
--FILE--
<?php
echo preg_replace('/a/', null, 'abc'), "\n";
$count = 0;
echo preg_replace('/a/', null, 'abc', -1, $count), "\n";
echo $count, "\n";
?>
--EXPECT--
bc
bc
1
