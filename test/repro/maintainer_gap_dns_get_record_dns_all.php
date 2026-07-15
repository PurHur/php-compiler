<?php

declare(strict_types=1);

$result = dns_get_record('', DNS_ALL);
if (!is_array($result) || [] === $result) {
    echo "fail: expected non-empty DNS_ALL records\n";
    exit(1);
}

$type = $result[0]['type'] ?? '';
if ('NS' !== $type && 'SOA' !== $type) {
    echo 'fail: expected NS or SOA, got '.$type."\n";
    exit(1);
}

echo "ok\n";
