--TEST--
stdlib hrtime(true) integer nanoseconds on 8.2 reference profile (issue #12789, #17468)
--FILE--
<?php
if ('double' === gettype(hrtime(true))) {
    echo "skip — hrtime(true) float on forward profile\n";
    exit(0);
}
echo gettype(hrtime(true)) === 'integer' ? "int\n" : "bad\n";
echo gettype(hrtime(as_number: true)) === 'integer' ? "named\n" : "bad\n";
--EXPECT--
int
named
