<?php

declare(strict_types=1);

class ConfigHolder
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function appName(): string
    {
        $cfg = $this->config;
        if (isset($cfg['app_name'])) {
            return $cfg['app_name'];
        }

        return 'Fallback';
    }
}

echo (new ConfigHolder(['app_name' => 'MiniWebApp']))->appName(), "\n";
