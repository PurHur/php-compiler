<?php
// Minimal stand-in for Slim RouteCollectorProxy::get → map → routeCollector->map (#36382)
class RouteCollectorMini {
    /** @var array */
    public $routes = [];
    public function map(array $methods, string $pattern, $callable) {
        $this->routes[] = [$methods, $pattern, $callable];
        echo "MAP:" . $methods[0] . ":" . $pattern . "\n";
        return $this;
    }
}
class AppMini {
    /** @var RouteCollectorMini */
    private $routeCollector;
    /** @var string */
    private $groupPattern = '';
    public function __construct() {
        $this->routeCollector = new RouteCollectorMini();
    }
    public function get(string $pattern, $callable) {
        return $this->map(['GET'], $pattern, $callable);
    }
    public function map(array $methods, string $pattern, $callable) {
        $pattern = $this->groupPattern . $pattern;
        return $this->routeCollector->map($methods, $pattern, $callable);
    }
}
$app = new AppMini();
$app->get('/hello', function ($request, $response, $args) {
    return 'hello';
});
echo "REGISTERED\n";
