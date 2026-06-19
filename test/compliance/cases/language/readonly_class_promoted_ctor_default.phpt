--TEST--
readonly class: constructor-promoted property with default constructs (#4758)
--FILE--
<?php
readonly class R {
    public function __construct(public int $x = 1) {}
}
echo (new R())->x, "\n";
echo (new R(5))->x, "\n";
--EXPECT--
1
5
