<?php
declare(strict_types=1);
class R {
    private function resolveAppName(): string { return 'MiniWebApp'; }
    public function go(): void {
        $appName = $this->resolveAppName();
        include __DIR__ . '/layout.php';
    }
}
(new R())->go();
