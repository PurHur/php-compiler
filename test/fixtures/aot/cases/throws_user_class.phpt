--TEST--
AOT: throw/catch user empty class (#2157)
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

