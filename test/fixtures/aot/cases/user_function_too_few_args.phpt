--TEST--
AOT user function too-few-args throws ArgumentCountError (#29746, zend_execute.c)
--FILE--
<?php
function f($a) {
    return $a;
}
try {
    var_export(f());
    echo "\n";
} catch (Throwable $e) {
    echo preg_replace('/ in .* on line \d+/', ' in FILE on line N', $e->getMessage()), "\n";
}

function g($a, $b = 2) {
    return "$a-$b";
}
try {
    var_export(g(1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Too few arguments to function f(), 0 passed in FILE on line N and exactly 1 expected
string(3) "1-2"
