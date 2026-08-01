<?php
// #25750 — stream_get_contents Reflection string|false + ?int $length (php-src file.stub.php)
$r = new ReflectionFunction('stream_get_contents');
echo 'ret=', (string) $r->getReturnType(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' type=', $p->hasType() ? (string) $p->getType() : 'none', "\n";
}
$s = fopen('php://memory', 'r+');
fwrite($s, 'abcdef');
rewind($s);
echo 'null_length=', var_export(stream_get_contents($s, null, 1), true), "\n";
fclose($s);
