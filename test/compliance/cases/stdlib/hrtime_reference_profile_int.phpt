--TEST--
stdlib hrtime(true) integer on Zend 8.2 reference profile (issue #12789)
--FILE--
<?php
if ('integer' !== gettype(hrtime(true))) {
    echo "skip — requires reference profile integer hrtime(true)\n";
    exit(0);
}
echo gettype(hrtime(true)) === 'integer' ? "int\n" : "bad\n";
echo gettype(hrtime(as_number: true)) === 'integer' ? "named\n" : "bad\n";
--EXPECT--
int
named
