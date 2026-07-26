--TEST--
language: readonly property __clone may reinit once on PROFILE=8.3 (issue #23526, PHP 8.3+ #15365)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
class C {
    public function __construct(public readonly string $s) {}
    public function __clone() {
        $this->s = strtoupper($this->s);
    }
}
$b = clone new C("hi");
echo $b->s, "\n";
--EXPECT--
HI
