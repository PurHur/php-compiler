<?php

declare(strict_types=1);

$empty = dns_get_record('');
$nullCoerced = dns_get_record(null);

if (!is_array($empty) || !is_array($nullCoerced)) {
    echo "fail: expected arrays\n";
    exit(1);
}

if ($empty !== $nullCoerced) {
    echo "fail: null must match empty string\n";
    exit(1);
}

$all = dns_get_record('', DNS_ALL);
if (!is_array($all) || [] === $all) {
    echo "fail: DNS_ALL on empty hostname must return root delegation records\n";
    exit(1);
}

$firstType = $all[0]['type'] ?? '';
if ('NS' !== $firstType && 'SOA' !== $firstType) {
    echo 'fail: expected NS or SOA, got '.$firstType."\n";
    exit(1);
}

$aOnly = dns_get_record('', DNS_A);
if ([] !== $aOnly) {
    echo "fail: DNS_A on empty hostname must stay []\n";
    exit(1);
}

echo "ok\n";
