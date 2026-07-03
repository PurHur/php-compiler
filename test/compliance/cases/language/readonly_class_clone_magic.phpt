--TEST--
language: readonly class __clone may reinit readonly property once (issue #15365, zend_readonly.c)
--FILE--
<?php
readonly class R {
    public function __construct(public int $x) {}
    public function __clone(): void {
        $this->x = 5;
    }
}
$r = new R(1);
$c = clone $r;
echo $c->x, "\n";
--EXPECT--
5
