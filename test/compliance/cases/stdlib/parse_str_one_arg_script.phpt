--TEST--
stdlib parse_str() one-arg at {main} throws ArgumentCountError (#4050)
--FILE--
<?php
try {
    parse_str('route=home&page=3');
    echo "no throw\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
parse_str() expects exactly 2 arguments, 1 given
