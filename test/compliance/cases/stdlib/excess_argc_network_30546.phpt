--TEST--
stdlib: network/DNS ArgumentCountError wording (#30546)
--FILE--
<?php
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
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$bin = inet_pton('127.0.0.1');
$text = inet_ntop($bin);
echo 'ok_inet=', ($text === '127.0.0.1') ? '1' : '0', "\n";
$n = ip2long('127.0.0.1');
echo 'ok_ip2long=', (is_int($n) && $n === 2130706433) ? '1' : '0', "\n";
try {
    $proto = getprotobyname('tcp');
    echo 'ok_proto_call=', (is_int($proto) || $proto === false) ? '1' : '0', "\n";
} catch (Throwable $e) {
    echo 'ok_proto_call=0:', get_class($e), "\n";
}
--EXPECT--
inet_pton_hi ArgumentCountError: inet_pton() expects exactly 1 argument, 2 given
inet_pton_lo ArgumentCountError: inet_pton() expects exactly 1 argument, 0 given
inet_ntop_hi ArgumentCountError: inet_ntop() expects exactly 1 argument, 2 given
ip2long_hi ArgumentCountError: ip2long() expects exactly 1 argument, 2 given
getprotobyname_hi ArgumentCountError: getprotobyname() expects exactly 1 argument, 2 given
getprotobynumber_hi ArgumentCountError: getprotobynumber() expects exactly 1 argument, 2 given
gethostbyaddr_hi ArgumentCountError: gethostbyaddr() expects exactly 1 argument, 2 given
checkdnsrr_hi ArgumentCountError: checkdnsrr() expects at most 2 arguments, 3 given
checkdnsrr_lo ArgumentCountError: checkdnsrr() expects at least 1 argument, 0 given
ok_inet=1
ok_ip2long=1
ok_proto_call=1
