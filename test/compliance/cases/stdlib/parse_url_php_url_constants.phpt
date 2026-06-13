--TEST--
stdlib parse_url() PHP_URL_* component parity (#4458, ext/standard/url.c)
--FILE--
<?php
$url = 'https://user:pass@example.com:8443/a/b?x=1#frag';

echo parse_url($url, PHP_URL_SCHEME), "\n";
echo parse_url($url, PHP_URL_USER), "\n";
echo parse_url($url, PHP_URL_PASS), "\n";
echo parse_url($url, PHP_URL_HOST), "\n";
echo parse_url($url, PHP_URL_PORT), "\n";
echo parse_url($url, PHP_URL_PATH), "\n";
echo parse_url($url, PHP_URL_QUERY), "\n";
echo parse_url($url, PHP_URL_FRAGMENT), "\n";
echo parse_url('https://example.com', PHP_URL_USER) ?? 'null', "\n";
--EXPECT--
https
user
pass
example.com
8443
/a/b
x=1
frag
null
