--TEST--
Language: void return with value — compile-time fatal (#4215)
--FILE--
<?php
function f(): void {
    return 1;
}
f();
echo "after\n";
--EXPECT_EXIT--
255
