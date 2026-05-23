<?php

declare(strict_types=1);

/**
 * Contact name guard for VM (issue #697): use == and substr, not strlen compares.
 *
 * @return bool true when name is non-empty and at most 200 bytes
 */
function miniwebapp_contact_name_is_valid(): bool
{
    if (!isset($_REQUEST['name'])) {
        return false;
    }
    $name = $_REQUEST['name'];
    if ($name == '') {
        return false;
    }
    if ($name != substr($name, 0, 200)) {
        return false;
    }

    return true;
}

/**
 * Front-controller dispatch for MiniWebApp (issue #210).
 */
class Router
{
    private const DEFAULT_CONTACT_NAME_MAX = 200;

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Front-controller entry for index.php (PATH_INFO primary, ?route= fallback #489).
     */
    public function handleRequest(string $method): void
    {
        $this->dispatch($method, self::resolveRouteFromEnvironment());
    }

    public static function resolveRouteFromEnvironment(): string
    {
        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        if (is_string($pathInfo) && '' !== $pathInfo) {
            if (0 === strpos($pathInfo, '/')) {
                $pathInfo = substr($pathInfo, 1);
            }
            if ('' !== $pathInfo) {
                return $pathInfo;
            }
        }

        $queryRoute = self::queryStringParam('route');
        if (null !== $queryRoute && '' !== $queryRoute) {
            return $queryRoute;
        }

        return 'home';
    }

    /**
     * Read a query-string key without preg_match($matches) (unsupported in AOT #764).
     */
    public static function queryStringParam(string $name): ?string
    {
        return self::urlEncodedParam((string) ($_SERVER['QUERY_STRING'] ?? ''), $name);
    }

    /**
     * Parse name=value from an application/x-www-form-urlencoded buffer (no preg_match in AOT).
     */
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
                        $this->rejectContactInput();

                        return;
                    }
                    $this->renderContactThankYou((string) $_REQUEST['name']);

                    return;
                }
                break;
            case 'GET':
            default:
                break;
        }

        switch ($route) {
            case 'home':
                $this->renderHome();
                break;
            case 'hello':
                $this->renderHello();
                break;
            case 'contact':
                $this->renderContactForm();
                break;
            case 'api/status':
                $this->renderApiStatus();
                break;
            default:
                http_response_code(404);
                echo '<h1>Not found</h1>';
        }
    }

    private function rejectContactInput(): void
    {
        http_response_code(400);
        echo "Invalid contact name\n";
    }

    private function renderHome(): void
    {
        $appName = $this->resolveAppName();
        $title = 'Home';
        include __DIR__ . '/../templates/layout.php';
    }

    private function renderHello(): void
    {
        $appName = $this->resolveAppName();
        $guestName = self::queryStringParam('name');
        if (null === $guestName || '' === $guestName) {
            $guestName = 'World';
        }
        $title = 'Hello';
        include __DIR__ . '/../templates/layout.php';
    }

    private function renderContactForm(): void
    {
        $appName = $this->resolveAppName();
        $title = 'Contact';
        include __DIR__ . '/../templates/layout.php';
    }

    private function renderContactThankYou($contactName): void
    {
        $name = $contactName;
        $appName = $this->resolveAppName();
        $title = 'Thank you';
        include __DIR__ . '/../templates/layout.php';
    }

    private function resolveAppName(): string
    {
        $cfg = $this->config;
        if (isset($cfg['app_name'])) {
            return $cfg['app_name'];
        }

        return 'MiniWebApp';
    }

    private function renderApiStatus(): void
    {
        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'service' => '003-MiniWebApp',
            'app' => $this->resolveAppName(),
        ]);
    }
}
