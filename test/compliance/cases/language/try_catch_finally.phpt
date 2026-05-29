--TEST--
Language: try/catch/finally on throw (#3082)
--FILE--
<?php
class Ex {}
try {
    throw new Ex();
} catch (Ex $e) {
    echo "c";
} finally {
    echo "f";
}
--EXPECT--
fc
