--TEST--
stdlib http_build_query() stdClass/object values and bool encoding (#4272, ext/standard/http.c)
--FILE--
<?php
$o = new stdClass();
$o->name = 'x';
$o->flag = true;
echo http_build_query(['user' => $o]), "\n";
echo http_build_query(['n' => 1, 'b' => false]), "\n";
--EXPECT--
user%5Bname%5D=x&user%5Bflag%5D=1
n=1&b=0
