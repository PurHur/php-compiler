--TEST--
Language: readonly parent property widened to non-readonly in child (#7367)
--FILE--
<?php
class P {
    public readonly string $x;
    public function __construct(string $x) { $this->x = $x; }
}
class C extends P {
    public string $x;
}
echo "ok\n";
--EXPECT_EXIT--
255
