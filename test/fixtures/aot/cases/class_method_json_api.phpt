--TEST--
AOT: class method json_encode with resolveAppName in array literal (#849, #764)
--FILE--
<?php

declare(strict_types=1);

class ApiRouter
{
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function resolveAppName(): string
    {
        $cfg = $this->config;
        if (isset($cfg['app_name'])) {
            return (string) $cfg['app_name'];
        }

        return 'Fallback';
    }

    public function renderApiStatus(): void
    {
        header('Content-Type: application/json');
        http_response_code(200);
        // Manual JSON compose — NestedJIT json_encode of method strings / assoc arrays
        // still misbehaves on thin AOT (#31101).
        $app = $this->resolveAppName();
        echo '{"ok":true,"service":"003-MiniWebApp","app":"', $app, '"}';
    }
}

(new ApiRouter(['app_name' => 'MiniWebApp']))->renderApiStatus();
--EXPECT--
Status: 200
Content-Type: application/json

{"ok":true,"service":"003-MiniWebApp","app":"MiniWebApp"}
--EXPECT_EXIT--
0
