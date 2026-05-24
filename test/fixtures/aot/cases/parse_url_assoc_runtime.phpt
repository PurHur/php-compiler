--TEST--
AOT parse_url() associative array on runtime REQUEST_URI
--ENV--
REQUEST_URI=/api/status?verbose=1
--FILE--
<?php
$uri = $_SERVER['REQUEST_URI'] ?? '';
$parts = parse_url($uri);
echo $parts['path'], "\n";
echo $parts['query'], "\n";
--EXPECT--
/api/status
verbose=1
