<?php
/**
 * #36382 — FastRoute-shaped IncludeHelper graph: callable formal invoke across units.
 * php-src: ZEND_INIT_DYNAMIC_CALL / zend_closures.c.
 */
namespace FastRoute;

class P {}
class G
{
    public function getData()
    {
        return [[], []];
    }
}
class RC
{
    public function __construct(public $p, public $g)
    {
    }

    public function addRoute($m, $r, $h)
    {
        echo "ADD:$m:$r:$h\n";
    }

    public function getData()
    {
        return $this->g->getData();
    }
}
class D
{
    public function __construct(public $data)
    {
    }

    public function dispatch($m, $u)
    {
        return [1, 'hello_id', []];
    }
}

if (!function_exists('FastRoute\\simpleDispatcher')) {
    function simpleDispatcher(callable $routeDefinitionCallback, array $options = [])
    {
        $routeCollector = new RC(new P(), new G());
        $routeDefinitionCallback($routeCollector);

        return new D($routeCollector->getData());
    }
}
