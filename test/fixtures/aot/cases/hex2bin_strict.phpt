--TEST--
AOT: hex2bin() rejects phantom $strict (issue #27763 / #4966)
--FILE--
<?php
echo bin2hex(hex2bin('4142')), "\n";
try {
    hex2bin('4142', true);
    echo "fail\n";
} catch (ArgumentCountError $e) {
    echo "arity\n";
}
--EXPECT--
4142
arity
