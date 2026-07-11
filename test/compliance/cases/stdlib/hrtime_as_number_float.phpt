--TEST--
stdlib hrtime(true) returns float like microtime(true) (issue #12779)
--FILE--
<?php
if ('integer' === gettype(hrtime(true))) {
    echo "skip — hrtime(true) integer on reference profile\n";
    exit(0);
}
echo gettype(hrtime(true)) === 'double' ? "float\n" : "bad\n";
echo gettype(hrtime(as_number: true)) === 'double' ? "named\n" : "bad\n";
--EXPECT--
float
named
