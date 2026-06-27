--TEST--
Stdlib: gethostbyaddr() PTR miss — no E_WARNING (#12573, ext/standard/dns.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
$ip = '10.0.0.1';
ob_start();
$result = gethostbyaddr($ip);
$warnings = ob_get_clean();
echo ('' === $warnings && $result === $ip) ? "ok\n" : "fail\n";
--EXPECT--
ok
