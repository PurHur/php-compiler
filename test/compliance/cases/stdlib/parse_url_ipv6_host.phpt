--TEST--
stdlib parse_url() IPv6 bracketed host and port (issue #13739)
--FILE--
<?php
echo parse_url('http://[::1]/', PHP_URL_HOST), "\n";
echo parse_url('http://[::1]:8080/path', PHP_URL_HOST), "\n";
echo parse_url('http://[::1]:8080/path', PHP_URL_PORT), "\n";
echo parse_url('http://[2001:db8::1]/', PHP_URL_HOST), "\n";
echo parse_url('ftp://user:pass@[::1]:21/', PHP_URL_HOST), "\n";
echo parse_url('ftp://user:pass@[::1]:21/', PHP_URL_PORT), "\n";
--EXPECT--
[::1]
[::1]
8080
[2001:db8::1]
[::1]
21
