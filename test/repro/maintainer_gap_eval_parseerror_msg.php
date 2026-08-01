<?php
// #26691 — eval() unclosed class body: Zend ParseError message is Unclosed '{'
try {
    eval('class X { function foo() {');
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
echo "after\n";
