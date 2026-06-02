--TEST--
Language: try/finally on throw via MCJIT (issue #4246)
--FILE--
<?php
class Ex {}
try {
    throw new Ex();
} catch (Ex $e) {
    echo "c\n";
} finally {
    echo "f\n";
}
echo "after\n";
--EXPECT--
c
f
after
