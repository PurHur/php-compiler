--TEST--
Stdlib: gethostbyaddr() reverse lookup failure returns unmodified IPv4 (#12560, ext/standard/dns.c)
--FILE--
<?php
$ip = '10.0.0.1';
$result = gethostbyaddr($ip);
echo is_string($result) && $result === $ip ? "unchanged\n" : "changed\n";
echo gethostbyaddr('not-an-ip') === false ? "invalid-false\n" : "invalid-ok\n";
--EXPECTREGEX--
PHP Warning:  Address 10\.0\.0\.1 is not in the reverse hosts table.*\nPHP Warning:  Address is not a valid IPv4 or IPv6 address.*\nunchanged\ninvalid-false
