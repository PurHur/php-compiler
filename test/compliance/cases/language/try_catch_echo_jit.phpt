--TEST--
Language: try/catch runs catch block after throw (JIT, #2084, #1056)
--FILE--
<?php
class Ex {}
try {
    throw new Ex();
} catch (Ex $e) {
    echo "caught\n";
}
--EXPECT--
caught
