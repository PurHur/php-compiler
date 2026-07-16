--TEST--
stdlib socket_addrinfo_lookup/explain/connect/bind (#6064, ext/sockets/sockets.c)
--FILE--
<?php
declare(strict_types=1);

echo 'lookup_fn=', (int) function_exists('socket_addrinfo_lookup'), "\n";
echo 'class=', (int) class_exists('AddressInfo', false), "\n";

$infos = socket_addrinfo_lookup('127.0.0.1', '9', [
    'ai_family' => AF_INET,
    'ai_socktype' => SOCK_STREAM,
]);
echo 'is_array=', (int) is_array($infos), "\n";
echo 'count=', is_array($infos) ? count($infos) : 0, "\n";
echo 'instanceof=', (int) ($infos[0] instanceof AddressInfo), "\n";

$e = socket_addrinfo_explain($infos[0]);
echo 'family=', $e['ai_family'], "\n";
echo 'socktype=', $e['ai_socktype'], "\n";
echo 'protocol=', $e['ai_protocol'], "\n";
echo 'port=', $e['ai_addr']['sin_port'], "\n";
echo 'addr=', $e['ai_addr']['sin_addr'], "\n";

$sock = @socket_addrinfo_connect($infos[0]);
echo 'connect_false=', (int) (false === $sock), "\n";

$bindInfos = socket_addrinfo_lookup('127.0.0.1', '0', [
    'ai_family' => AF_INET,
    'ai_socktype' => SOCK_STREAM,
]);
$bound = socket_addrinfo_bind($bindInfos[0]);
echo 'bind_class=', $bound instanceof Socket ? 'Socket' : gettype($bound), "\n";
if ($bound instanceof Socket) {
    socket_close($bound);
}

echo 'empty=', var_export(socket_addrinfo_lookup('', '80'), true), "\n";
echo "done\n";
--EXPECT--
lookup_fn=1
class=1
is_array=1
count=1
instanceof=1
family=2
socktype=1
protocol=6
port=9
addr=127.0.0.1
connect_false=1
bind_class=Socket
empty=false
done
