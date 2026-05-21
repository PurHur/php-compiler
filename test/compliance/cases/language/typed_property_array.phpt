--TEST--
Typed array property on class instance (issue #473)
--FILE--
<?php
class R {
    private array $c;
    public function __construct(array $c) {
        $this->c = $c;
    }
    public function g(): int {
        return count($this->c);
    }
}
echo (new R([1]))->g(), "\n";
--EXPECT--
1
