--TEST--
Language: trait abstract private method with private impl compiles (#6895)
--FILE--
<?php
declare(strict_types=1);

trait T {
    abstract private function f(): void;
}

class C {
    use T;

    private function f(): void {}
}

echo "ok\n";
--EXPECT--
ok
