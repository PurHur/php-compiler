<?php

declare(strict_types=1);

/**
 * AOT repro for #31357 — socket_addrinfo_lookup NestedJIT + AddressInfo.
 */
$infos = socket_addrinfo_lookup('127.0.0.1', '9', [
    'ai_family' => AF_INET,
    'ai_socktype' => SOCK_STREAM,
]);
echo 'lookup=', is_array($infos) ? 'array' : 'no', "\n";
echo 'info=', (is_array($infos) && isset($infos[0]) && $infos[0] instanceof AddressInfo) ? 'AddressInfo' : 'no', "\n";
echo "addrinfo_ok\n";
