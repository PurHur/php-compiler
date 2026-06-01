--TEST--
Language: throw Exception getLine() matches throw site (issue #195)
--FILE--
<?php
try {
    throw new Exception('boom');
} catch (Exception $e) {
    echo $e->getLine(), "\n";
    echo $e->getFile() !== '' ? "file_ok\n" : "file_bad\n";
}
--EXPECT--
3
file_ok
