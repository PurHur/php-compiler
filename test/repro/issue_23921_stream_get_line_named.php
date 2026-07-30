<?php
// #23921 — stream_get_line Reflection length (not maxlen) + named stream/length/ending.
$r = new ReflectionFunction('stream_get_line');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), '|', $p->hasType() ? (string) $p->getType() : 'NONE', '|opt=', (int) $p->isOptional();
    if ($p->isDefaultValueAvailable()) {
        echo '|def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
$h = fopen('php://memory', 'r+');
fwrite($h, "a\nb");
rewind($h);
echo 'named=', var_export(stream_get_line(stream: $h, length: 10, ending: "\n"), true), "\n";
try {
    stream_get_line(stream: $h, maxlen: 10);
    echo "maxlen_ok\n";
} catch (Throwable $e) {
    echo "maxlen_rej\n";
}
fclose($h);
