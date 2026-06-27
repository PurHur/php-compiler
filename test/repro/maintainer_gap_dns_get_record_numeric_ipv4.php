<?php

declare(strict_types=1);

$ips = ['127.0.0.1', '192.168.1.1', '10.0.0.1'];
foreach ($ips as $ip) {
    $records = dns_get_record($ip, DNS_A);
    if (!is_array($records) || [] !== $records) {
        echo "fail: dns_get_record({$ip}, DNS_A) expected [], got ";
        var_export($records);
        echo "\n";
        exit(1);
    }
}

echo "ok\n";
