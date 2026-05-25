--TEST--
Language: throw unwinds to outer catch (#2084, #57)
--FILE--
<?php
class Ex {}
class Other {}
try {
    try {
        throw new Ex();
    } catch (Other $e) {
        echo "inner\n";
    }
} catch (Ex $e) {
    echo "caught\n";
}
--EXPECT--
caught
