--TEST--
Language: eval() readonly class cannot extend non-readonly parent (#7170)
--FILE--
<?php
class ParentNormal {}
eval('readonly class ChildReadonly extends ParentNormal { public function __construct(public int $x = 1) {} }');
echo "allowed\n";
--EXPECT_EXIT--
255
