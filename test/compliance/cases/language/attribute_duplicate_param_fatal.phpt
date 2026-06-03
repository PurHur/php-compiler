--TEST--
Language: duplicate parameter attribute compile-time fatal (#5239)
--FILE--
<?php
function f(
    #[\SensitiveParameter]
    #[\SensitiveParameter]
    $x
) {}
echo "ok\n";
--EXPECT_EXIT--
255
