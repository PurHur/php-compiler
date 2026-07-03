--TEST--
language: readonly class __clone rejects readonly property reinit (issue #15409, zend_readonly.c)
--FILE--
<?php
readonly class R {
    public function __construct(public int $x) {}
    public function __clone(): void {
        $this->x = 5;
    }
}
$r = new R(1);
clone $r;
--EXPECT_EXIT--
255
