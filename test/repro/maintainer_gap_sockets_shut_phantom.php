<?php
/**
 * Issue #26760 — SHUT_RD/SHUT_WR/SHUT_RDWR phantom on default (8.2) profile.
 *
 * Zend 8.2–8.4: undefined. PHP 8.5+ / PROFILE=8.5: defined (0/1/2).
 */
echo 'SOL_SOCKET=', (int) (defined('SOL_SOCKET') && SOL_SOCKET === 1), "\n";
echo 'SOCK_STREAM=', (int) (defined('SOCK_STREAM') && SOCK_STREAM === 1), "\n";
echo 'SHUT_RD=', (int) defined('SHUT_RD'), "\n";
echo 'SHUT_WR=', (int) defined('SHUT_WR'), "\n";
echo 'SHUT_RDWR=', (int) defined('SHUT_RDWR'), "\n";

$cats = get_defined_constants(true);
$sock = $cats['sockets'] ?? [];
foreach (['SHUT_RD', 'SHUT_WR', 'SHUT_RDWR'] as $name) {
    echo 'cat_'.$name.'=', (int) array_key_exists($name, $sock), "\n";
}
echo "ok\n";
