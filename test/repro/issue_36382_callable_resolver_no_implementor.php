<?php
namespace Psr\Container { interface ContainerInterface { public function get(string $id); public function has(string $id): bool; } }
namespace Slim {
    use Psr\Container\ContainerInterface;
    final class CallableResolver {
        private ?ContainerInterface $container;
        public function __construct(?ContainerInterface $c = null) { $this->container = $c; }
        private function resolveSlimNotation(string $toResolve): array {
            $class = $toResolve;
            if ($this->container && $this->container->has($class)) {
                $instance = $this->container->get($class);
                return [$instance, '__invoke'];
            }
            return [new $class(), '__invoke'];
        }
        public function resolve($toResolve): callable {
            return $this->resolveSlimNotation((string)$toResolve);
        }
    }
}
namespace {
    class X { public function __invoke() { return 1; } }
    $r = new Slim\CallableResolver();
    $c = $r->resolve(X::class);
    echo is_callable($c) ? 'ok' : 'no';
}
