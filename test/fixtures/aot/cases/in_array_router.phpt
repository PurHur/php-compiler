--TEST--
AOT: in_array() route guard (issue #83)
--ENV--
QUERY_STRING=route=home
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
--EXPECT--
ok:home
--EXPECT_EXIT--
0
