--TEST--
AOT: eval() throw Exception::getLine() is 1-based in the eval string (#31948)
--FILE--
<?php
echo 'line_const=', eval('return __LINE__;'), "\n";
try {
    eval('throw new Exception("x");');
} catch (Exception $e) {
    echo 'throw_line=', $e->getLine(), "\n";
}
try {
    eval("\nthrow new Exception(\"x\");");
} catch (Exception $e) {
    echo 'throw_line_2=', $e->getLine(), "\n";
}
--EXPECT--
line_const=1
throw_line=1
throw_line_2=2
