--TEST--
AOT: PATH_INFO front controller passes superglobal-derived args to dispatch (#764)
--ENV--
REQUEST_METHOD=GET
PATH_INFO=/hello
SCRIPT_NAME=/index.php
--FILE--
<?php
class Router
{
    public function dispatch(string $method, string $route): void
    {
        echo $method, ':', $route, "\n";
    }
}

$route = 'home';
if (isset($_SERVER['PATH_INFO'])) {
    $pathInfo = $_SERVER['PATH_INFO'];
    if ('' !== $pathInfo) {
        if (0 === strpos($pathInfo, '/')) {
            $pathInfo = substr($pathInfo, 1);
        }
        if ('' !== $pathInfo) {
            $route = $pathInfo;
        }
    }
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
(new Router())->dispatch($method, (string) $route);
--EXPECT--
GET:hello
