<?php
/** Issue #27739 — stream_copy_to_stream Reflection int|false + ?int length. */
$r = new ReflectionFunction('stream_copy_to_stream');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', PHP_EOL;
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'none', $p->isOptional() ? '?' : '', PHP_EOL;
}
$src = fopen('php://memory', 'r+');
$dst = fopen('php://memory', 'r+');
fwrite($src, 'abcd');
rewind($src);
$n = stream_copy_to_stream(from: $src, to: $dst, length: null);
echo 'copied=', $n, PHP_EOL;
