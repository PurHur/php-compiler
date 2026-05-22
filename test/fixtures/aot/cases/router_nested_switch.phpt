--TEST--
AOT: nested switch route dispatch (003-MiniWebApp index pattern, no classes)
--ENV--
REQUEST_METHOD=GET
--FILE--
<?php
header('Content-Type: text/html; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = 'home';

switch ($method) {
    case 'POST':
        if ('contact' === $route) {
            echo "thanks\n";
            return;
        }
        break;
    case 'GET':
    default:
        break;
}

switch ($route) {
    case 'home':
        echo "home:MiniWebApp\n";
        break;
    case 'hello':
        echo "hello\n";
        break;
    default:
        http_response_code(404);
        echo "not found\n";
}
--EXPECT--
Content-Type: text/html; charset=UTF-8
home:MiniWebApp
--EXPECT_EXIT--
0
