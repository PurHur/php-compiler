--TEST--
stdlib getprotobynumber/getservbyport/getprotobyname/getservbyname — enum case TypeError (#5912, ext/standard/network.c)
--FILE--
<?php
declare(strict_types=1);

enum Ei: int
{
    case A = 6;
}

enum Es: string
{
    case B = 'tcp';
}

try {
    getprotobynumber(Ei::A);
    echo "proto uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    getservbyport(80, Es::B);
    echo "serv uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    getprotobyname(Es::B);
    echo "byname uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    getservbyname(Es::B, 'tcp');
    echo "servname uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
getprotobynumber(): Argument #1 ($protocol) must be of type int, Ei given
getservbyport(): Argument #2 ($protocol) must be of type string, Es given
getprotobyname(): Argument #1 ($protocol) must be of type string, Es given
getservbyname(): Argument #1 ($service) must be of type string, Es given
