<?php

declare(strict_types=1);

/**
 * Front-controller dispatch (miniwebapp init profile).
 */
class Router
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function dispatch(string $method, string $route): void
    {
        header('Content-Type: text/html; charset=UTF-8');

        switch ($route) {
            case 'home':
                $this->renderHome();
                break;
            default:
                http_response_code(404);
                echo '<h1>Not found</h1>';
        }
    }

    private function renderHome(): void
    {
        $appName = $this->resolveAppName();
        $title = 'Home';
        include __DIR__ . '/../templates/layout.php';
    }

    private function resolveAppName(): string
    {
        if (isset($this->config['app_name'])) {
            return $this->config['app_name'];
        }

        return 'MyWebApp';
    }
}
