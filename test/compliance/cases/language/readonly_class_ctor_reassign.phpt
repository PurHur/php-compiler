--TEST--
readonly class: re-assignment inside __construct throws Error (#14838)
--FILE--
<?php
readonly class C {
    public int $x;
    public function __construct() {
        $this->x = 1;
        try {
            $this->x = 2;
            echo "no error\n";
        } catch (\Error $e) {
            echo $e->getMessage(), "\n";
        }
    }
}
new C;
--EXPECT--
Cannot modify readonly property C::$x
