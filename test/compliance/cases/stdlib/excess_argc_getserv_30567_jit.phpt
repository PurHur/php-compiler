--TEST--
stdlib: getservbyname/getservbyport ArgumentCountError wording JIT (#30567)
--FILE--
<?php
foreach ([
    'getservbyname_hi' => static fn () => getservbyname('http', 'tcp', 1),
    'getservbyname_lo' => static fn () => getservbyname('http'),
    'getservbyport_hi' => static fn () => getservbyport(80, 'tcp', 1),
    'getservbyport_lo' => static fn () => getservbyport(80),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$port = getservbyname('http', 'tcp');
echo 'ok_getservbyname=', (is_int($port) || false === $port) ? '1' : '0', "\n";
$name = getservbyport(80, 'tcp');
echo 'ok_getservbyport=', (is_string($name) || false === $name) ? '1' : '0', "\n";
--EXPECT--
getservbyname_hi ArgumentCountError: getservbyname() expects exactly 2 arguments, 3 given
getservbyname_lo ArgumentCountError: getservbyname() expects exactly 2 arguments, 1 given
getservbyport_hi ArgumentCountError: getservbyport() expects exactly 2 arguments, 3 given
getservbyport_lo ArgumentCountError: getservbyport() expects exactly 2 arguments, 1 given
ok_getservbyname=1
ok_getservbyport=1
