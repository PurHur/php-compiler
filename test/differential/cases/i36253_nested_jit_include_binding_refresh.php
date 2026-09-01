<?php
// Inline include + htmlspecialchars NestedJIT must not refresh outer bindings (#36253).
class RenderHarness
{
    public function render(): void
    {
        include __DIR__.'/_fixtures/i36253_layout.php';
        echo htmlspecialchars($title), "\n";
    }
}

(new RenderHarness())->render();
