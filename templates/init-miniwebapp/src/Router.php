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
        $guestName = $_REQUEST['name'] ?? 'World';
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
