--TEST--
Language: generator with void return type and yield — compile-time fatal (#11666)
--FILE--
<?php
function gen(): void {
    yield 1;
}
echo "compiled\n";
--EXPECT_EXIT--
255
