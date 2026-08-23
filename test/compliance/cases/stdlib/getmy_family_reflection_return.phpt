--TEST--
stdlib getmyuid/getmygid/getmypid/getlastmod/getmyinode Reflection return int|false (#26317, #27727)
--FILE--
<?php
foreach (['getmyuid', 'getmygid', 'getmypid', 'getlastmod', 'getmyinode'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
}
?>
--EXPECT--
getmyuid ret=int|false
getmygid ret=int|false
getmypid ret=int|false
getlastmod ret=int|false
getmyinode ret=int|false
