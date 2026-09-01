<?php

declare(strict_types=1);

class R {
    private function resolveAppName(): string { return 'MiniWebApp'; }
    public function go(): void {
        $appName = $this->resolveAppName();
        include __DIR__ . '/issue-36253-resolve-hs-only-layout.php';
    }
}

(new R())->go();
