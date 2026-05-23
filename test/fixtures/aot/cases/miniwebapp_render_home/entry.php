<?php

declare(strict_types=1);

class Router
{
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function go(): void
    {
        $this->renderHome();
    }

    private function renderHome(): void
    {
        $appName = $this->resolveAppName();
        $title = 'Home';
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
