--TEST--
language: function return type int (#55)
--FILE--
<?php
function count_items(): int {
    return 42;
}
echo count_items();
--EXPECT--
42
