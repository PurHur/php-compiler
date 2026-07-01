--TEST--
Language: never in union return type — compile-time fatal (#14334)
--FILE--
<?php
function f(): int|never {
    throw new Exception('done');
}
echo "compiled\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: never can only be used as a standalone type in %s on line %d
