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

    public function handleRequest(string $method): void
    {
        $route = self::resolveRouteFromEnvironment();
        $this->dispatch($method, $route);
    }

    public static function resolveRouteFromEnvironment(): string
    {
        $queryRoute = self::queryStringParam('route');
        if (null !== $queryRoute && '' !== $queryRoute) {
            return $queryRoute;
        }

        return 'home';
    }

    public static function queryStringParam(string $name): ?string
    {
        return self::urlEncodedParam((string) ($_SERVER['QUERY_STRING'] ?? ''), $name);
    }

    private static function urlEncodedParam(string $buffer, string $name): ?string
    {
        if ('' === $buffer) {
            return null;
        }
        foreach (explode('&', $buffer) as $pair) {
            $eq = strpos($pair, '=');
            if (false === $eq) {
                continue;
            }
            if (substr($pair, 0, $eq) === $name) {
                return substr($pair, $eq + 1);
            }
        }

        return null;
    }

    public function dispatch(string $method, string $route): void
    {
        if ('api/status' !== $route) {
            header('Content-Type: text/html; charset=UTF-8');
        }

        switch ($method) {
            case 'POST':
                if ('contact' === $route) {
                    if (!miniwebapp_contact_name_is_valid()) {
                        echo "Invalid contact name\n";

                        return;
                    }
                    $this->renderContactThankYou((string) $_REQUEST['name']);

                    return;
                }
                break;
            default:
                break;
        }

        switch ($route) {
            case 'home':
                $this->renderHome();
                break;
            default:
                echo "skip\n";
        }
    }

    private function renderHome(): void
    {
        $appName = $this->resolveAppName();
        $title = 'Home';
        include __DIR__ . '/layout.php';
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
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
(new Router($config))->handleRequest($method);
