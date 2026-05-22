--TEST--
switch case can call private methods on $this (MiniWebApp Router dispatch)
--FILE--
<?php
class R {
    public function dispatch(string $route): void {
        switch ($route) {
            case 'home':
                $this->renderHome();
                break;
            default:
                echo "no\n";
        }
    }

    private function renderHome(): void {
        echo "home\n";
    }
}

(new R())->dispatch('home');
--EXPECT--
home
