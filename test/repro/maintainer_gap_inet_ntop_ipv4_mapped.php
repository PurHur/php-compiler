<?php
/**
 * Issue #14324 — inet_ntop() must compress embedded IPv4 tails (RFC 4291 mapped + legacy compat).
 */
$cases = [
    \hex2bin('0000000000000000000000007f000001') => '::127.0.0.1',
    \hex2bin('000000000000000000000000ffffffff') => '::255.255.255.255',
];

$fail = 0;
foreach ($cases as $packed => $expect) {
    $got = inet_ntop($packed);
    if ($got !== $expect) {
        echo "packed tail: expected {$expect}, got ".var_export($got, true)."\n";
        ++$fail;
    }
}

$mapped = inet_ntop((string) inet_pton('::ffff:127.0.0.1'));
if ('::ffff:127.0.0.1' !== $mapped) {
    echo "round-trip mapped: expected ::ffff:127.0.0.1, got ".var_export($mapped, true)."\n";
    ++$fail;
}

// Zend rejects compat tail 0.1.0.0 for this packed form — both must agree.
$edge = \hex2bin('0000000000000000000000000000010000');
$edgeGot = inet_ntop($edge);
if (false !== $edgeGot) {
    echo 'edge 0.1.0.0: expected false, got '.var_export($edgeGot, true)."\n";
    ++$fail;
}

exit($fail > 0 ? 1 : 0);
