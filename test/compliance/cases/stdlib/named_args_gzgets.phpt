--TEST--
gzgets/gzgetc/gzeof/gzputs Reflection + named args (VM, issue #24392)
--FILE--
<?php
foreach (['gzgets', 'gzgetc', 'gzeof', 'gzputs'] as $f) {
    $r = new ReflectionFunction($f);
    $bits = [];
    foreach ($r->getParameters() as $p) {
        $bits[] = $p->getName() . ($p->isOptional() ? '=' : '');
    }
    echo $f, ':', implode(',', $bits),
        ' arity=', $r->getNumberOfParameters(),
        ' req=', $r->getNumberOfRequiredParameters(), PHP_EOL;
}

$path = sys_get_temp_dir() . '/phpc-named-gzgets-' . getmypid() . '.gz';
$w = gzopen($path, 'w9');
gzwrite($w, "line1\n");
gzclose($w);

$h = gzopen($path, 'r');
echo var_export(gzgets(stream: $h, length: 20), true), PHP_EOL;
try {
    gzgets(zp: $h, length: 20);
    echo "legacy zp accepted\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter') ? "legacy zp rejected\n" : "legacy zp other\n";
}
gzclose($h);

$w = gzopen($path, 'a');
echo var_export(gzputs(stream: $w, data: "line2\n"), true), PHP_EOL;
gzclose($w);
@unlink($path);
--EXPECT--
gzgets:stream,length= arity=2 req=1
gzgetc:stream arity=1 req=1
gzeof:stream arity=1 req=1
gzputs:stream,data,length= arity=3 req=2
'line1
'
legacy zp rejected
6
