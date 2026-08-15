--TEST--
socket_addrinfo_* thin AOT NestedJIT (#31357)
--FILE--
<?php
// Prove call() lowers (no LogicException). Synthetic AF_INET covers numeric hosts
// when NestedJIT FFI getaddrinfo is unavailable (#31357).
$infos = socket_addrinfo_lookup('127.0.0.1', '9', [
    'ai_family' => AF_INET,
    'ai_socktype' => SOCK_STREAM,
]);
echo 'lookup=', is_array($infos) ? 'array' : 'no', "\n";
echo 'info=', (is_array($infos) && isset($infos[0]) && $infos[0] instanceof AddressInfo) ? 'AddressInfo' : 'no', "\n";
echo "addrinfo_linked_ok\n";
?>
--EXPECT--
lookup=array
info=AddressInfo
addrinfo_linked_ok
