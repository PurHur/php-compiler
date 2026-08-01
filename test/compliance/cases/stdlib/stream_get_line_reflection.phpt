--TEST--
stdlib stream_get_line Reflection return string|false (#26357)
--FILE--
<?php
$r = new ReflectionFunction('stream_get_line');
echo 'ret=', (string) $r->getReturnType(), "\n";
$s = fopen('php://memory', 'r+');
fwrite($s, '');
rewind($s);
var_dump(stream_get_line($s, 10, "\n"));
fclose($s);
?>
--EXPECT--
ret=string|false
bool(false)
