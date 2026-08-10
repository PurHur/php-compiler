--TEST--
AOT: user-function TypeError for non-coercible string to int return (#29858)
--FILE--
<?php
function f(): int {
    return "x";
}

function g(): int {
    return "42";
}

try {
    var_export(f());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

var_export(g());
echo "\n";
--EXPECT--
TypeError: f(): Return value must be of type int, string returned
42
