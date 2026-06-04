--TEST--
Language: throw from finally chains pending try exception (#5486)
--FILE--
<?php
try {
    try {
        throw new Exception('inner');
    } finally {
        throw new Exception('finally');
    }
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
    $p = $e->getPrevious();
    echo $p ? $p->getMessage() : "null", "\n";
}
--EXPECT--
finally
inner
