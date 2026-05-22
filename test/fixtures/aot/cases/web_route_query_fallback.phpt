--TEST--
AOT: $_GET route= from QUERY_STRING (MiniWebApp ?route= fallback, issue #489)
--ENV--
QUERY_STRING=route=home
--FILE--
<?php
$route = $_GET['route'];
echo 'route:', $route, "\n";
--EXPECT--
route:home
--EXPECT_EXIT--
0
