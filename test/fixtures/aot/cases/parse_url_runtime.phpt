--TEST--
AOT parse_url() on runtime REQUEST_URI
--ENV--
REQUEST_URI=/api/status?verbose=1
--FILE--
<?php
$uri = $_SERVER['REQUEST_URI'] ?? '';
echo parse_url($uri, PHP_URL_PATH), "\n";
echo parse_url($uri, PHP_URL_QUERY), "\n";
--EXPECT--
/api/status
verbose=1
