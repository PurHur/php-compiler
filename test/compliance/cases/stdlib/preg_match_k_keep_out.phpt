--TEST--
Stdlib: preg_match()/preg_replace() \\K keep-out assertion (#14089, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);

if (1 !== preg_match('/(a)\K(b)/', 'ab', $m)) {
    echo "match_fail\n";
    exit(0);
}
echo json_encode($m), "\n";
echo preg_replace('/(a)\K(b)/', 'X', 'ab'), "\n";
--EXPECT--
["b","a","b"]
aX
