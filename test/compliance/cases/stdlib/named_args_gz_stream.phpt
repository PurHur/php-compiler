--TEST--
gzread/gzwrite/gzclose/gzuncompress Reflection + named args (VM, issue #23655)
--FILE--
<?php
foreach (['gzread', 'gzwrite', 'gzclose', 'gzuncompress'] as $fn) {
    $bits = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $bits[] = $p->getName() . ($p->isOptional() ? '=' : '');
    }
    echo $fn, ':', implode(',', $bits), PHP_EOL;
}

$raw = gzcompress('hello-world');
echo var_export(gzuncompress(data: $raw, max_length: 100), true), PHP_EOL;

try {
    gzuncompress(data: $raw, max_decoded_len: 100);
    echo "legacy max_decoded_len accepted\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter') ? "legacy max_decoded_len rejected\n" : "legacy max_decoded_len other\n";
}

$path = sys_get_temp_dir() . '/phpc-named-gz-' . getmypid() . '.gz';
$w = gzopen($path, 'w9');
echo var_export(gzwrite(stream: $w, data: "line1\n"), true), PHP_EOL;
gzclose(stream: $w);

$h = gzopen($path, 'r');
echo var_export(gzread(stream: $h, length: 20), true), PHP_EOL;
try {
    gzread(zp: $h, length: 20);
    echo "legacy zp accepted\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter') ? "legacy zp rejected\n" : "legacy zp other\n";
}
gzclose($h);
@unlink($path);
--EXPECT--
gzread:stream,length
gzwrite:stream,data,length=
gzclose:stream
gzuncompress:data,max_length=
'hello-world'
legacy max_decoded_len rejected
6
'line1
'
legacy zp rejected
