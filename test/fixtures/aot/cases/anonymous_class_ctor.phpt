--TEST--
AOT: anonymous class with constructor promotion (issue #3098)
--FILE--
<?php
$o = new class(42) {
    public function __construct(private int $x) {}

    public function get(): int {
        return $this->x;
    }
};
echo $o->get(), "\n";
--EXPECT--
42
--EXPECT_EXIT--
0
