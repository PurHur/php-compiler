--TEST--
stdlib stream_copy_to_stream Reflection int|false + ?int length (#27739, streamsfuncs.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('stream_copy_to_stream');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'none', $p->isOptional() ? '?' : '', PHP_EOL;
}
$src = fopen('php://memory', 'r+');
$dst = fopen('php://memory', 'r+');
fwrite($src, 'abcd');
rewind($src);
echo 'copied=', stream_copy_to_stream($src, $dst, null), PHP_EOL;
?>
--EXPECT--
return=int|false
from:none
to:none
length:?int?
offset:int?
copied=4
