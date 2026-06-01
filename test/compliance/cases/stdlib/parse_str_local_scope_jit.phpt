--TEST--
stdlib parse_str() one-arg JIT/AOT local scope (issue #3708)
--FILE--
<?php
function t(): void {
    parse_str('x=9&y=hello');
    echo $x, ' ', $y, "\n";
}
t();
--EXPECT--
9 hello
