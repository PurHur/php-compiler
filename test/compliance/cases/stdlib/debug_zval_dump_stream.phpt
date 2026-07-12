--TEST--
stdlib debug_zval_dump() stream resource format (issue #18419)
--FILE--
<?php
$h = fopen('php://memory', 'w+');
debug_zval_dump($h);
fclose($h);
debug_zval_dump($h);
--EXPECTF--
resource(%d) of type (stream) refcount(%d)
resource(%d) of type (Unknown) refcount(%d)
