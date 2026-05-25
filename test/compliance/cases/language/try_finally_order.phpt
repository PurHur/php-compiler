--TEST--
Language: try/catch then fallthrough (#2084; finally after catch tracked in #57)
--FILE--
<?php
class Ex {}
try {
    throw new Ex();
} catch (Ex $e) {
    echo "catch\n";
}
echo "after\n";
--EXPECT--
catch
after
