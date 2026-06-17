<?php

declare(strict_types=1);

class Holder
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return $this->config['app_name'];
    }
}

$config = require __DIR__ . '/config.php';
$holder = new Holder($config);
echo $holder->name(), "\n";
