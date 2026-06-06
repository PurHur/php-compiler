--TEST--
Language: eval() non-readonly class cannot extend readonly parent (#7170)
--FILE--
<?php
eval('readonly class ParentReadonly {} class ChildNormal extends ParentReadonly {}');
echo "allowed\n";
--EXPECT_EXIT--
255
