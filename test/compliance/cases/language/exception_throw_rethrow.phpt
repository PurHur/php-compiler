--TEST--
Language: rethrow preserves original getLine() (issue #195)
--FILE--
<?php
try {
    throw new Exception('x');
} catch (Exception $e) {
    try {
        throw $e;
    } catch (Exception $e2) {
        echo $e2->getLine(), "\n";
    }
}
--EXPECT--
3
