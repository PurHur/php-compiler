<?php
/**
 * Network/DNS builtins excess argc → ArgumentCountError (#30546).
 * php-src: ext/standard/basic_functions.c / network.c / dns.c
 */
foreach ([
    'inet_pton_hi' => static fn () => inet_pton('127.0.0.1', 1),
    'inet_pton_lo' => static fn () => inet_pton(),
    'inet_ntop_hi' => static fn () => inet_ntop(inet_pton('127.0.0.1'), 1),
    'ip2long_hi' => static fn () => ip2long('127.0.0.1', 1),
    'getprotobyname_hi' => static fn () => getprotobyname('tcp', 1),
    'getprotobynumber_hi' => static fn () => getprotobynumber(6, 1),
    'gethostbyaddr_hi' => static fn () => gethostbyaddr('127.0.0.1', 1),
    'checkdnsrr_hi' => static fn () => checkdnsrr('example.com', 'A', 1),
    'checkdnsrr_lo' => static fn () => checkdnsrr(),
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

$bin = inet_pton('127.0.0.1');
$text = inet_ntop($bin);
echo 'ok_inet:', ($text === '127.0.0.1') ? '1' : '0', "\n";
$n = ip2long('127.0.0.1');
echo 'ok_ip2long:', (is_int($n) && $n === 2130706433) ? '1' : '0', "\n";
// Arity-ok path must not raise ArgumentCountError (return value is host /etc/protocols-dependent).
try {
    $proto = getprotobyname('tcp');
    echo 'ok_proto_call:', (is_int($proto) || $proto === false) ? '1' : '0', "\n";
} catch (Throwable $e) {
    echo 'ok_proto_call:0:', get_class($e), "\n";
}
