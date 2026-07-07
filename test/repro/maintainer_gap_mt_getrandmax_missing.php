<?php

declare(strict_types=1);

echo function_exists('mt_rand') ? "mt_rand=yes\n" : "mt_rand=no\n";
echo function_exists('mt_getrandmax') ? "mt_getrandmax=yes\n" : "mt_getrandmax=no\n";
echo mt_getrandmax(), "\n";

try {
    mt_getrandmax(1);
    echo "argcount=uncaught\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
