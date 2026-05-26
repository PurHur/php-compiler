--TEST--
User class static method call (issue #2209)
--FILE--
<?php
class Router {
    public static function fromConfig(array $config): Router {
        return new self($config);
    }

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(array $config) {
        $this->config = $config;
    }

    public function label(): string {
        return $this->config['app'] ?? 'default';
    }
}
$router = Router::fromConfig(['app' => 'ok']);
echo $router->label();
--EXPECT--
ok
