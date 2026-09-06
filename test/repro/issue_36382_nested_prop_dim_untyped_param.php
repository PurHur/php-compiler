<?php
/**
 * #36382 — nested `$this->prop[$param][$k] = $v` must persist when $param is an
 * untyped method argument (TYPE_VALUE key). FastRoute DataGenerator::addStaticRoute:
 * `$this->staticRoutes[$httpMethod][$routeStr] = $handler`.
 *
 * Typed `string $m` already worked; untyped params took prepareValueBoxKeyWrite orphan
 * instead of a live child HT for nested FETCH_DIM_W.
 *
 * php-src: Zend/zend_execute.c ZEND_FETCH_DIM_W (write / nested dimension).
 */
class RouteBag
{
    public $staticRoutes = array();

    public function addUntyped($httpMethod, $routeStr, $handler)
    {
        $this->staticRoutes[$httpMethod][$routeStr] = $handler;
    }

    public function addTyped(string $httpMethod, string $routeStr, $handler)
    {
        $this->staticRoutes[$httpMethod][$routeStr] = $handler;
    }
}

$a = new RouteBag();
$a->addUntyped('GET', '/hello', 'hello_id');
echo isset($a->staticRoutes['GET']['/hello']) ? 'untyped=1' : 'untyped=0';
echo "\n";
if (isset($a->staticRoutes['GET']['/hello'])) {
    echo 'handler=', $a->staticRoutes['GET']['/hello'], "\n";
}

$b = new RouteBag();
$b->addTyped('POST', '/x', 'post_id');
echo isset($b->staticRoutes['POST']['/x']) ? 'typed=1' : 'typed=0';
echo "\n";
