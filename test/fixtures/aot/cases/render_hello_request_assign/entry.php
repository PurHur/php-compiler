<?php

declare(strict_types=1);

class Router
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function go(): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        $this->renderHello();
    }

    private function renderHello(): void
    {
        $appName = $this->resolveAppName();
        $guestName = $_REQUEST['name'] ?? 'World';
        $title = 'Hello';
        include __DIR__ . '/layout.php';
    }

    private function resolveAppName(): string
    {
        $cfg = $this->config;
        if (isset($cfg['app_name'])) {
            return $cfg['app_name'];
        }

        return 'MiniWebApp';
    }
}

(new Router(['app_name' => 'MiniWebApp']))->go();
