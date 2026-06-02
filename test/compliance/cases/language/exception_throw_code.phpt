--TEST--
Language: throw Exception getCode() preserves constructor code (issue #195)
--FILE--
<?php
try {
    throw new Exception('boom', 42);
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
    echo $e->getCode(), "\n";
}
--EXPECT--
boom
42
