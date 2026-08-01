--TEST--
Language: generator with scalar return type and yield — compile-time fatal (#26467)
--FILE--
<?php
function gen(): int {
    yield 1;
}
echo "compiled\n";
--EXPECT_EXIT--
255
--EXPECTF--
%aGenerator return type must be a supertype of Generator, int given%a
