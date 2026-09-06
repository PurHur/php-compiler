<?php
namespace FastRoute;
class RouteCollector {
    public $routes = [];
    public function addRoute($m, $p, $h) {
        $this->routes[$m][$p] = $h;
    }
}
