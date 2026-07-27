--TEST--
Language: AOT try/catch getMessage + AFTER; uncaught Zend fatal (#23641)
--FILE--
<?php
echo "BEFORE\n";
try {
    throw new LogicException("boom");
} catch (LogicException $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
echo "AFTER\n";
--EXPECT--
BEFORE
caught: boom
AFTER
