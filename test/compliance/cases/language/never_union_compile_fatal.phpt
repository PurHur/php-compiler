--TEST--
Language: never in union return type — compile-time fatal (#4970)
--FILE--
<?php
function f(): string|never {
    throw new Exception('x');
}
echo "compiled\n";
--EXPECT_EXIT--
255
