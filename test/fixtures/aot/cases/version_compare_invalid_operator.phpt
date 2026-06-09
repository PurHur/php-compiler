--TEST--
AOT version_compare() — invalid $operator throws ValueError (#4319)
--FILE--
<?php
try {
    version_compare('1', '2', '??');
    echo "no_ex\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
version_compare(): Argument #3 ($operator) must be a valid comparison operator
