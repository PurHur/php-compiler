--TEST--
never return type: return with value is compile-time fatal (issue #4206)
--FILE--
<?php
function f(): never {
    return 1;
}
f();
echo "after\n";
--EXPECT_EXIT--
255
