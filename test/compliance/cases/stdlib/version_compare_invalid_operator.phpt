--TEST--
stdlib version_compare() — invalid $operator throws ValueError (#4319, ext/standard/versioning.c)
--FILE--
<?php
try {
    version_compare('1', '2', '??');
    echo "no_ex\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
echo version_compare('1.0.0', '1.0.1', '<') ? "lt\n" : "no\n";
--EXPECT--
version_compare(): Argument #3 ($operator) must be a valid comparison operator
lt
