<?php

$host = 'php.net';

try {
    $ok = dns_get_record($host, $t = DNS_A | DNS_AAAA) !== false;
    echo 'assign_in_arg=', $ok ? '1' : '0', "\n";
} catch (Throwable $e) {
    echo 'assign_in_arg=err:', $e->getMessage(), "\n";
    exit(1);
}

$ok2 = dns_get_record($host, DNS_A | DNS_AAAA) !== false;
echo 'inline=', $ok2 ? '1' : '0', "\n";

$t = DNS_A | DNS_AAAA;
$ok3 = dns_get_record($host, $t) !== false;
echo 'preassign=', $ok3 ? '1' : '0', "\n";

if (!$ok || !$ok2 || !$ok3) {
    exit(1);
}
