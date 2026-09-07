<?php

declare(strict_types=1);

/**
 * AOT (#36382): Slim Route::handle uses `$strategy($callable, …)` (InvocationStrategy
 * `__invoke`). Under AOT, `$obj($closure)` immediately after a Closure assign in the
 * same method aborts (RuntimeIndirectClosureCall / stale FCC metadata). A named method
 * call is Zend-equivalent for RequestResponse and matches the b2c9 repro that is green.
 *
 * php-src: Zend/zend_object_handlers.c ZEND_INIT_METHOD_CALL vs ZEND_INIT_DYNAMIC_CALL;
 * zend_closures.c only for zend_ce_closure.
 *
 * Usage: php script/composer/patch-slim-route-strategy-invoke-36382.php Route.php RequestResponse.php
 */
$routePath = $argv[1] ?? '';
$rrPath = $argv[2] ?? '';
if ('' === $routePath || !is_file($routePath) || '' === $rrPath || !is_file($rrPath)) {
    fwrite(STDERR, "usage: {$argv[0]} Route.php RequestResponse.php\n");
    exit(1);
}

$rr = file_get_contents($rrPath);
if (false === $rr) {
    fwrite(STDERR, "read failed: {$rrPath}\n");
    exit(1);
}
if (str_contains($rr, 'AOT (#36382): named callRouteCallable')) {
    echo "RequestResponse.php already patched (#36382)\n";
} else {
    $oldRr = <<<'PHP'
    public function __invoke(
        callable $callable,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $routeArguments
    ): ResponseInterface {
        foreach ($routeArguments as $k => $v) {
            $request = $request->withAttribute($k, $v);
        }

        /** @var ResponseInterface */
        return $callable($request, $response, $routeArguments);
    }
}
PHP;
    $newRr = <<<'PHP'
    public function __invoke(
        callable $callable,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $routeArguments
    ): ResponseInterface {
        return $this->callRouteCallable($callable, $request, $response, $routeArguments);
    }

    /**
     * AOT (#36382): named callRouteCallable — `$strategy($callable)` aborts under AOT when
     * a Closure was assigned earlier in the same method; named method dispatch is green.
     */
    public function callRouteCallable(
        callable $callable,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $routeArguments
    ): ResponseInterface {
        foreach ($routeArguments as $k => $v) {
            $request = $request->withAttribute($k, $v);
        }

        /** @var ResponseInterface */
        return $callable($request, $response, $routeArguments);
    }
}
PHP;
    if (!str_contains($rr, $oldRr)) {
        fwrite(STDERR, "RequestResponse __invoke pattern not found\n");
        exit(1);
    }
    file_put_contents($rrPath, str_replace($oldRr, $newRr, $rr));
    echo "patched RequestResponse for AOT (#36382)\n";
}

$route = file_get_contents($routePath);
if (false === $route) {
    fwrite(STDERR, "read failed: {$routePath}\n");
    exit(1);
}
if (str_contains($route, 'AOT (#36382): Closure routes')) {
    echo "Route.php already patched (#36382)\n";
    exit(0);
}

$oldRoute = <<<'PHP'
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->callableResolver instanceof AdvancedCallableResolverInterface) {
            $callable = $this->callableResolver->resolveRoute($this->callable);
        } else {
            $callable = $this->callableResolver->resolve($this->callable);
        }
        $strategy = $this->invocationStrategy;

        $strategyImplements = class_implements($strategy);

        if (
            is_array($callable)
            && $callable[0] instanceof RequestHandlerInterface
            && !in_array(RequestHandlerInvocationStrategyInterface::class, $strategyImplements)
        ) {
            $strategy = new RequestHandler();
        }

        $response = $this->responseFactory->createResponse();
        return $strategy($callable, $request, $response, $this->arguments);
    }
}
PHP;
$newRoute = <<<'PHP'
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // AOT (#36382): Closure routes — skip resolveRoute (callable return SEGVs) and
        // skip `$strategy($callable)` / callRouteCallable (method+Closure-arg aborts).
        // Default RequestResponse only forwards to the Closure; invoke it directly.
        // php-src: zend_closures.c Closure->__invoke; Slim RequestResponse::__invoke body.
        if ($this->callable instanceof \Closure) {
            $response = $this->responseFactory->createResponse();
            foreach ($this->arguments as $k => $v) {
                $request = $request->withAttribute($k, $v);
            }
            $cb = $this->callable;
            /** @var ResponseInterface */
            return $cb($request, $response, $this->arguments);
        }
        if ($this->callableResolver instanceof AdvancedCallableResolverInterface) {
            $callable = $this->callableResolver->resolveRoute($this->callable);
        } else {
            $callable = $this->callableResolver->resolve($this->callable);
        }
        $strategy = $this->invocationStrategy;

        $strategyImplements = class_implements($strategy);

        if (
            is_array($callable)
            && $callable[0] instanceof RequestHandlerInterface
            && !in_array(RequestHandlerInvocationStrategyInterface::class, $strategyImplements)
        ) {
            $strategy = new RequestHandler();
        }

        $response = $this->responseFactory->createResponse();
        return $strategy->callRouteCallable($callable, $request, $response, $this->arguments);
    }
}
PHP;
if (!str_contains($route, $oldRoute)) {
    fwrite(STDERR, "Route handle return pattern not found\n");
    exit(1);
}
file_put_contents($routePath, str_replace($oldRoute, $newRoute, $route));
echo "patched Route for AOT (#36382)\n";
