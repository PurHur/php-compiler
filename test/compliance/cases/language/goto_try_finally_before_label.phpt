--TEST--
goto from try runs finally before label (Zend order, issue #4491)
--FILE--
<?php
try {
    goto inside;
} finally {
    echo "finally\n";
}
inside:
echo "inside\n";
--EXPECT--
finally
inside
