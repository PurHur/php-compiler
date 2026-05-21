--TEST--
AOT: in_array() route guard returns 404 for unknown route
--ENV--
QUERY_STRING=route=unknown
--FILE--
<?php
$routes = array('home', 'contact');
$route = $_GET['route'];
if (!in_array($route, $routes, true)) {
    http_response_code(404);
    echo "not found\n";
} else {
    echo 'ok:', $route, "\n";
}
--EXPECTF--
Status: 404 Not Found
not found
--EXPECT_EXIT--
0
