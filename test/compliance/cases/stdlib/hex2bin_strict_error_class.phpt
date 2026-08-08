--TEST--
stdlib hex2bin() — second argument is ArgumentCountError not Error (#27763, re-#10072)
--FILE--
<?php
try {
    hex2bin('abc', true);
    echo "no throw\n";
} catch (ValueError $e) {
    echo "ValueError\n";
} catch (ArgumentCountError $e) {
    echo 'ArgumentCountError:', $e->getMessage(), "\n";
} catch (Error $e) {
    echo 'Error:', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError:hex2bin() expects exactly 1 argument, 2 given
