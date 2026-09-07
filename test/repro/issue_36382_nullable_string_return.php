<?php
/**
 * #36382 — `: ?string` return ABI must not return a stack alloca __value__*.
 *
 * php-src: Zend/zend_execute.c ZEND_RETURN / ZVAL_COPY
 */
function f(): ?string
{
    return 'route0';
}

class RoutingResultsMini
{
    protected int $routeStatus;
    protected ?string $routeIdentifier = null;

    public function __construct(int $routeStatus, ?string $routeIdentifier = null)
    {
        $this->routeStatus = $routeStatus;
        $this->routeIdentifier = $routeIdentifier;
    }

    public function getRouteStatus(): int
    {
        return $this->routeStatus;
    }

    public function getRouteIdentifier(): ?string
    {
        return $this->routeIdentifier;
    }
}

$results = [1, 'route0'];
$r = new RoutingResultsMini($results[0], $results[1]);

echo 'f=' . (f() ?? 'NULL') . "\n";
echo 'status=' . $r->getRouteStatus() . "\n";
echo 'id=' . ($r->getRouteIdentifier() ?? 'NULL') . "\n";
echo "OK\n";
