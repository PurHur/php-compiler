--TEST--
never return type: explicit return; is compile-time fatal (issue #4206)
--FILE--
<?php
function f(): never {
    return;
}
f();
echo "after\n";
--EXPECT_EXIT--
255
