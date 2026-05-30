--TEST--
Promoted readonly constructor parameters with defaults (issue #3816)
--FILE--
<?php
class C {
    public function __construct(private readonly int $x = 1) {}
    public function g(): int { return $this->x; }
    public function set(): void { $this->x = 2; }
}
echo (new C())->g(), "\n";
echo (new C(5))->g(), "\n";
try {
    (new C())->set();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
1
5
Error: Cannot modify readonly property C::$x
