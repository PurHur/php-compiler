<?php
/**
 * getservbyname/getservbyport excess argc → ArgumentCountError (#30567).
 * php-src: ext/standard/network.c
 */
foreach ([
    'getservbyname_hi' => static fn () => getservbyname('http', 'tcp', 1),
    'getservbyname_lo' => static fn () => getservbyname('http'),
    'getservbyport_hi' => static fn () => getservbyport(80, 'tcp', 1),
    'getservbyport_lo' => static fn () => getservbyport(80),
] as $name => $call) {
    try {
        $call();
        echo $name, ":OK\n";
    } catch (ArgumentCountError $e) {
        echo $name, ':ArgumentCountError:', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}

$port = getservbyname('http', 'tcp');
echo 'ok_getservbyname:', (is_int($port) || false === $port) ? '1' : '0', "\n";
$name = getservbyport(80, 'tcp');
echo 'ok_getservbyport:', (is_string($name) || false === $name) ? '1' : '0', "\n";
