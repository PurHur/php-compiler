--TEST--
stream_get_line Reflection length + named stream/length/ending (#23921, file.stub.php)
--FILE--
<?php
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
?>
--EXPECT--
stream|NONE|opt=0
length|int|opt=0
ending|string|opt=1|def=''
named='a'
maxlen_rej
