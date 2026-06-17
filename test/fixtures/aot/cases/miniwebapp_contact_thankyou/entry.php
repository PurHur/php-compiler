<?php

declare(strict_types=1);

function miniwebapp_contact_name_is_valid(): bool
{
    if (!isset($_REQUEST['name'])) {
        return false;
    }
    $name = $_REQUEST['name'];
    if ($name == '') {
        return false;
    }
    if (isset($name[200])) {
        return false;
    }

    return true;
}

class Router
{
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function dispatch(string $method, string $route): void
    {
        if ('POST' !== $method || 'contact' !== $route) {
            return;
        }
        header('Content-Type: text/html; charset=UTF-8');
        if (!miniwebapp_contact_name_is_valid()) {
            echo "Invalid contact name\n";

            return;
        }
        $this->renderContactThankYou((string) $_REQUEST['name']);
    }

    private function renderContactThankYou($contactName): void
    {
        $name = $contactName;
        $appName = $this->resolveAppName();
        $title = 'Thank you';
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

$config = ['app_name' => 'MiniWebApp'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
(new Router($config))->dispatch($method, 'contact');
