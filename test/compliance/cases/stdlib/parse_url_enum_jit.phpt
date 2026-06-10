--TEST--
stdlib ParseUrl enum JIT — parse_url() component (#7260)
--JIT--
--FILE--
<?php
$url = 'http://user:pass@example.com:8080/path?q=1#frag';
echo parse_url($url, ParseUrl::Host), "\n";
echo parse_url($url, component: ParseUrl::Path), "\n";
echo parse_url($url, ParseUrl::Port), "\n";
--EXPECT--
example.com
/path
8080
