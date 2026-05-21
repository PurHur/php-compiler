<?php

declare(strict_types=1);

/**
 * Front-controller dispatch for MiniWebApp (issue #210).
 *
 * Class methods are intentional lint blockers until #145 lands; routes use switch + includes.
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

        switch ($method) {
            case 'POST':
                if ('contact' === $route) {
                    $this->renderContactThankYou();

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

    private function renderHome(): void
    {
        $appName = $this->config['app_name'] ?? 'MiniWebApp';
        $title = 'Home';
        include __DIR__ . '/../templates/layout.php';
    }

    private function renderHello(): void
    {
        $name = $_GET['name'] ?? 'World';
        $title = 'Hello';
        include __DIR__ . '/../templates/layout.php';
    }

    private function renderContactForm(): void
    {
        $title = 'Contact';
        include __DIR__ . '/../templates/layout.php';
    }

    private function renderContactThankYou(): void
    {
        $title = 'Thank you';
        include __DIR__ . '/../templates/layout.php';
    }

    private function renderApiStatus(): void
    {
        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'service' => '003-MiniWebApp',
            'app' => $this->config['app_name'] ?? 'MiniWebApp',
        ]);
    }
}
