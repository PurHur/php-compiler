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
        $title = 'Home';
        include __DIR__ . '/layout.php';
    }
}

(new Renderer())->go();
