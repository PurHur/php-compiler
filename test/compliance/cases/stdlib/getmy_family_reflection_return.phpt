--TEST--
stdlib getmyuid/getmygid/getmypid/getlastmod Reflection return int|false (#26317)
--FILE--
<?php
foreach (['getmyuid', 'getmygid', 'getmypid', 'getlastmod'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
}
?>
--EXPECT--
getmyuid ret=int|false
getmygid ret=int|false
getmypid ret=int|false
getlastmod ret=int|false
