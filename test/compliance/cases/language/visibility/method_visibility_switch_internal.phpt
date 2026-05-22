--TEST--
private method callable via $this inside switch (MiniWebApp dispatch pattern)
--FILE--
<?php
class C {
    private function secret(): void {
        echo "ok";
    }

    public function go(string $route): void {
        switch ($route) {
            case 'x':
                $this->secret();
                break;
        }
    }
}

(new C())->go('x');
--EXPECT--
ok
