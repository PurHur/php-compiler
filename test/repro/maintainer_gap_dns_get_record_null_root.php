<?php

declare(strict_types=1);

// VM: php bin/vm.php test/repro/maintainer_gap_dns_get_record_null_root.php
// null → TypeError (#23856); empty hostname DNS_ALL/DNS_A semantics stay.

try {
    dns_get_record(null);
    echo "fail: null coerced\n";
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'must be of type string, null given')) {
        echo 'fail: unexpected message: ', $e->getMessage(), "\n";
        exit(1);
    }
}

$empty = dns_get_record('');
if (!is_array($empty)) {
    echo "fail: expected array for empty hostname\n";
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
