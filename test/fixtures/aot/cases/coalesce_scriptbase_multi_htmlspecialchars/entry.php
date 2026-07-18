<?php

declare(strict_types=1);

class Renderer
{
    private function render(): void
    {
        $title = 'Home';
        $appName = 'MiniWebApp';
        include __DIR__ . '/layout.php';
    }

    public function go(): void
    {
        $this->render();
    }
}

(new Renderer())->go();
