--TEST--
Language: readonly property with hooks — compile fatal (#9805, zend_compile.c)
--FILE--
<?php
class C {
    public readonly string $name {
        set (string $value) {
            $this->name = strtoupper($value);
        }
    }
    public function __construct(string $v) {
        $this->name = $v;
    }
}
echo "compiled\n";
--EXPECT_EXIT--
255
