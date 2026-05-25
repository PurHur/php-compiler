--TEST--
Language: throw unwinds to outer catch (JIT, #2084, #1056)
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
