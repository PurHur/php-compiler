--TEST--
stdlib debug_zval_dump() stream resource — resource() line not object(Resource) (#18419, ext/standard/var.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
debug_zval_dump($h);
$h2 = $h;
debug_zval_dump($h);
fclose($h);
debug_zval_dump($h);
--EXPECTF--
resource(%d) of type (stream) refcount(%d)
resource(%d) of type (stream) refcount(%d)
resource(%d) of type (Unknown) refcount(%d)
