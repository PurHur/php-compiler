--TEST--
stdlib stream_isatty Reflection return bool (#27774, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('stream_isatty');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo '$', $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : '(none)', PHP_EOL;
}
$fh = fopen('php://memory', 'r');
echo 'mem=', var_export(stream_isatty(stream: $fh), true), PHP_EOL;
fclose($fh);
?>
--EXPECT--
return=bool
$stream type=(none)
mem=false
