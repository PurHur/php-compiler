--TEST--
language eval() parse error throws ParseError (VM, issue #4410)
--FILE--
<?php
try {
    eval('syntax error;');
    echo "no-exception\n";
} catch (ParseError $e) {
    echo "ParseError\n";
}
--EXPECT--
ParseError
