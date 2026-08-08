<?php
declare(strict_types=1);

// #29063 — FILTER_VALIDATE_IP rejects URI-style bracketed IPv6.
foreach (['[::1]', '[2001:db8::1]', '::1', '2001:db8::1'] as $ip) {
    echo $ip, '=>', var_export(filter_var($ip, FILTER_VALIDATE_IP), true), "\n";
}
echo 'flag_ipv6:', var_export(filter_var('[::1]', FILTER_VALIDATE_IP, FILTER_FLAG_IPV6), true), "\n";
