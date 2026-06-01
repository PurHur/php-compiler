--TEST--
stdlib parse_str() one-arg inside function throws on JIT/AOT (#4034)
--FILE--
<?php
function t(): void {
    try {
        parse_str('x=9&y=hello');
        echo "no throw\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}
t();
--EXPECT--
parse_str() expects exactly 2 arguments, 1 given
