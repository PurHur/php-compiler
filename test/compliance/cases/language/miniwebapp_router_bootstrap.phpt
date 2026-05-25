--TEST--
Language: MiniWebApp Router static factory, ::class, instanceof (#2185)
--FILE--
<?php
class Router
{
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        return new self($config);
    }

    public function appName(): string
    {
        return $this->config['app_name'] ?? 'MiniWebApp';
    }
}

$config = ['app_name' => 'TestApp'];
$router = new Router($config);
if (!$router instanceof Router) {
    echo "bootstrap-fail\n";
    exit(1);
}
echo $router->appName();
echo "\n";
echo Router::class;
echo "\n";
--EXPECT--
TestApp
Router
