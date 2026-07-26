--TEST--
language: readonly property __clone reinit denied on default profile (issue #23526, zend_readonly.c)
--FILE--
<?php
class C {
    public function __construct(public readonly string $s) {}
    public function __clone() {
        $this->s = strtoupper($this->s);
    }
}
try {
    $b = clone new C("hi");
    echo $b->s, "\n";
} catch (Throwable $e) {
    echo get_class($e), ":", $e->getMessage(), "\n";
}
--EXPECT--
Error:Cannot modify readonly property C::$s
