--TEST--
AOT: promoted readonly constructor property write rejected (#3149)
--FILE--
<?php
class C {
    public function __construct(public readonly string $id) {}
}
$c = new C('a');
$c->id = 'b';
--EXPECT--
--EXPECT_EXIT--
255
