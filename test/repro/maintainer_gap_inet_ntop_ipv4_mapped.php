<?php

declare(strict_types=1);

/**
 * inet_ntop() IPv4-mapped/compatible tail compression (#14324).
 *
 * php-src: ext/standard/basic_functions.c — php_inet_ntop6()
 */

$cases = [
    '00000000000000000000000000010000' => '::0.1.0.0',
    '0000000000000000000000007f000001' => '::127.0.0.1',
    '000000000000000000000000ffffffff' => '::255.255.255.255',
];

foreach ($cases as $hex => $expect) {
    $packed = hex2bin($hex);
    if (false === $packed) {
        fwrite(STDERR, "bad hex: {$hex}\n");
        exit(1);
    }
    $got = inet_ntop($packed);
    if ($got !== $expect) {
        fwrite(STDERR, "packed {$hex}: got ".var_export($got, true)." expect {$expect}\n");
        exit(1);
    }
}

$roundTrip = inet_ntop(inet_pton('::ffff:127.0.0.1'));
if ('::ffff:127.0.0.1' !== $roundTrip) {
    fwrite(STDERR, 'round-trip: got '.var_export($roundTrip, true)."\n");
    exit(1);
}

echo "ok\n";
