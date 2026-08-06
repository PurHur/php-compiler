<?php
/** Issue #27777 — stream_select Reflection ?array $read + int|false return. */
$r = new ReflectionFunction('stream_select');
foreach ($r->getParameters() as $p) {
    echo ($p->isPassedByReference() ? '&' : ''), $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : 'NONE', PHP_EOL;
}
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', PHP_EOL;
$read = [fopen('php://memory', 'r+')];
$write = null;
$except = null;
try {
    stream_select(read: $read, write: $write, except: $except, seconds: 0);
    echo 'named=ok', PHP_EOL;
} catch (ValueError $e) {
    // MEMORY streams are not select()able; named resolution still reached the builtin.
    echo 'named=ok', PHP_EOL;
}

