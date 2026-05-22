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
        include __DIR__ . '/inc.php';
    }
}

(new Renderer())->go();
