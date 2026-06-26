<?php

declare(strict_types=1);

/**
 * Front-controller dispatch for MiniWebApp (issue #210).
 */
class Router
{
    public const DEFAULT_CONTACT_NAME_MAX = 200;

    /** @var array<string, mixed> */
    private array $config;

    /**
     * Contact name guard for VM (issue #697): empty check + isset offset at max length.
     */
    private static function contactNameIsValid(): bool
    {
        $name = $_REQUEST['name'] ?? '';
        if ($name == '') {
            return false;
        }
        if (isset($name[self::DEFAULT_CONTACT_NAME_MAX])) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Front-controller bootstrap (static call + ::class slice for AOT graph, #2185).
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        return new self($config);
    }

    public function dispatch(string $method, string $route): void
    {
        if ('api/status' !== $route) {
            header('Content-Type: text/html; charset=UTF-8');
        }

        switch ($method) {
            case 'POST':
                if ('contact' === $route) {
                    if (!self::contactNameIsValid()) {
                        $this->rejectContactInput();

                        return;
                    }
                    $contactName = $_REQUEST['name'] ?? '';
                    $this->renderContactThankYou($contactName);

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
        $name = (string) $contactName;
        include __DIR__ . '/../templates/thankyou.php';
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
