<?php

declare(strict_types=1);

class Renderer
{
    private function render(): void
    {
        $title = 'Home';
        include __DIR__ . '/layout_nested.php';
    }

    public function go(): void
    {
        $this->render();
    }
}

(new Renderer())->go();
