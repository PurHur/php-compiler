--TEST--
Language: parenthesized invokable (new Class())($args) calls __invoke (#10176)
--FILE--
<?php
echo (new class {
    public function __invoke(int $x): int {
        return $x + 1;
    }
})(3), "\n";
--EXPECT--
4
