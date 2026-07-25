<?php
$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
$ai = socket_addrinfo_lookup('127.0.0.1', '80', ['ai_socktype' => SOCK_STREAM]);
foreach (['Socket' => $sock, 'AddressInfo' => $ai[0]] as $n => $o) {
    try {
        serialize($o);
        echo $n, " serialize:no-throw\n";
    } catch (Throwable $e) {
        echo $n, ' ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
foreach (['Socket' => 'O:6:"Socket":0:{}', 'AddressInfo' => 'O:11:"AddressInfo":0:{}'] as $n => $payload) {
    try {
        unserialize($payload);
        echo $n, " unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $n, ' unserialize ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
