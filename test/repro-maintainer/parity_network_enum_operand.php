<?php

declare(strict_types=1);

/**
 * Maintainer repro: network service builtins enum operand TypeError (#5912).
 */

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
    echo "proto: uncaught\n";
} catch (Throwable $e) {
    echo 'proto: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    getservbyport(80, Es::B);
    echo "serv: uncaught\n";
} catch (Throwable $e) {
    echo 'serv: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    getprotobyname(Es::B);
    echo "byname: uncaught\n";
} catch (Throwable $e) {
    echo 'byname: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    getservbyname(Es::B, Es::B);
    echo "servname: uncaught\n";
} catch (Throwable $e) {
    echo 'servname: ', get_class($e), ': ', $e->getMessage(), "\n";
}
