--TEST--
Stdlib: pcre.jit / pcre.recursion_limit INI entries (#12433, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);
echo 'jit=' . ini_get('pcre.jit') . ' recursion=' . ini_get('pcre.recursion_limit') . "\n";
$all = ini_get_all();
echo isset($all['pcre.jit'], $all['pcre.recursion_limit']) ? "all=1\n" : "all=0\n";
?>
--EXPECT--
jit=1 recursion=100000
all=1
