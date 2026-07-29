<?php
/** Repro for #24392 — gzgets/gzgetc/gzeof/gzputs Reflection + Zend named stream. */
foreach (['gzgets', 'gzgetc', 'gzeof', 'gzputs'] as $f) {
    $r = new ReflectionFunction($f);
    $bits = [];
    foreach ($r->getParameters() as $p) {
        $bits[] = $p->getName() . ($p->isOptional() ? '=' : '');
    }
    echo $f, ': ', implode(',', $bits),
        ' arity=', $r->getNumberOfParameters(),
        ' req=', $r->getNumberOfRequiredParameters(), "\n";
}

$path = sys_get_temp_dir() . '/phpc-issue-24392-' . getmypid() . '.gz';
$w = gzopen($path, 'w9');
gzwrite($w, "line1\n");
gzclose($w);

$h = gzopen($path, 'r');
try {
    echo 'named=', var_export(gzgets(stream: $h, length: 20), true), "\n";
} catch (Throwable $e) {
    echo 'named=', str_starts_with($e->getMessage(), 'Unknown named parameter') ? 'rejected' : 'other', "\n";
}
try {
    gzgets(zp: $h, length: 20);
    echo "legacy_zp=accepted\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter') ? "legacy_zp=rejected\n" : "legacy_zp=other\n";
}
gzclose($h);

$w = gzopen($path, 'a');
try {
    echo 'gzputs_named=', var_export(gzputs(stream: $w, data: "line2\n"), true), "\n";
} catch (Throwable $e) {
    echo 'gzputs_named=', str_starts_with($e->getMessage(), 'Unknown named parameter') ? 'rejected' : 'other', "\n";
}
gzclose($w);
@unlink($path);
