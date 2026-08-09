--TEST--
stdlib stream_bucket_new Reflection return object on default profile (#28824)
--FILE--
<?php
$r = new ReflectionFunction('stream_bucket_new');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
$f = fopen('php://memory', 'r+');
$b = stream_bucket_new($f, 'x');
echo 'runtime=', gettype($b), PHP_EOL;
?>
--EXPECT--
ret=object
runtime=object
