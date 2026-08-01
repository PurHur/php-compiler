--TEST--
language eval() unclosed brace ParseError message matches Zend (issue #26691)
--FILE--
<?php
try {
    eval('class X { function foo() {');
    echo "ok\n";
} catch (ParseError $e) {
    echo $e->getMessage(), "\n";
}
echo "after\n";
--EXPECT--
Unclosed '{'
after
