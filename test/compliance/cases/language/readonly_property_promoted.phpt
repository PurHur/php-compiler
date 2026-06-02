--TEST--
readonly property: promoted ctor param write rejected after construction (issue #3149)
--FILE--
<?php
class C {
    public function __construct(public readonly string $id) {}
}
$c = new C('a');
$c->id = 'b';
--EXPECT_EXIT--
255
