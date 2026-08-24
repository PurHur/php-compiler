--TEST--
stdlib getservbyname/port and getprotobyname/number Reflection return |false (#26318)
--FILE--
<?php
foreach (['getservbyname', 'getservbyport', 'getprotobyname', 'getprotobynumber'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
}
?>
--EXPECT--
getservbyname ret=int|false
getservbyport ret=string|false
getprotobyname ret=int|false
getprotobynumber ret=string|false
