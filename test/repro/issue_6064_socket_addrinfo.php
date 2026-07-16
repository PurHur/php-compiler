<?php

declare(strict_types=1);

/**
 * Repro for #6064 — socket_addrinfo_* + AddressInfo.
 */
var_dump(function_exists('socket_addrinfo_lookup'));
var_dump(class_exists('AddressInfo', false));
$infos = socket_addrinfo_lookup('127.0.0.1', '9');
var_dump(is_array($infos));
var_dump($infos[0] instanceof AddressInfo);
var_dump(socket_addrinfo_explain($infos[0])['ai_addr']['sin_addr'] ?? null);
