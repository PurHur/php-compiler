<?php

class C
{
    private array $c;

    public function __construct(array $x)
    {
        $this->c = $x;
    }

    private function render(): void
    {
        $appName = $this->c['app_name'];
        include __DIR__ . '/layout.php';
    }

    public function go(): void
    {
        $this->render();
    }
}

(new C(['app_name' => 'MiniWebApp']))->go();
