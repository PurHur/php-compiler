<?php

declare(strict_types=1);

class Renderer
{
    public function go(): void
    {
        $this->render();
    }

    private function render(): void
    {
        $appName = 'MiniWebApp';
        $title = 'Home';
        include __DIR__ . '/layout_simple.php';
    }
}

(new Renderer())->go();
