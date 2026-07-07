<?php
declare(strict_types=1);

$records = @dns_get_record('localhost', DNS_A);
if (!\is_array($records) || [] === $records) {
    echo "skip: no A records\n";
    exit(0);
}
$order = implode(',', array_keys($records[0]));
$expected = 'host,class,ttl,type,ip';
if ($order !== $expected) {
    echo "fail: key order {$order}\n";
    exit(1);
}
echo "ok\n";
