--TEST--
language: invokable objects via __invoke (JIT, issue #1232)
--FILE--
<?php
class Doubler {
    public function __invoke(int $x): int {
        return $x * 2;
    }
}
$o = new Doubler();
echo $o(21), "\n";
class Greeter {
    public function __invoke(): string {
        return 'hi';
    }
}
echo (new Greeter())(), "\n";
--EXPECT--
42
hi
