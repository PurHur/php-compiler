<?php

declare(strict_types=1);

class Renderer
{
    public function renderThankYou(string $contactName): void
    {
        $name = $contactName;
        $appName = 'MiniWebApp';
        $title = 'Thank you';
        include __DIR__ . '/layout.php';
    }

    public function go(): void
    {
        $this->renderThankYou('PostDev');
    }
}

(new Renderer())->go();
