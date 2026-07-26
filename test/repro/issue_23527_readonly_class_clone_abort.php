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
