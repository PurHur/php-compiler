--TEST--
Language: deferred throw uses new-site getLine() (issue #195)
--FILE--
<?php
$e = new Exception('x');
try {
    throw $e;
} catch (Exception $ex) {
    echo $ex->getLine(), "\n";
}
--EXPECT--
2
