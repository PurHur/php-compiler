--TEST--
Language: generator with never return type and yield — compile-time fatal (#7351)
--FILE--
<?php
function gen(): never {
    yield 1;
}
echo "compiled\n";
--EXPECT_EXIT--
255
