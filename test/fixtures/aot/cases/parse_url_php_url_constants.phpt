--TEST--
AOT parse_url() PHP_URL_* component parity (#4458)
--FILE--
<?php
$url = 'https://user:pass@example.com:8443/a/b?x=1#frag';

echo parse_url($url, PHP_URL_SCHEME), "\n";
echo parse_url($url, PHP_URL_HOST), "\n";
echo parse_url($url, PHP_URL_PORT), "\n";
echo parse_url('https://example.com', PHP_URL_USER) ?? 'null', "\n";
--EXPECT--
https
example.com
8443
null
