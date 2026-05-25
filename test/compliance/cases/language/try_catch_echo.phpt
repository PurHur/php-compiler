--TEST--
Language: try/catch runs catch block after throw (#2084, #57)
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
