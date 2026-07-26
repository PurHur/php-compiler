--TEST--
language: readonly class __clone Error aborts clone — no resume after catch (#23527, zend_readonly.c)
--FILE--
<?php
readonly class C {
    public function __construct(public string $s) {}
    public function __clone() {
        echo "IN_CLONE\n";
        $this->s = strtoupper($this->s);
        echo "AFTER_ASSIGN\n";
    }
}
echo "START\n";
try {
    $a = new C("hi");
    echo "BEFORE_CLONE\n";
    $b = clone $a;
    echo "AFTER_CLONE:", $b->s, "\n";
} catch (Throwable $e) {
    echo "CATCH:", get_class($e), ":", $e->getMessage(), "\n";
}
echo "END\n";
--EXPECT--
START
BEFORE_CLONE
IN_CLONE
CATCH:Error:Cannot modify readonly property C::$s
END
